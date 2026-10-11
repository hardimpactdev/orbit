<?php

declare(strict_types=1);

namespace App\Infrastructure\TaskVms;

use App\Domain\TaskVms\TaskVmException;
use App\Domain\TaskVms\TaskVmHost;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\Node;
use Closure;
use Illuminate\Cache\Lock;
use Illuminate\Contracts\Cache\Lock as CacheLock;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Sleep;
use Throwable;

/**
 * The steps that the base image build and the base image sync share on one host: the host's image lock, the
 * smoke boot, the alias move, and the prune. Builder and smoke VMs are named `tvm-image-<time>…`, so a later
 * build or sync of the host deletes what an interrupted one left.
 */
final readonly class TaskVmBaseImage
{
    public const string WorkPrefix = 'tvm-image-';

    private const string FingerprintPattern = '/\A256 SHA256:[A-Za-z0-9+\/]{43} .* \(ED25519\)\n?\z/D';

    private const int BootSeconds = 600;

    private const int PollSeconds = 3;

    /** A lock that a killed process cannot release lasts as long as the refresh job may run. */
    private const int LockSeconds = 1500;

    public function __construct(
        private IncusHost $incus,
        private TaskVmCloudInit $cloudInit,
        private SshKeyProvider $keys,
    ) {}

    /**
     * Takes the host's image lock, which one build or sync of the host holds at a time. `$owner` names the lock,
     * so a caller that is killed can {@see abandon()} its own work.
     *
     * @throws TaskVmException `task_vm.image_build_running` while another build or sync of the host runs
     */
    public function lock(Node $node, ?string $owner): CacheLock
    {
        $lock = Cache::lock(self::lockName($node), self::LockSeconds, $owner);

        return $lock->get() ? $lock : throw new TaskVmException('task_vm.image_build_running', "A base image build or sync of Node [{$node->name}] is already running.");
    }

    /**
     * Cleans up after a build or sync whose process stopped before its own cleanup ran: deletes its builder and
     * smoke VMs and releases the lock. Does nothing unless `$owner` still holds the host's lock.
     */
    public function abandon(TaskVmHost $host, Node $node, string $owner): void
    {
        $lock = Cache::restoreLock(self::lockName($node), $owner);
        if (! $lock instanceof Lock || ! $lock->isOwnedByCurrentProcess()) {
            return;
        }
        try {
            $this->sweep($host, $node);
        } finally {
            $lock->release();
        }
    }

    /**
     * Runs one stage and reports its seconds. A failure becomes `$code` and names `$work` and the stage.
     *
     * @template T
     *
     * @param  (Closure(string, float): void)|null  $progress
     * @param  Closure(): T  $stage
     * @return T
     */
    public function stage(string $code, string $work, string $name, ?Closure $progress, Closure $stage): mixed
    {
        $started = microtime(true);
        try {
            $result = $stage();
        } catch (Throwable $exception) {
            throw new TaskVmException($code, "The {$work} failed at stage [{$name}]: ".mb_substr($exception->getMessage(), 0, 2000), 502, $exception);
        }
        if ($progress instanceof Closure) {
            $progress($name, round(microtime(true) - $started, 1));
        }

        return $result;
    }

    /** Deletes builder and smoke VMs that an interrupted build or sync left. */
    public function sweep(TaskVmHost $host, Node $node): void
    {
        foreach (array_keys($this->instances($host, $node)) as $name) {
            if (str_starts_with($name, self::WorkPrefix)) {
                $this->delete($host, $node, $name);
            }
        }
    }

    public function launch(TaskVmHost $host, Node $node, string $name, string $image, string $userData): void
    {
        $result = $this->incus->launch($host, $node, $name, $image, $userData);
        if (! $result->succeeded()) {
            throw IncusHost::failed("the base image VM [{$name}]", 'launch', $result);
        }
    }

    /** Waits until cloud-init is done without errors. */
    public function awaitCloudInit(TaskVmHost $host, Node $node, string $name): void
    {
        $deadline = now()->getTimestamp() + self::BootSeconds;
        while (true) {
            $result = $this->exec($host, $node, $name, ['cloud-init', 'status', '--format=json']);
            // Until the guest agent runs, `incus exec` fails and prints nothing on stdout.
            if ($result->succeeded() || trim($result->stdout) !== '') {
                $status = IncusHost::json("the base image VM [{$name}]", $result);
                $errors = $status['errors'] ?? null;
                if (! is_string($status['status'] ?? null) || ! is_array($errors) || ! array_is_list($errors)) {
                    throw IncusHost::invalid("the base image VM [{$name}]", 'the cloud-init status has no status or errors');
                }
                if ($status['status'] === 'error' || ($status['status'] === 'done' && $errors !== [])) {
                    throw new TaskVmException('task_vm.bootstrap_failed', "Cloud-init failed on [{$name}]: ".mb_substr(json_encode($errors, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), 0, 2000), 502);
                }
                if ($status['status'] === 'done') {
                    return;
                }
            }
            if (now()->getTimestamp() >= $deadline) {
                throw new TaskVmException('task_vm.bootstrap_timeout', "Cloud-init on [{$name}] was not done ".self::BootSeconds.' seconds after launch.', 504);
            }
            Sleep::for(self::PollSeconds)->seconds();
        }
    }

    /**
     * Boots a smoke VM `$name` from `$image` with the task VM user-data. It must have a host key, which proves
     * `openssh-server` and a clean first boot, the Gateway's key from its user-data, and one boot only. The boot
     * also creates the image volume on the host's pool, so the first task VM is a clone too.
     */
    public function smoke(TaskVmHost $host, Node $node, string $name, string $image): void
    {
        $this->launch($host, $node, $name, $image, $this->cloudInit->render($this->keys->publicKey()));
        $this->awaitCloudInit($host, $node, $name);
        $result = $this->checked($name, 'exec ssh-keygen', $this->exec($host, $node, $name, ['ssh-keygen', '-l', '-f', '/etc/ssh/ssh_host_ed25519_key.pub']));
        if (preg_match(self::FingerprintPattern, $result->stdout) !== 1) {
            throw IncusHost::invalid("the base image smoke VM [{$name}]", 'it has no ed25519 SSH host key');
        }
        // Cloud-init found the user-data without the seed: the Gateway's key is in place.
        if (! $this->exec($host, $node, $name, ['test', '-s', '/home/orbit/.ssh/authorized_keys'])->succeeded()) {
            throw IncusHost::invalid("the base image smoke VM [{$name}]", 'cloud-init did not read its user-data, so orbit has no authorized key');
        }
        $boots = $this->checked($name, 'exec journalctl', $this->exec($host, $node, $name, ['journalctl', '--list-boots', '--no-pager', '--quiet']));
        if (count(array_filter(explode("\n", trim($boots->stdout)), static fn (string $line): bool => trim($line) !== '')) !== 1) {
            throw IncusHost::invalid("the base image smoke VM [{$name}]", 'it rebooted during its first boot');
        }
        $this->delete($host, $node, $name);
    }

    /** Points `orbit-task-base` at `$fingerprint` in one request, or creates the alias on the first image. */
    public function promote(TaskVmHost $host, Node $node, string $fingerprint): void
    {
        $result = $this->incus->aliasTarget($host, $node, TaskVmHost::BaseImage) === null
            ? $this->incus->run($host, $node, ['image', 'alias', 'create', '--', TaskVmHost::BaseImage, $fingerprint])
            : $this->incus->query($host, $node, 'PATCH', '/1.0/images/aliases/'.TaskVmHost::BaseImage, json_encode(['target' => $fingerprint], JSON_THROW_ON_ERROR));
        if (! $result->succeeded()) {
            throw IncusHost::failed("the base image of Node [{$node->name}]", 'image alias', $result);
        }
    }

    /**
     * Deletes every dated base image other than `$keep` that no instance in the project uses.
     *
     * @return list<string> the deleted fingerprints
     */
    public function prune(TaskVmHost $host, Node $node, string $keep): array
    {
        $used = array_values($this->instances($host, $node));
        $deleted = [];
        foreach ($this->incus->images($host, $node) as $fingerprint => $aliases) {
            $dated = array_filter($aliases, static fn (string $alias): bool => str_starts_with($alias, TaskVmHost::BaseImage.'-'));
            if ($fingerprint === $keep || $dated === [] || in_array($fingerprint, $used, true)) {
                continue;
            }
            $result = $this->incus->run($host, $node, ['image', 'delete', '--', $fingerprint]);
            if (! $result->succeeded()) {
                throw IncusHost::failed("the base image [{$fingerprint}]", 'image delete', $result);
            }
            $deleted[] = $fingerprint;
        }

        return $deleted;
    }

    /** Deletes a VM; an absent VM counts as deleted. */
    public function delete(TaskVmHost $host, Node $node, string $name): void
    {
        $result = $this->incus->run($host, $node, ['delete', '--force', '--', $name]);
        if (! $result->succeeded() && array_key_exists($name, $this->instances($host, $node))) {
            throw IncusHost::failed("the base image VM [{$name}]", 'delete', $result);
        }
    }

    /**
     * After a failure: deletes its VMs, and the new image when it never became the base image. Best effort, so
     * the failure's own error stays the reported one; the next build or sync sweeps what remains.
     *
     * @param  list<string>  $names
     */
    public function discard(TaskVmHost $host, Node $node, array $names, ?string $image): void
    {
        foreach ($names as $name) {
            $this->incus->run($host, $node, ['delete', '--force', '--', $name]);
        }
        if ($image === null) {
            return;
        }
        // A promote whose reply was lost may have moved the alias already: the image it names stays, and so does
        // an image whose alias cannot be read.
        try {
            $base = $this->incus->aliasTarget($host, $node, TaskVmHost::BaseImage);
        } catch (TaskVmException) {
            return;
        }
        if ($base !== $image) {
            $this->incus->run($host, $node, ['image', 'delete', '--', $image]);
        }
    }

    /**
     * The instances of the host's project, as name => the fingerprint of the image each one launched.
     *
     * @return array<string, ?string>
     */
    public function instances(TaskVmHost $host, Node $node): array
    {
        $subject = "the instances of Node [{$node->name}]";
        $result = $this->incus->run($host, $node, ['list', '--format', 'json']);
        if (! $result->succeeded()) {
            throw IncusHost::failed($subject, 'list', $result);
        }
        $list = IncusHost::json($subject, $result);
        if (! array_is_list($list)) {
            throw IncusHost::invalid($subject, 'the instance list is not a JSON list');
        }
        $instances = [];
        foreach ($list as $instance) {
            $name = is_array($instance) ? $instance['name'] ?? null : null;
            $config = is_array($instance) ? $instance['config'] ?? [] : null;
            if (! is_string($name) || ! is_array($config)) {
                throw IncusHost::invalid($subject, 'an instance has no name or config');
            }
            $image = $config['volatile.base_image'] ?? null;
            $instances[$name] = is_string($image) ? $image : null;
        }

        return $instances;
    }

    /** @param  list<string>  $command */
    public function exec(TaskVmHost $host, Node $node, string $name, array $command, ?string $input = null): CommandResult
    {
        return $this->incus->run($host, $node, ['exec', '--', $name, ...$command], $input);
    }

    public function checked(string $name, string $operation, CommandResult $result): CommandResult
    {
        return $result->succeeded() ? $result : throw IncusHost::failed("the base image VM [{$name}]", $operation, $result);
    }

    private static function lockName(Node $node): string
    {
        return 'task-vms:image-build:'.$node->id;
    }
}
