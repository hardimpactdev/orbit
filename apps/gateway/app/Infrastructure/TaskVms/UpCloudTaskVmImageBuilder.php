<?php

declare(strict_types=1);

namespace App\Infrastructure\TaskVms;

use App\Domain\TaskVms\TaskVmException;
use App\Domain\TaskVms\TaskVmSettings;
use App\Domain\TaskVms\TaskVmUpCloud;
use App\Infrastructure\Firewall\NodeFirewallRuleCatalog;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\ProtectedInput;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\Node;
use Closure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Sleep;
use Throwable;

/**
 * Builds the UpCloud task VM template with the same recipe as the Incus base image ({@see TaskVmImageRecipe}). A
 * builder server boots the stock Ubuntu template with the builder user-data, runs the enrollment programs, the agent
 * binary, the Pi executable, the masks and the manifest over SSH as `orbit`, turns ufw on, and `base-image-clean.sh`
 * removes its identity. A server from the template boots with ufw on and only public SSH open, the exact rule that
 * enrollment's base firewall opens and its role closes, so no baked service is public before enrollment. Its stopped disk becomes the template `orbit-task-base-<time>-unverified`. A smoke server boots it with
 * the task VM user-data; only then does the title lose `-unverified`, so task VMs clone it. The two newest verified
 * templates stay, older ones go: a cloned server does not need its template. A failed build deletes its servers and
 * its unverified template; the next build sweeps what a killed one left. A build marks its time stamp as running for
 * two hours, and no sweep touches the servers or template of a marked build.
 */
final readonly class UpCloudTaskVmImageBuilder
{
    private const string Lock = 'task-vms:upcloud-image-build';

    private const int LockSeconds = 3600;

    /** A build's running mark, followed by its time stamp. It outlasts the lock, so a build past its lock stays marked. */
    private const string Running = 'task-vms:upcloud-image-build:running:';

    private const int RunningSeconds = 2 * 3600;

    private const int StartSeconds = 300;

    private const int CloudInitSeconds = 1200;

    private const int KeepTemplates = 2;

    public function __construct(
        private UpCloudServers $servers,
        private UpCloudGuest $guest,
        private TaskVmCloudInit $cloudInit,
        private SshKeyProvider $keys,
        private TaskVmSettings $settings,
        private TaskVmImageRecipe $recipe,
        private TaskVmBaseImage $image,
    ) {}

    /**
     * Builds the template and returns its UUID. `$progress` hears each stage with its seconds.
     *
     * @param  (Closure(string, float): void)|null  $progress
     *
     * @throws TaskVmException `task_vm.image_build_running` while another UpCloud build runs, else `task_vm.image_build_failed`
     */
    public function build(?Closure $progress = null): string
    {
        $lock = Cache::lock(self::Lock, self::LockSeconds);
        if (! $lock->get()) {
            throw new TaskVmException('task_vm.image_build_running', 'An UpCloud task VM template build is already running.');
        }
        $stamp = now()->format('YmdHis');
        Cache::put(self::Running.$stamp, true, self::RunningSeconds);
        $builder = TaskVmBaseImage::WorkPrefix.$stamp;
        $smoke = $builder.'-smoke';
        $title = TaskVmUpCloud::TemplatePrefix.$stamp;
        $template = null;

        try {
            $programs = $this->stage($progress, 'render', fn (): array => $this->recipe->programs());
            $this->stage($progress, 'sweep', fn () => $this->sweep());
            [$address, $hostKey] = $this->stage($progress, 'launch', fn (): array => $this->launch($builder, $stamp, $this->upcloud()->sourceTemplate, $this->cloudInit->renderImageBuilder($this->keys->publicKey())));
            $run = function (array $arguments, ?string $input = null, ?ProtectedInput $protected = null) use ($builder, $address, $hostKey): CommandResult {
                /** @var list<string> $arguments */
                return $this->run($builder, $address, $hostKey, $arguments, $input, $protected);
            };
            $this->stage($progress, 'cloud-init', fn () => $this->awaitCloudInit($builder, $address, $hostKey));
            $this->stage($progress, 'orbit-user', fn () => $run(['sh', '-euc', TaskVmImageRecipe::OwnerCheck, 'sh', TaskVmCloudInit::Owner]));
            foreach ($programs as $name => $program) {
                $this->stage($progress, $name, fn () => $run($program->arguments, $program->input, $program->protectedInput));
            }
            $this->stage($progress, 'agent-binary', fn () => $run(['sudo', '-n', ...$this->recipe->installAgent($run(['uname', '-m'])->stdout, "the UpCloud builder [{$builder}]")]));
            if ($this->settings->piArtifactPath !== null && $this->settings->piArtifactSha256 !== null) {
                $this->stage($progress, 'pi-artifact', fn () => $run(['bash', '-ceu', TaskVmRuntime::InstallPi, '--', (string) $this->settings->piArtifactSha256], protected: ProtectedInput::fromFile((string) $this->settings->piArtifactPath, '', TaskVmRuntime::ArtifactMaxBytes)));
            }
            $this->stage($progress, 'masks', fn () => $run(['sudo', '-n', 'sh', '-c', TaskVmImageRecipe::Masks]));
            $this->stage($progress, 'firewall', fn () => $this->firewall($run));
            $this->stage($progress, 'manifest', fn () => $run(['sudo', '-n', 'install', '-D', '-o', 'root', '-g', 'root', '-m', '0644', '/dev/stdin', TaskVmBakedPrograms::ManifestPath], TaskVmBakedPrograms::manifest($programs)));
            $this->stage($progress, 'clean', fn () => $this->clean($builder, $run));
            $template = $this->stage($progress, 'templatize', fn (): string => $this->templatize($builder, $stamp, $title.TaskVmUpCloud::Unverified));
            $this->stage($progress, 'delete-builder', fn () => $this->servers->delete($builder, UpCloudServers::ImageLabel, $stamp, null));
            $this->stage($progress, 'smoke', fn () => $this->smoke($smoke, $stamp, $template));
            $this->stage($progress, 'promote', fn () => $this->servers->renameTemplate($template, $title));
            $published = $template;
            $template = null;
            $this->stage($progress, 'prune', fn () => $this->prune());

            return $published;
        } catch (Throwable $exception) {
            $this->discard([$builder, $smoke], $stamp, $template);

            throw $exception;
        } finally {
            Cache::forget(self::Running.$stamp);
            $lock->release();
        }
    }

    /** Whether a build is marked as running with the time stamp `$stamp`. */
    public static function running(string $stamp): bool
    {
        return Cache::has(self::Running.$stamp);
    }

    /**
     * Deletes the base image servers and unverified templates that an interrupted build left, except those of a build
     * that is marked as running. With `$olderThan`, only those whose time stamp is older.
     */
    public function sweep(?int $olderThan = null): void
    {
        $stale = static function (string $name) use ($olderThan): bool {
            if (preg_match('/([0-9]{14})/', $name, $match) !== 1) {
                return $olderThan === null;
            }
            if (self::running($match[1])) {
                return false;
            }
            if ($olderThan === null) {
                return true;
            }
            $stamp = Carbon::createFromFormat('YmdHis', $match[1]);

            return $stamp instanceof Carbon && $stamp->getTimestamp() < now()->getTimestamp() - $olderThan;
        };
        foreach ($this->servers->labelled(UpCloudServers::ImageLabel) as $server) {
            if ($stale($server['title'])) {
                $this->servers->delete($server['title'], UpCloudServers::ImageLabel, $server['value'], $server['uuid']);
            }
        }
        foreach ($this->servers->templates() as $uuid => $title) {
            if (str_ends_with($title, TaskVmUpCloud::Unverified) && $stale($title)) {
                $this->servers->deleteTemplate($uuid);
            }
        }
    }

    /**
     * Creates a server with a new pinned host key and waits until it runs.
     *
     * @return array{string, string} its address and host key line
     */
    private function launch(string $name, string $stamp, string $template, string $userData): array
    {
        $hostKey = $this->guest->generateHostKey($name);
        $uuid = $this->servers->create($name, UpCloudServers::ImageLabel, $stamp, $template, TaskVmCloudInit::withHostKey($userData, $hostKey), $this->keys->publicKey());
        $server = $this->servers->await($name, UpCloudServers::ImageLabel, $stamp, $uuid, 'started', self::StartSeconds);
        $address = $server->address ?? throw new TaskVmException('task_vm.upcloud_invalid', "UpCloud server [{$name}] has no public IPv4 address.", 502);

        return [$address, $hostKey['public']];
    }

    /** Waits over SSH until cloud-init is done without errors. Until sshd has the key from the user-data, SSH fails. */
    private function awaitCloudInit(string $name, string $address, string $hostKey): void
    {
        $deadline = now()->getTimestamp() + self::CloudInitSeconds;
        while (true) {
            $result = $this->guest->run($address, $hostKey, new RemoteCommand(['timeout', '60', 'cloud-init', 'status', '--wait', '--format=json'], maxOutputBytes: 65536, timeout: 90));
            $status = json_decode($result->stdout, true);
            if (is_array($status) && is_string($status['status'] ?? null)) {
                $errors = $status['errors'] ?? [];
                if ($status['status'] === 'error' || ($status['status'] === 'done' && $errors !== [])) {
                    throw new TaskVmException('task_vm.bootstrap_failed', "Cloud-init failed on [{$name}]: ".mb_substr(json_encode($errors, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), 0, 2000), 502);
                }
                if ($status['status'] === 'done') {
                    return;
                }
            }
            if (now()->getTimestamp() >= $deadline) {
                throw new TaskVmException('task_vm.bootstrap_timeout', "Cloud-init on [{$name}] was not done ".self::CloudInitSeconds.' seconds after launch.', 504);
            }
            Sleep::for(5)->seconds();
        }
    }

    /**
     * Turns ufw on with incoming traffic denied, except the public SSH rule of a new Node on port 22 that enrollment
     * opens itself. ufw keeps the SSH connection of the build, which that rule allows anyway.
     *
     * @param  Closure(list<string>, ?string=, ?ProtectedInput=): CommandResult  $run
     */
    private function firewall(Closure $run): void
    {
        $run(['sudo', '-n', 'ufw', 'default', 'deny', 'incoming']);
        $run((new NodeFirewallRuleCatalog)->publicSshRecovery(new Node(['public_ssh_port' => 22]))->arguments);
        $run(['sudo', '-n', 'ufw', '--force', 'enable']);
        if (preg_match('/^Status: active$/m', $run(['sudo', '-n', 'ufw', 'status'])->stdout) !== 1) {
            throw new TaskVmException('task_vm.image_build_failed', 'ufw is not active after it was enabled.', 502);
        }
    }

    /** @param  Closure(list<string>, ?string=, ?ProtectedInput=): CommandResult  $run */
    private function clean(string $name, Closure $run): void
    {
        $result = $run(['sudo', '-n', 'bash', '-s'], $this->recipe->cleanScript());
        if (trim($result->stdout) !== TaskVmImageRecipe::CleanConfirmation) {
            throw new TaskVmException('task_vm.image_build_failed', "base-image-clean.sh did not confirm on [{$name}].", 502);
        }
    }

    /** Stops the builder cleanly and turns its disk into a template. */
    private function templatize(string $name, string $stamp, string $title): string
    {
        $server = $this->servers->find($name, UpCloudServers::ImageLabel, $stamp) ?? throw new TaskVmException('task_vm.vm_missing', "UpCloud server [{$name}] is gone.", 502);
        $this->servers->stop($server->uuid, 120);
        $server = $this->servers->await($name, UpCloudServers::ImageLabel, $stamp, $server->uuid, 'stopped', 180);

        return $this->servers->templatize($server->disk, $title);
    }

    /**
     * Boots a server from the new template with the task VM user-data. Cloud-init must finish, `orbit` must have the
     * Gateway's key and the pinned uid and gid, the image manifest must be there, and ufw must be on.
     */
    private function smoke(string $name, string $stamp, string $template): void
    {
        [$address, $hostKey] = $this->launch($name, $stamp, $template, $this->cloudInit->render($this->keys->publicKey()));
        $this->awaitCloudInit($name, $address, $hostKey);
        $this->checked($name, $this->guest->run($address, $hostKey, new RemoteCommand(
            ['sh', '-euc', TaskVmImageRecipe::OwnerCheck.' && test -s "$2" && test -s /home/orbit/.ssh/authorized_keys && sudo -n ufw status | grep -qx "Status: active"', 'sh', TaskVmCloudInit::Owner, TaskVmBakedPrograms::ManifestPath],
            timeout: 60,
        )));
        $this->servers->delete($name, UpCloudServers::ImageLabel, $stamp, null);
    }

    /** Keeps the newest verified templates, and deletes the older ones. */
    private function prune(): void
    {
        $verified = array_filter($this->servers->templates(), static fn (string $title): bool => ! str_ends_with($title, TaskVmUpCloud::Unverified));
        arsort($verified, SORT_STRING);
        foreach (array_slice(array_keys($verified), self::KeepTemplates) as $uuid) {
            $this->servers->deleteTemplate($uuid);
        }
    }

    /**
     * After a failure: deletes the build's servers and its unverified template. Best effort, so the failure's own error
     * stays the reported one; the next build sweeps what remains.
     *
     * @param  list<string>  $names
     */
    private function discard(array $names, string $stamp, ?string $template): void
    {
        foreach ($names as $name) {
            try {
                $this->servers->delete($name, UpCloudServers::ImageLabel, $stamp, null);
            } catch (Throwable) {
            }
        }
        if ($template !== null) {
            try {
                $this->servers->deleteTemplate($template);
            } catch (Throwable) {
            }
        }
    }

    /**
     * @template T
     *
     * @param  (Closure(string, float): void)|null  $progress
     * @param  Closure(): T  $work
     * @return T
     */
    private function stage(?Closure $progress, string $name, Closure $work): mixed
    {
        return $this->image->stage('task_vm.image_build_failed', 'UpCloud template build', $name, $progress, $work);
    }

    /**
     * Runs a command as `orbit` on the build server, and fails on a non-zero exit.
     *
     * @param  list<string>  $arguments
     */
    private function run(string $name, string $address, string $hostKey, array $arguments, ?string $input = null, ?ProtectedInput $protected = null): CommandResult
    {
        return $this->checked($name, $this->guest->run($address, $hostKey, new RemoteCommand($arguments, $input, $protected, timeout: 900.0)));
    }

    private function checked(string $name, CommandResult $result): CommandResult
    {
        if (! $result->succeeded()) {
            $detail = mb_substr(implode("\n", array_slice(explode("\n", trim($result->stderr)), -5)), 0, 2000);

            throw new TaskVmException('task_vm.image_build_failed', "A command on [{$name}] failed with exit code [{$result->exitCode}].".($detail === '' ? '' : "\n{$detail}"), 502);
        }

        return $result;
    }

    private function upcloud(): TaskVmUpCloud
    {
        return $this->servers->upcloud();
    }
}
