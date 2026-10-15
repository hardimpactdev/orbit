<?php

declare(strict_types=1);

namespace App\Infrastructure\TaskVms;

use App\Domain\TaskVms\TaskVmException;
use App\Domain\TaskVms\TaskVmHost;
use App\Domain\TaskVms\TaskVmImageBuildState;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\Node;
use App\Models\TaskVmImageBuild;
use Closure;

/**
 * The Incus steps that the base image build and the base image sync share on one host: the smoke boot,
 * the alias move, and the prune. Builder and smoke VMs are named `tvm-image-<time>…`
 * ({@see TaskVmImageRecipe::WorkPrefix}), so a later build or sync of the host deletes what an interrupted one left.
 */
final readonly class IncusTaskVmBaseImage
{
    private const string FingerprintPattern = '/\A256 SHA256:[A-Za-z0-9+\/]{43} .* \(ED25519\)\n?\z/D';

    private const int BootSeconds = 600;

    public function __construct(
        private IncusHost $incus,
        private TaskVmCloudInit $cloudInit,
        private SshKeyProvider $keys,
        private TaskVmBaseImageCache $claimImages,
    ) {}

    /** Deletes builder and smoke VMs that an interrupted build or sync left. */
    public function sweep(TaskVmHost $host, Node $node, bool $discardFailed = false): void
    {
        foreach (array_keys($this->instances($host, $node)) as $name) {
            if (str_starts_with($name, TaskVmImageRecipe::WorkPrefix) && TaskVmHostAudit::claimBuildCleanup($node, $name, $discardFailed)) {
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

    /** The guest shell of the VM `$name` on the host. */
    public function shell(TaskVmHost $host, Node $node, string $name): IncusGuestShell
    {
        return new IncusGuestShell($this->incus, $host, $node, $name);
    }

    /** Waits until cloud-init is done without errors. */
    public function awaitCloudInit(TaskVmHost $host, Node $node, string $name): void
    {
        $shell = $this->shell($host, $node, $name);
        TaskVmGuest::awaitCloudInit("VM [{$name}]", self::BootSeconds, static fn (array $command, float $timeout): CommandResult => $shell->asRoot($command, timeout: $timeout));
    }

    /**
     * Boots a smoke VM `$name` from `$image` with the task VM user-data. It must have a host key, which proves
     * `openssh-server` and a clean first boot, the Gateway's key from its user-data, and one boot only. The boot
     * also creates the image volume on the host's pool, so the first task VM is a clone too. `$check` runs on the
     * booted smoke VM before it goes, for checks only a new build can pass.
     *
     * @param  (Closure(IncusGuestShell): void)|null  $check
     */
    public function smoke(TaskVmHost $host, Node $node, string $name, string $image, ?Closure $check = null): void
    {
        $this->launch($host, $node, $name, $image, $this->cloudInit->render($this->keys->publicKey()));
        $this->awaitCloudInit($host, $node, $name);
        $shell = $this->shell($host, $node, $name);
        $this->checkSmoke($shell);
        if ($check instanceof Closure) {
            $check($shell);
        }
        $this->delete($host, $node, $name);
    }

    public function checkSmoke(IncusGuestShell $shell): void
    {
        $name = $shell->vm;
        $result = $this->checked($name, 'exec ssh-keygen', $shell->asRoot(['ssh-keygen', '-l', '-f', '/etc/ssh/ssh_host_ed25519_key.pub']));
        if (preg_match(self::FingerprintPattern, $result->stdout) !== 1) {
            throw IncusHost::invalid("the base image smoke VM [{$name}]", 'it has no ed25519 SSH host key');
        }
        // Cloud-init found the user-data without the seed: the Gateway's key is in place.
        if (! $shell->asRoot(['test', '-s', TaskVmCloudInit::Home.'/.ssh/authorized_keys'])->succeeded()) {
            throw IncusHost::invalid("the base image smoke VM [{$name}]", 'cloud-init did not read its user-data, so '.TaskVmCloudInit::User.' has no authorized key');
        }
        $boots = $this->checked($name, 'exec journalctl', $shell->asRoot(['journalctl', '--list-boots', '--no-pager', '--quiet']));
        if (count(array_filter(explode("\n", trim($boots->stdout)), static fn (string $line): bool => trim($line) !== '')) !== 1) {
            throw IncusHost::invalid("the base image smoke VM [{$name}]", 'it rebooted during its first boot');
        }
    }

    /** Points `orbit-task-base` at the fingerprint, or creates the alias on the first image. */
    public function promote(TaskVmHost $host, Node $node, string $fingerprint): void
    {
        try {
            $result = $this->incus->aliasTarget($host, $node, TaskVmHost::BaseImage) === null
            ? $this->incus->run($host, $node, ['image', 'alias', 'create', '--', TaskVmHost::BaseImage, $fingerprint])
            : $this->incus->query($host, $node, 'PATCH', '/1.0/images/aliases/'.TaskVmHost::BaseImage, json_encode(['target' => $fingerprint], JSON_THROW_ON_ERROR));
            if (! $result->succeeded()) {
                throw IncusHost::failed("the base image of Node [{$node->name}]", 'image alias', $result);
            }
        } finally {
            // A claim reads the alias again, also when the reply to the move was lost.
            $this->claimImages->forget($node->id);
        }
    }

    /**
     * Deletes dated base images other than `$keep` that neither an instance nor a running build owns.
     *
     * @return list<string> the deleted fingerprints
     */
    public function prune(TaskVmHost $host, Node $node, string $keep): array
    {
        $used = array_values($this->instances($host, $node));
        $images = $this->incus->images($host, $node);
        // Read after the remote inventory. Every published image already has a running row, even
        // if publish's fingerprint write was interrupted or its builder has since been deleted.
        $running = TaskVmImageBuild::query()->where('host_node_id', $node->id)
            ->where(fn ($query) => $query->where('state', TaskVmImageBuildState::Running)
                ->orWhere(fn ($failed) => $failed->where('state', TaskVmImageBuildState::Failed)
                    ->whereNull('cleanup_started_at')->where('updated_at', '>', now()->subHours(TaskVmHostAudit::FailedBuildHours))))->get();
        $protected = $running->pluck('image_fingerprint')->all();
        $pendingAliases = $running->map(static fn (TaskVmImageBuild $build): string => TaskVmHost::BaseImage.'-'.$build->created_at?->format('YmdHis').'-'.$build->id)->all();
        $deleted = [];
        foreach ($images as $fingerprint => $aliases) {
            $dated = array_filter($aliases, static fn (string $alias): bool => str_starts_with($alias, TaskVmHost::BaseImage.'-'));
            if ($fingerprint === $keep || $dated === [] || in_array($fingerprint, $used, true)
                || in_array($fingerprint, $protected, true) || array_intersect($aliases, $pendingAliases) !== []) {
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

    public function checked(string $name, string $operation, CommandResult $result): CommandResult
    {
        return $result->succeeded() ? $result : throw IncusHost::failed("the base image VM [{$name}]", $operation, $result);
    }
}
