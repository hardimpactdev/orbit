<?php

declare(strict_types=1);

namespace App\Infrastructure\TaskVms;

use App\Domain\TaskVms\TaskVmError;
use App\Domain\TaskVms\TaskVmException;
use App\Domain\TaskVms\TaskVmProviderKind;
use App\Domain\TaskVms\TaskVmUpCloud;
use App\Infrastructure\Firewall\NodeFirewallRuleCatalog;
use App\Infrastructure\MachineSoftware\MachineRecipe;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\Node;
use App\Models\TaskVm;
use App\Models\UpCloudTaskVmImageBuild;
use Closure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Builds the UpCloud template with the same base-profile installer as Incus ({@see RecipeImagePrograms}). A
 * builder server boots stock Ubuntu without package upgrades, installs and verifies the deployed recipe over SSH,
 * records its identity in the guest and the template's orbit.recipe label, turns ufw on, and `image.clean`
 * removes its identity. A server from the template boots with ufw on and only public SSH open, the exact rule that
 * enrollment's base firewall opens and its role closes, so no baked service is public before enrollment. Its stopped disk becomes the template `orbit-task-base-<time>-unverified`. A smoke server boots it with
 * the task VM user-data; only then does the title lose `-unverified`, so task VMs clone it. The two newest verified
 * templates and any template pinned by a live VM awaiting server creation stay; older unreferenced ones go. A failed build deletes its servers and
 * its unverified template; the next build sweeps what a killed one left. A build marks its time stamp as running for
 * two hours, and no sweep touches the servers or template of a marked build.
 */
final readonly class UpCloudTaskVmImageBuilder
{
    private const string Lock = 'task-vms:upcloud-image-build';

    private const int LockSeconds = 3600;

    /** A build's running mark, followed by its time stamp. It outlasts the lock, so a build past its lock stays marked. */
    private const string Running = 'task-vms:upcloud-image-build:running:';

    private const int RunningSeconds = UpCloudTaskVmImageBuild::RunningSeconds;

    private const int StartSeconds = 300;

    private const int CloudInitSeconds = 1200;

    private const int KeepTemplates = 2;

    public function __construct(
        private UpCloudServers $servers,
        private UpCloudGuest $guest,
        private TaskVmCloudInit $cloudInit,
        private SshKeyProvider $keys,
        private TaskVmImageRecipe $recipe,
        private RecipeImagePrograms $programs,
        private RecipeImageBuilds $builds,
        private GuestBootstrap $bootstrap,
    ) {}

    /**
     * Builds the template and returns its UUID. `$progress` hears each stage with its seconds.
     *
     * @param  (Closure(string, float): void)|null  $progress
     *
     * @throws TaskVmException `task_vm.image_build_running` while another UpCloud build runs, else `task_vm.image_build_failed`
     */
    public function build(?Closure $progress = null, ?MachineRecipe $machineRecipe = null, ?int $afterAttemptId = null, ?int $generation = null): string
    {
        $machineRecipe ??= $this->builds->currentRecipe();
        $lock = Cache::lock(self::Lock, self::LockSeconds);
        if (! $lock->get()) {
            throw new TaskVmException('task_vm.image_build_running', 'An UpCloud task VM template build is already running.');
        }
        $stamp = now()->format('YmdHis');
        Cache::put(self::Running.$stamp, true, self::RunningSeconds);
        $builder = TaskVmImageRecipe::WorkPrefix.$stamp;
        $smoke = $builder.'-smoke';
        $title = TaskVmUpCloud::TemplatePrefix.$stamp;
        $template = null;

        $attempt = null;
        try {
            $attempt = DB::transaction(function () use ($machineRecipe, $afterAttemptId, $generation): int {
                DB::table('task_vm_image_recipe')->where('id', 1)->update(['generation' => DB::raw('generation')]);
                if ($generation !== null) {
                    $this->fence($machineRecipe, $generation);
                }
                if ($afterAttemptId !== null && self::lastAttempt($machineRecipe->identity) !== $afterAttemptId) {
                    throw new TaskVmException(TaskVmError::ImageBuildSuperseded, 'This UpCloud recipe was already attempted. Request an explicit retry.');
                }

                return DB::table('upcloud_task_vm_image_builds')->insertGetId([
                    'recipe_identity' => $machineRecipe->identity, 'state' => 'running', 'started_at' => now(),
                ]);
            });
            $this->stage($progress, 'sweep', fn () => $this->sweep());
            [$address, $hostKey] = $this->stage($progress, 'launch', fn (): array => $this->launch($builder, $stamp, $this->upcloud()->sourceTemplate, $this->cloudInit->renderImageBuilder($this->keys->publicKey())));
            $shell = new UpCloudGuestShell($this->guest, $address, $hostKey);
            $this->stage($progress, 'cloud-init', fn () => $this->awaitCloudInit($builder, $shell));
            $this->stage($progress, 'orbit-user', fn () => $this->recipe->owner($shell->agentTarget()));
            foreach (RecipeImagePrograms::Phases as $phase) {
                $this->stage($progress, $phase, fn () => $this->programs->apply($shell, $machineRecipe, 'base', $phase));
            }
            $this->stage($progress, 'recipe-marker', fn () => $this->checked($builder, $shell->asRoot(
                ['install', '-D', '-m', '0644', '/dev/stdin', '/etc/orbit-machine-recipe.json'],
                json_encode(['identity' => $machineRecipe->identity, 'profile' => 'base'], JSON_THROW_ON_ERROR),
            )));
            $this->stage($progress, 'verify', fn () => $this->programs->verify($shell, $machineRecipe, 'base'));
            $this->stage($progress, 'masks', fn () => $this->recipe->boot($shell->agentTarget(), lean: false));
            $this->stage($progress, 'firewall', fn () => $this->firewall($builder, $shell));
            $this->stage($progress, 'clean', fn () => $this->clean($shell));
            $template = $this->stage($progress, 'templatize', fn (): string => $this->templatize($builder, $stamp, $title.TaskVmUpCloud::Unverified));
            $this->stage($progress, 'delete-builder', fn () => $this->servers->delete($builder, UpCloudServers::ImageLabel, $stamp, null));
            $this->stage($progress, 'smoke', fn () => $this->smoke($smoke, $stamp, $template, $machineRecipe));
            $this->stage($progress, 'promote', fn () => DB::transaction(function () use ($template, $title, $machineRecipe, $generation): void {
                if ($generation !== null) {
                    $this->fence($machineRecipe, $generation);
                }
                $this->servers->renameTemplate($template, $title, $machineRecipe->identity);
            }));
            $published = $template;
            $template = null;
            $this->stage($progress, 'prune', fn () => $this->prune());

            DB::table('upcloud_task_vm_image_builds')->where('id', $attempt)->update([
                'state' => 'succeeded', 'finished_at' => now(), 'template_uuid' => $published,
            ]);

            return $published;
        } catch (Throwable $exception) {
            if ($attempt !== null) {
                DB::table('upcloud_task_vm_image_builds')->where('id', $attempt)->update([
                    'state' => 'failed', 'finished_at' => now(),
                    'failure' => $exception instanceof TaskVmException && $exception->imageFailure !== null ? json_encode($exception->imageFailure, JSON_THROW_ON_ERROR) : null,
                ]);
                try {
                    $this->discard([$builder, $smoke], $stamp, $template);
                } catch (Throwable $cleanupError) {
                    report($cleanupError);
                }
            }

            throw $exception;
        } finally {
            Cache::forget(self::Running.$stamp);
            $lock->release();
        }
    }

    private function fence(MachineRecipe $recipe, int $generation): void
    {
        $matched = DB::table('task_vm_image_recipe')->where('id', 1)->where('identity', $recipe->identity)
            ->where('generation', $generation)->update(['generation' => $generation]);
        if ($matched !== 1) {
            throw new TaskVmException(TaskVmError::ImageBuildSuperseded, 'A newer recipe image generation is desired. This UpCloud build cannot continue or promote.');
        }
    }

    /** The last admitted attempt, including a worker that died before recording its result. */
    public static function lastAttempt(string $identity): int
    {
        $attempt = DB::table('upcloud_task_vm_image_builds')->where('recipe_identity', $identity)->max('id');

        return is_numeric($attempt) ? (int) $attempt : 0;
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
    private function awaitCloudInit(string $name, UpCloudGuestShell $shell): void
    {
        $this->bootstrap->awaitReady($shell->agentTarget(), "UpCloud server [{$name}]", self::CloudInitSeconds);
    }

    /**
     * Turns ufw on with incoming traffic denied, except the public SSH rule of a new Node on port 22 that enrollment
     * opens itself. ufw keeps the SSH connection of the build, which that rule allows anyway.
     */
    private function firewall(string $name, UpCloudGuestShell $shell): void
    {
        $this->checked($name, $shell->asRoot(['ufw', 'default', 'deny', 'incoming']));
        $this->checked($name, $shell->asUser((new NodeFirewallRuleCatalog)->publicSshRecovery(new Node(['public_ssh_port' => 22]))->arguments));
        $this->checked($name, $shell->asRoot(['ufw', '--force', 'enable']));
        if (preg_match('/^Status: active$/m', $this->checked($name, $shell->asRoot(['ufw', 'status']))->stdout) !== 1) {
            throw new TaskVmException('task_vm.image_build_failed', 'ufw is not active after it was enabled.', 502);
        }
    }

    private function clean(UpCloudGuestShell $shell): void
    {
        $this->recipe->clean($shell->agentTarget());
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
     * Gateway's key and the pinned uid and gid, and ufw must be on.
     */
    private function smoke(string $name, string $stamp, string $template, MachineRecipe $machineRecipe): void
    {
        [$address, $hostKey] = $this->launch($name, $stamp, $template, $this->cloudInit->render($this->keys->publicKey()));
        $shell = new UpCloudGuestShell($this->guest, $address, $hostKey);
        $this->awaitCloudInit($name, $shell);
        $this->programs->verify($shell, $machineRecipe, 'base');
        $this->recipe->check($shell->agentTarget(), firewall: true);
        $this->servers->delete($name, UpCloudServers::ImageLabel, $stamp, null);
    }

    /** Keeps the newest verified templates, and deletes the older ones. */
    private function prune(): void
    {
        $verified = array_filter($this->servers->templates(), static fn (string $title): bool => ! str_ends_with($title, TaskVmUpCloud::Unverified));
        arsort($verified, SORT_STRING);
        $pinned = TaskVm::query()->live()->where('provider', TaskVmProviderKind::UpCloud)->whereNull('provider_id')->pluck('image_fingerprint')->all();
        foreach (array_slice(array_keys($verified), self::KeepTemplates) as $uuid) {
            if (! in_array($uuid, $pinned, true)) {
                $this->servers->deleteTemplate($uuid);
            }
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
        return TaskVmBuildStage::run('task_vm.image_build_failed', 'UpCloud template build', $name, $progress, $work);
    }

    /** Fails on a non-zero exit of a command on the build or smoke server `$name`. */
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
