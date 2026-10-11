<?php

declare(strict_types=1);

namespace App\Infrastructure\TaskVms;

use App\Domain\GitHub\RepositoryReadAccess;
use App\Domain\Instances\ComposerSourceClassifier;
use App\Domain\Nodes\ManagedUserAccount;
use App\Domain\Nodes\Storage\NodeSettingsNormalizer;
use App\Domain\Nodes\Storage\StorageRootResolver;
use App\Domain\Projects\LifecyclePhase;
use App\Domain\SourceControl\GitRepositoryOrigin;
use App\Domain\TaskVms\TaskVmException;
use App\Domain\TaskVms\TaskVmHost;
use App\Infrastructure\AppDev\DevelopmentSshExecutor;
use App\Infrastructure\GitHub\GitReadScript;
use App\Infrastructure\Nodes\RemotePhpPackageManager;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\ProtectedInput;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\Node;
use App\Models\Project;
use App\Models\ProjectLifecycleStep;
use Closure;
use Illuminate\Cache\Lock;
use Illuminate\Contracts\Cache\Lock as CacheLock;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Builds the workspace template of one Project on one task VM host ({@see TaskVmWorkspaceTemplates}). A builder VM
 * `tvm-template-<time>` boots the base image with the task VM user-data and gets a new block volume. The volume is
 * mounted at the Project's apps directory with the same script as a claim. The builder clones the default branch,
 * installs the PHP packages that the Project's Instance gets, and runs the Project's setup steps as `orbit`, as the
 * baseline check does. The tree must stay clean. Then the volume is detached and marked ready, the builder goes, and
 * older templates of the Project are deleted. No agent and no group ever ran on the volume.
 *
 * One build runs on a host at a time. A failed build deletes its builder and its volume, and the Project keeps its
 * current template. A build that was killed is cleaned up by the next build on the host.
 */
final readonly class IncusTaskVmWorkspaceTemplateBuilder
{
    public const string BuilderPrefix = 'tvm-template-';

    private const string ErrorCode = 'task_vm.workspace_template_failed';

    private const int LockSeconds = 1500;

    private const string ManagedUser = 'orbit';

    /** Formats the new volume and gives its root to the managed user. The mount script then mounts it, as at a claim. */
    private const string Format = <<<'BASH'
        device=/dev/disk/by-id/scsi-0QEMU_QEMU_HARDDISK_incus_$1
        for _ in $(seq 1 100); do
            [ -b "$device" ] && break
            sleep 0.1
        done
        test -b "$device"
        mkfs.ext4 -q -F -m 0 -L orbit-workspace -E lazy_itable_init=1,lazy_journal_init=1 "$device"
        staging=$(mktemp -d)
        mount -- "$device" "$staging"
        rmdir -- "$staging/lost+found"
        chown -- "$2:$3" "$staging"
        umount -- "$staging"
        rmdir -- "$staging"
        BASH;

    /** Clones the default branch into the template checkout and prints its commit. */
    private const string Clone = <<<'BASH'
        checkout=$1
        branch=$2
        repository=$3
        git_read git -c core.hooksPath=/dev/null -c core.fsmonitor=false clone --no-checkout --origin origin -- "$repository" "$checkout"
        git -C "$checkout" checkout --quiet --force -B "$branch" "refs/remotes/origin/$branch"
        git -C "$checkout" rev-parse --verify 'HEAD^{commit}'
        BASH;

    public function __construct(
        private IncusHost $incus,
        private TaskVmBaseImage $image,
        private TaskVmCloudInit $cloudInit,
        private SshKeyProvider $keys,
        private KnownHostsStore $knownHosts,
        private TaskVmWorkspaceTemplates $templates,
        private RepositoryReadAccess $access,
        private ComposerSourceClassifier $classifier,
        private RemotePhpPackageManager $php,
        private StorageRootResolver $storageRoots,
        private NodeSettingsNormalizer $nodeSettings,
    ) {}

    /**
     * Builds the Project's template on the host and returns the template volume. `$progress` hears each stage with its
     * seconds. `$owner` names the host's template lock, so a caller that is killed can {@see abandon()} its build.
     *
     * @param  (Closure(string, float): void)|null  $progress
     *
     * @throws TaskVmException `task_vm.workspace_template_running` while another template build of the host runs, else `task_vm.workspace_template_failed`
     */
    public function build(TaskVmHost $host, Node $node, Project $project, ?Closure $progress = null, ?string $owner = null): string
    {
        $lock = $this->lock($node, $owner);
        $stamp = now()->format('YmdHis');
        $builder = self::BuilderPrefix.$stamp;
        $volume = TaskVmWorkspaceTemplates::templateName($project->id, $stamp);
        $directory = $this->directory($project);
        $checkout = $directory.'/'.TaskVmWorkspaceTemplates::TemplateDirectory;

        try {
            $steps = $this->stage($node, $progress, 'render', fn (): array => $this->setupSteps($project));
            $this->stage($node, $progress, 'sweep', fn () => $this->sweep($host, $node));
            $this->stage($node, $progress, 'launch', fn () => $this->image->launch($host, $node, $builder, TaskVmHost::BaseImage, $this->cloudInit->render($this->keys->publicKey())));
            $this->stage($node, $progress, 'cloud-init', fn () => $this->image->awaitCloudInit($host, $node, $builder));
            $this->stage($node, $progress, 'volume', fn () => $this->createVolume($host, $node, $builder, $volume));
            $this->stage($node, $progress, 'mount', fn () => $this->mount($host, $node, $builder, $directory));
            $commit = $this->stage($node, $progress, 'clone', fn (): string => $this->cloneDefaultBranch($host, $node, $builder, $project, $checkout));
            $this->stage($node, $progress, 'php', fn () => $this->installPhp($host, $node, $builder, $project, $checkout));
            foreach ($steps as $step) {
                $this->stage($node, $progress, 'setup:'.$step->name, fn () => $this->runStep($host, $node, $builder, $checkout, $step));
            }
            $this->stage($node, $progress, 'verify', fn () => $this->verify($host, $node, $builder, $checkout));
            $this->stage($node, $progress, 'detach', fn () => $this->detach($host, $node, $builder, $directory));
            $this->stage($node, $progress, 'publish', fn () => $this->checked($builder, 'storage volume set', $this->incus->run($host, $node, [
                'storage', 'volume', 'set', '--', $host->pool, $volume,
                TaskVmWorkspaceTemplates::ProjectKey.'='.$project->id,
                TaskVmWorkspaceTemplates::CommitKey.'='.$commit,
                TaskVmWorkspaceTemplates::Ready.'=true',
            ])));
            $this->stage($node, $progress, 'delete-builder', fn () => $this->image->delete($host, $node, $builder));
            $this->stage($node, $progress, 'prune', fn (): array => $this->templates->prune($host, $node, $project->id, $volume));

            return $volume;
        } catch (Throwable $exception) {
            // Best effort, so the build's own error stays the reported one; the next build sweeps what remains.
            $this->incus->run($host, $node, ['delete', '--force', '--', $builder]);
            $this->incus->run($host, $node, ['storage', 'volume', 'delete', '--', $host->pool, $volume]);

            throw $exception;
        } finally {
            $lock->release();
        }
    }

    /**
     * Cleans up after a build whose process stopped before its own cleanup ran: deletes builder VMs and unfinished
     * templates, and releases the lock. Does nothing unless `$owner` still holds the host's template lock.
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

    /** The Project's apps directory on a task VM, where the volume is mounted. */
    public function directory(Project $project): string
    {
        return $this->storageRoots->resolveApps(
            $this->nodeSettings->fromStored(null),
            new ManagedUserAccount(self::ManagedUser, self::ManagedUser, '/home/'.self::ManagedUser),
        )->append($project->slug)->value;
    }

    private function lock(Node $node, ?string $owner): CacheLock
    {
        $lock = Cache::lock(self::lockName($node), self::LockSeconds, $owner);

        return $lock->get() ? $lock : throw new TaskVmException('task_vm.workspace_template_running', "A workspace template build on Node [{$node->name}] is already running.", 409);
    }

    private static function lockName(Node $node): string
    {
        return 'task-vms:workspace-template:'.$node->id;
    }

    /** Deletes builder VMs and template volumes that an interrupted build left. Runs under the host's template lock. */
    private function sweep(TaskVmHost $host, Node $node): void
    {
        foreach (array_keys($this->image->instances($host, $node)) as $name) {
            if (str_starts_with($name, self::BuilderPrefix)) {
                $this->image->delete($host, $node, $name);
            }
        }
        foreach ($this->templates->volumes($host, $node) as $name => $config) {
            if (TaskVmWorkspaceTemplates::projectOf($name) !== null && ($config[TaskVmWorkspaceTemplates::Ready] ?? null) !== 'true') {
                $this->templates->deleteVolume($host, $node, $name);
            }
        }
    }

    /** @return list<ProjectLifecycleStep> */
    private function setupSteps(Project $project): array
    {
        GitRepositoryOrigin::validate($project->repository_url);

        return array_values(ProjectLifecycleStep::query()
            ->where('project_id', $project->id)
            ->where('phase', LifecyclePhase::Setup->value)
            ->orderBy('position')
            ->orderBy('id')
            ->get()
            ->all());
    }

    private function createVolume(TaskVmHost $host, Node $node, string $builder, string $volume): void
    {
        $this->checked($builder, 'storage volume create', $this->incus->run($host, $node, [
            'storage', 'volume', 'create', '--type=block', '--', $host->pool, $volume, "size={$host->disk}",
        ]));
        $this->checked($builder, 'config device add', $this->incus->run($host, $node, [
            'config', 'device', 'add', '--', $builder, TaskVmWorkspaceTemplates::Device, 'disk', "pool={$host->pool}", "source={$volume}",
        ]));
    }

    private function mount(TaskVmHost $host, Node $node, string $builder, string $directory): void
    {
        $this->checked($builder, 'exec format', $this->image->exec($host, $node, $builder, [
            'bash', '-ceu', self::Format, '--', TaskVmWorkspaceTemplates::Device, self::ManagedUser, self::ManagedUser,
        ]));
        $this->checked($builder, 'exec mount', $this->asManagedUser($host, $node, $builder, '/home/'.self::ManagedUser, [
            'bash', '-ceu', TaskVmWorkspaceTemplates::MountScript, '--', TaskVmWorkspaceTemplates::Device, $directory, self::ManagedUser, self::ManagedUser,
            TaskVmWorkspaceTemplates::TemplateDirectory, TaskVmWorkspaceTemplates::CacheDirectory, ...TaskVmWorkspaceTemplates::Caches,
        ]));
    }

    private function cloneDefaultBranch(TaskVmHost $host, Node $node, string $builder, Project $project, string $checkout): string
    {
        $repository = GitRepositoryOrigin::validate($project->repository_url);
        $script = GitReadScript::for($this->access->for($repository, $project->source_access), self::Clone);
        $result = $this->checked($builder, 'exec clone', $this->asManagedUser($host, $node, $builder, '/home/'.self::ManagedUser, [
            'bash', '-seu', '--', $checkout, (string) $project->default_branch, $repository,
        ], $script->protectedInput ?? $script->input));
        $commit = trim($result->stdout);

        return preg_match('/\A[0-9a-f]{40}(?:[0-9a-f]{24})?\z/D', $commit) === 1
            ? $commit
            : throw IncusHost::invalid("the workspace template builder [{$builder}]", 'the clone printed no commit');
    }

    /**
     * Installs the `app-dev` PHP packages of the PHP version that the Project's `composer.json` selects, with the same
     * program that the workspace's Instance runs on a task VM. A Project without `composer.json` needs none.
     */
    private function installPhp(TaskVmHost $host, Node $node, string $builder, Project $project, string $checkout): void
    {
        $composer = $this->checked($builder, 'exec read composer.json', $this->asManagedUser($host, $node, $builder, $checkout, [
            'sh', '-c', 'if [ -f composer.json ]; then cat composer.json; else printf absent; fi',
        ]));
        if ($composer->stdout === 'absent') {
            return;
        }
        $version = $this->classifier->classify($composer->stdout, $project->type, 'regular')->phpVersion;
        if ($version === null) {
            return;
        }
        $shell = new DevelopmentSshExecutor(new TaskVmBuilderShell($this->incus, $host, $node, $builder), $this->keys, $this->knownHosts);
        $this->php->installForAppDev(new Node(['name' => $builder, 'platform' => 'linux', 'user' => self::ManagedUser, 'wireguard_ip' => '127.0.0.1']), collect([$version]), $shell);
    }

    /** Runs one setup step as the baseline check does: a login Bash in the checkout, within the step's timeout. */
    private function runStep(TaskVmHost $host, Node $node, string $builder, string $checkout, ProjectLifecycleStep $step): void
    {
        $seconds = max(1, (int) $step->timeout_seconds);
        $this->checked($builder, "setup step [{$step->name}]", $this->asManagedUser($host, $node, $builder, $checkout, [
            'timeout', (string) $seconds, 'bash', '-l', '-c', (string) $step->command,
        ], null, $seconds + 30.0));
    }

    /** The setup steps may only add ignored files, so a claim's checkout of its branch starts from a clean tree. */
    private function verify(TaskVmHost $host, Node $node, string $builder, string $checkout): void
    {
        $status = $this->checked($builder, 'exec git status', $this->asManagedUser($host, $node, $builder, $checkout, ['git', 'status', '--porcelain']));
        if (trim($status->stdout) !== '') {
            throw IncusHost::invalid("the workspace template builder [{$builder}]", 'the setup steps changed tracked or unignored files: '.mb_substr(trim($status->stdout), 0, 500));
        }
    }

    private function detach(TaskVmHost $host, Node $node, string $builder, string $directory): void
    {
        $this->checked($builder, 'exec umount', $this->image->exec($host, $node, $builder, ['sh', '-euc', 'sync; umount -- "$1"', 'sh', $directory]));
        $this->checked($builder, 'config device remove', $this->incus->run($host, $node, ['config', 'device', 'remove', '--', $builder, TaskVmWorkspaceTemplates::Device]));
    }

    /** @param  list<string>  $command */
    private function asManagedUser(TaskVmHost $host, Node $node, string $builder, string $cwd, array $command, string|ProtectedInput|null $input = null, float $timeout = 900.0): CommandResult
    {
        return $this->incus->run($host, $node, [
            'exec', '--cwd', $cwd, '--env', 'HOME=/home/'.self::ManagedUser, '--env', 'USER='.self::ManagedUser,
            '--', $builder, 'runuser', '-u', self::ManagedUser, '--', ...$command,
        ], $input, $timeout);
    }

    private function checked(string $builder, string $operation, CommandResult $result): CommandResult
    {
        return $result->succeeded() ? $result : throw IncusHost::failed("the workspace template builder [{$builder}]", $operation, $result);
    }

    /**
     * @template T
     *
     * @param  (Closure(string, float): void)|null  $progress
     * @param  Closure(): T  $work
     * @return T
     */
    private function stage(Node $node, ?Closure $progress, string $name, Closure $work): mixed
    {
        return $this->image->stage(self::ErrorCode, "workspace template build on Node [{$node->name}]", $name, $progress, $work);
    }
}
