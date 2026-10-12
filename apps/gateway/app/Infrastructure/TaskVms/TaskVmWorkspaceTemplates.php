<?php

declare(strict_types=1);

namespace App\Infrastructure\TaskVms;

use App\Domain\SourceControl\GitBranchName;
use App\Domain\SourceControl\GitRepositoryOrigin;
use App\Domain\Tasks\TaskCompute;
use App\Domain\TaskVms\TaskVmException;
use App\Domain\TaskVms\TaskVmHost;
use App\Domain\TaskVms\TaskVmSettings;
use App\Infrastructure\AppDev\DevelopmentSshExecutor;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Jobs\TaskVms\BuildTaskVmWorkspaceTemplate;
use App\Models\Node;
use App\Models\Project;
use App\Models\TaskVm;
use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The per-Project workspace templates of a task VM host, and their clones. A template is an Incus block volume
 * `tvm-template-<project id>-<time>` in the host's project and pool. Its ext4 filesystem holds the Project's
 * apps directory: a checkout of the default branch in `.orbit-workspace-template` with the output of the Project's
 * setup steps, and the Playwright and Composer caches in `.orbit-cache`. pnpm keeps its store at the root of that
 * filesystem by itself. {@see IncusTaskVmWorkspaceTemplateBuilder} builds it and sets `user.orbit.ready` last.
 *
 * A claim clones the newest ready template of the Project as `<vm name>-workspace`, a ZFS clone that Incus makes
 * in well under a second, attaches it to the running VM as the disk `workspace`, and mounts it at the Project's
 * apps directory. Source preparation then renames the template checkout to the workspace checkout. The clone goes
 * with the VM. Without a template, or when a step fails, the workspace starts from a fresh clone, as before.
 *
 * Templates are built on demand: a claim that finds no template for its VM's base image queues a build, and the
 * refresh after a promote builds ahead only for recently active Projects. Builds run on their own queue.
 */
final readonly class TaskVmWorkspaceTemplates
{
    public const string VolumePrefix = 'tvm-template-';

    public const string Device = 'workspace';

    public const string TemplateDirectory = '.orbit-workspace-template';

    public const string CacheDirectory = '.orbit-cache';

    /** The caches below the managed user's `~/.cache` that live on the volume, so a template brings them along. */
    public const array Caches = ['ms-playwright', 'composer'];

    public const string Ready = 'user.orbit.ready';

    public const string ProjectKey = 'user.orbit.project';

    public const string CommitKey = 'user.orbit.commit';

    /** The repository URL the template was cloned from. A claim takes a template only while it is the Project's. */
    public const string RepositoryKey = 'user.orbit.repository';

    /** The base image the builder VM booted. A claim takes a template only for a VM from the same image. */
    public const string ImageKey = 'user.orbit.image';

    /** The managed user's `uid:gid` on the builder, which owns the files. The mount refuses a VM where it differs. */
    public const string OwnerKey = 'user.orbit.owner';

    /** How long a host waits before a claim asks for another build of a Project whose build failed. */
    public const int FailureBackoffSeconds = 21600;

    /**
     * The queue of the template jobs, on the `task-vms` connection. Its own worker takes them, so a build never
     * delays the jobs of task VMs on the `task-vms` queue.
     */
    public const string Queue = 'task-vm-templates';

    /** A refresh builds templates ahead only for Projects with a group created within this many days. */
    public const int ActiveDays = 7;

    /**
     * Mounts the attached disk at the Project's apps directory. It refuses before it mounts when the managed user's
     * `uid:gid` is not the template's `$owner`, and after it mounts when the volume root or the template checkout is
     * not owned by it. Only after those checks does it keep the mount in `/etc/fstab`, so a resumed VM mounts it again
     * before its services start, and point the caches at the volume. A failed check leaves the mount without an fstab
     * line, and the claim detaches it ({@see self::UnmountScript}). It never mounts over content. It prints `template`
     * when the template checkout is there.
     */
    public const string MountScript = <<<'BASH'
        device=/dev/disk/by-id/scsi-0QEMU_QEMU_HARDDISK_incus_$1
        directory=$2
        owner=$3
        template=$4
        cache=$5
        shift 5
        case "$directory" in /*) ;; *) exit 1 ;; esac
        case "$directory" in *[[:space:]]*) exit 1 ;; esac
        if [ "$(id -u):$(id -g)" != "$owner" ]; then
            printf 'owner: the template belongs to [%s], but this user is [%s]\n' "$owner" "$(id -u):$(id -g)" >&2
            exit 1
        fi
        for _ in $(seq 1 100); do
            [ -b "$device" ] && break
            sleep 0.1
        done
        test -b "$device"
        if ! mountpoint -q -- "$directory"; then
            if [ -e "$directory" ] || [ -L "$directory" ]; then
                test -d "$directory" && test ! -L "$directory"
                test -z "$(ls -A -- "$directory")"
            fi
            sudo -n install -d -o "$(id -u)" -g "$(id -g)" -m 0755 -- "$directory"
            sudo -n mount -o noatime -- "$device" "$directory"
        fi
        test "$(findmnt -n -o SOURCE --target "$directory")" = "$(readlink -f -- "$device")"
        if [ "$(stat -c '%u:%g' -- "$directory")" != "$owner" ]; then
            printf 'owner: the volume root belongs to [%s], not [%s]\n' "$(stat -c '%u:%g' -- "$directory")" "$owner" >&2
            exit 1
        fi
        found=0
        if [ -d "$directory/$template" ] && [ ! -L "$directory/$template" ]; then
            if [ "$(stat -c '%u:%g' -- "$directory/$template")" != "$owner" ]; then
                printf 'owner: the template checkout belongs to [%s], not [%s]\n' "$(stat -c '%u:%g' -- "$directory/$template")" "$owner" >&2
                exit 1
            fi
            found=1
        fi
        line="$device $directory ext4 noatime,nofail,x-systemd.device-timeout=10s,x-systemd.before=multi-user.target 0 2"
        grep -qxF -- "$line" /etc/fstab || printf '%s\n' "$line" | sudo -n tee -a /etc/fstab >/dev/null
        install -d -m 0755 -- "$HOME/.cache"
        for name in "$@"; do
            install -d -m 0755 -- "$directory/$cache/$name"
            if [ -e "$HOME/.cache/$name" ] && [ ! -L "$HOME/.cache/$name" ]; then
                rm -rf -- "${HOME:?}/.cache/$name"
            fi
            ln -sfn -- "$directory/$cache/$name" "$HOME/.cache/$name"
        done
        if [ "$found" = 1 ]; then
            printf template
        fi
        BASH;

    /**
     * Undoes a mount that a claim could not finish, so the fresh clone that follows lands on the VM's own disk: it
     * removes the cache links into the volume, unmounts it, and drops its fstab line. Each part that is already gone
     * counts as done.
     */
    public const string UnmountScript = <<<'BASH'
        device=/dev/disk/by-id/scsi-0QEMU_QEMU_HARDDISK_incus_$1
        directory=$2
        cache=$3
        shift 3
        case "$directory" in /*) ;; *) exit 1 ;; esac
        for name in "$@"; do
            link=$HOME/.cache/$name
            if [ -L "$link" ] && [ "$(readlink -- "$link")" = "$directory/$cache/$name" ]; then
                rm -f -- "$link"
            fi
        done
        if mountpoint -q -- "$directory"; then
            sudo -n umount -- "$directory"
        fi
        if mountpoint -q -- "$directory"; then exit 1; fi
        if awk -v d="$device" -v m="$directory" '$1 == d && $2 == m { found = 1 } END { exit found ? 0 : 1 }' /etc/fstab; then
            table=$(mktemp)
            awk -v d="$device" -v m="$directory" '!($1 == d && $2 == m)' /etc/fstab > "$table"
            sudo -n install -o root -g root -m 0644 -- "$table" /etc/fstab
            rm -f -- "$table"
        fi
        BASH;

    public function __construct(
        private IncusHost $incus,
        private TaskVmSettings $settings,
        private DevelopmentSshExecutor $ssh,
    ) {}

    /** Whether the Project's groups run on task VMs of the web lane, which use workspace templates. */
    public static function serves(Project $project): bool
    {
        return $project->task_compute === TaskCompute::Vm && $project->slug !== 'orbit';
    }

    /**
     * Why the Project cannot have a template, or null when it can. A build clones the default branch from the
     * repository, so it needs both. A Project without them gets no build, and no retry: only a change of the Project
     * helps.
     */
    public static function unbuildable(Project $project): ?string
    {
        if (! is_string($project->default_branch) || ! GitBranchName::isValid($project->default_branch)) {
            return 'the Project has no valid default branch';
        }

        return GitRepositoryOrigin::isValid($project->repository_url) ? null : 'the Project has no valid repository URL';
    }

    /** The clone volume of a task VM. */
    public static function cloneName(TaskVm $vm): string
    {
        return $vm->name.'-'.self::Device;
    }

    public static function templateName(int $projectId, string $stamp): string
    {
        return self::VolumePrefix.$projectId.'-'.$stamp;
    }

    /**
     * Gives the task VM of `$project` a clone of the Project's current template at `$directory`, the Project's apps
     * directory on the VM. Returns the template checkout, or null when the workspace starts from a fresh clone. Every
     * step is idempotent, so a claim that runs again finds the clone, the disk, and the mount in place.
     *
     * A template counts only when it was built from the Project's repository on the VM's base image. When a step after
     * the clone fails, the claim unmounts and detaches the volume and deletes the clone before it throws, so the fresh
     * clone that follows starts on the VM's own disk.
     *
     * @throws TaskVmException when a host or guest step fails; the caller starts the workspace from a fresh clone. With
     *                         `task_vm.workspace_template_detach_failed` the volume may still be mounted, and the claim
     *                         must not go on.
     */
    public function attach(TaskVm $vm, Project $project, string $directory): ?string
    {
        $host = $this->settings->host($vm->host_node_id);
        $hostNode = $vm->hostNode;
        $node = $vm->node ?? throw new TaskVmException('task_vm.not_enrolled', "Task VM [{$vm->name}] has no Node yet.");
        $clone = self::cloneName($vm);
        $volumes = $this->volumes($host, $hostNode);
        $template = $volumes[$clone] ?? null;
        if ($template === null) {
            $name = $this->select($host, $hostNode, $vm, $project, $volumes);
            if ($name === null) {
                return null;
            }
            $template = $volumes[$name];
            $this->checked("the workspace clone of task VM [{$vm->name}]", 'storage volume copy', $this->incus->run($host, $hostNode, [
                'storage', 'volume', 'copy', '--', "{$host->pool}/{$name}", "{$host->pool}/{$clone}",
            ], timeout: 120.0));
        }

        try {
            $this->attachDisk($host, $hostNode, $vm, $clone);
            $mounted = $this->ssh->execute($node, new RemoteCommand([
                'bash', '-ceu', self::MountScript, '--', self::Device, $directory, (string) ($template[self::OwnerKey] ?? ''), self::TemplateDirectory, self::CacheDirectory, ...self::Caches,
            ], timeout: 30), 'task-vm-workspace-mount', 'task_vm.workspace_template_failed', 30, 'Task VM workspace');
        } catch (Throwable $exception) {
            $this->detach($host, $hostNode, $vm, $node, $directory, $exception);

            throw $exception;
        }

        if (trim($mounted->stdout) !== 'template') {
            Log::warning('Task VM workspace volume holds no template checkout; it starts from a fresh clone.', ['task_vm' => $vm->name, 'project_id' => $project->id]);

            return null;
        }

        return $directory.'/'.self::TemplateDirectory;
    }

    /**
     * The template for a new clone: the newest ready one of the Project from its repository, built on the VM's base
     * image. Without one, it logs why, and asks for a build when the VM runs the host's current base image: a build
     * always boots that image, so it cannot help a VM from an older one.
     *
     * @param  array<string, array<string, string>>  $volumes
     */
    private function select(TaskVmHost $host, Node $hostNode, TaskVm $vm, Project $project, array $volumes): ?string
    {
        $image = $this->image($host, $hostNode, $vm);
        $template = self::current($volumes, $project->id, $project->repository_url, $image);
        if ($template !== null) {
            return $template;
        }
        $context = ['task_vm' => $vm->name, 'project_id' => $project->id, 'host_node_id' => $host->nodeId, 'image' => $image];
        if (self::current($volumes, $project->id, $project->repository_url) !== null) {
            $current = $this->incus->aliasTarget($host, $hostNode, TaskVmHost::BaseImage, 15.0);
            if ($current !== $image) {
                Log::warning('Task VM runs an older base image than the workspace templates; it starts from a fresh clone.', [...$context, 'current' => $current]);

                return null;
            }
            $this->requestBuild($host, $project);
            Log::warning('Task VM workspace template was built on another base image; it starts from a fresh clone, and a new build is queued.', $context);

            return null;
        }
        $this->requestBuild($host, $project);
        Log::warning('Task VM workspace has no template; it starts from a fresh clone.', $context);

        return null;
    }

    /** The fingerprint of the base image that the VM booted. */
    private function image(TaskVmHost $host, Node $hostNode, TaskVm $vm): string
    {
        $subject = "task VM [{$vm->name}]";
        $image = trim($this->checked($subject, 'config get', $this->incus->run($host, $hostNode, [
            'config', 'get', '--', $vm->name, 'volatile.base_image',
        ], timeout: 60.0))->stdout);

        return $image !== '' ? $image : throw IncusHost::invalid($subject, 'it records no base image');
    }

    /**
     * Unmounts the volume in the guest, detaches the disk, and deletes the clone, after a failed attach. When that
     * fails too, it throws `task_vm.workspace_template_detach_failed`: a fresh clone could land on the volume.
     */
    private function detach(TaskVmHost $host, Node $hostNode, TaskVm $vm, Node $node, string $directory, Throwable $cause): void
    {
        try {
            $this->ssh->execute($node, new RemoteCommand([
                'bash', '-ceu', self::UnmountScript, '--', self::Device, $directory, self::CacheDirectory, ...self::Caches,
            ], timeout: 30), 'task-vm-workspace-unmount', 'task_vm.workspace_template_detach_failed', 30, 'Task VM workspace');
            $removed = $this->incus->run($host, $hostNode, ['config', 'device', 'remove', '--', $vm->name, self::Device], timeout: 60.0);
            if (! $removed->succeeded() && $this->incus->run($host, $hostNode, ['config', 'device', 'get', '--', $vm->name, self::Device, 'source'], timeout: 60.0)->succeeded()) {
                throw IncusHost::failed("task VM [{$vm->name}]", 'config device remove', $removed);
            }
            $this->release($host, $hostNode, $vm);
        } catch (Throwable $exception) {
            throw new TaskVmException(
                'task_vm.workspace_template_detach_failed',
                "The workspace template of task VM [{$vm->name}] failed ({$cause->getMessage()}), and its volume could not be detached: {$exception->getMessage()}",
                502,
                $exception,
            );
        }
    }

    /** Deletes the clone of a task VM whose instance is gone. An absent clone counts as deleted. */
    public function release(TaskVmHost $host, Node $hostNode, TaskVm $vm): void
    {
        $this->deleteVolume($host, $hostNode, self::cloneName($vm));
    }

    /**
     * Deletes the clones of the host whose task VM has no live row, and returns their names. A clone of a VM that
     * still exists stays, because Incus refuses to delete a volume in use; the sweep deletes orphaned VMs first.
     *
     * @return list<string>
     */
    public function sweepClones(TaskVmHost $host, Node $hostNode): array
    {
        $live = TaskVm::query()->live()->where('host_node_id', $hostNode->id)->pluck('name')->all();
        $deleted = [];
        foreach (array_keys($this->volumes($host, $hostNode)) as $name) {
            if (preg_match('/\A(tvm-[0-9]+)-'.self::Device.'\z/D', $name, $match) === 1 && ! in_array($match[1], $live, true)) {
                $this->deleteVolume($host, $hostNode, $name);
                $deleted[] = $name;
            }
        }

        return $deleted;
    }

    /**
     * Deletes the ready templates of `$projectId` on the host other than `$keep`, the template a build just published.
     * A template whose build has not finished stays: only a build, under the host's template lock, deletes those.
     * Incus keeps the data of a deleted template until its last clone is gone, so a running group never loses its
     * workspace.
     *
     * @return list<string> the deleted templates
     */
    public function prune(TaskVmHost $host, Node $hostNode, int $projectId, string $keep): array
    {
        return $this->deleteReady($host, $hostNode, static fn (string $name, int $owner): bool => $owner === $projectId && $name !== $keep);
    }

    /**
     * Deletes every ready template of the host that no claim can take: the templates of Projects that are not in
     * `$repositories`, and of each Project there all but its newest template from that repository on `$image`, the
     * host's current base image. With no current image it keeps the newest template of the repository. So after a
     * promote, the host keeps at most one template for each Project, and none from the old image.
     *
     * @param  array<int, string>  $repositories  the repository URL of each Project that runs task VMs, by Project id
     * @return list<string> the deleted templates
     */
    public function pruneStale(TaskVmHost $host, Node $hostNode, array $repositories, ?string $image): array
    {
        $volumes = $this->volumes($host, $hostNode);
        $keep = [];
        foreach ($repositories as $projectId => $repository) {
            $keep[] = self::current($volumes, $projectId, $repository, $image);
        }

        return $this->deleteReady($host, $hostNode, static fn (string $name): bool => ! in_array($name, $keep, true), $volumes);
    }

    /**
     * @param  Closure(string, int): bool  $delete  whether to delete a ready template, by name and Project id
     * @param  array<string, array<string, string>>|null  $volumes
     * @return list<string>
     */
    private function deleteReady(TaskVmHost $host, Node $hostNode, Closure $delete, ?array $volumes = null): array
    {
        $deleted = [];
        foreach ($volumes ?? $this->volumes($host, $hostNode) as $name => $config) {
            $owner = self::projectOf($name);
            if ($owner !== null && ($config[self::Ready] ?? null) === 'true' && $delete($name, $owner)) {
                $this->deleteVolume($host, $hostNode, $name);
                $deleted[] = $name;
            }
        }

        return $deleted;
    }

    /**
     * The custom volumes of the host's project, as name => config.
     *
     * @return array<string, array<string, string>>
     */
    public function volumes(TaskVmHost $host, Node $hostNode): array
    {
        $subject = "the storage volumes of Node [{$hostNode->name}]";
        $list = IncusHost::json($subject, $this->checked($subject, 'storage volume list', $this->incus->run($host, $hostNode, [
            'storage', 'volume', 'list', '--format', 'json', '--', $host->pool,
        ], timeout: 60.0)));
        if (! array_is_list($list)) {
            throw IncusHost::invalid($subject, 'the volume list is not a JSON list');
        }
        $volumes = [];
        foreach ($list as $volume) {
            if (! is_array($volume) || ! is_string($volume['name'] ?? null) || ! is_string($volume['type'] ?? null)) {
                throw IncusHost::invalid($subject, 'a volume has no name or type');
            }
            if ($volume['type'] !== 'custom') {
                continue;
            }
            $config = is_array($volume['config'] ?? null) ? $volume['config'] : [];
            $volumes[$volume['name']] = array_filter($config, is_string(...));
        }

        return $volumes;
    }

    /** Deletes a volume of the host's pool. An absent volume counts as deleted. */
    public function deleteVolume(TaskVmHost $host, Node $hostNode, string $name): void
    {
        $result = $this->incus->run($host, $hostNode, ['storage', 'volume', 'delete', '--', $host->pool, $name], timeout: 120.0);
        if (! $result->succeeded() && array_key_exists($name, $this->volumes($host, $hostNode))) {
            throw IncusHost::failed("the storage volume [{$name}]", 'storage volume delete', $result);
        }
    }

    /**
     * Queues a build of the Project's template on the host, unless its last build failed within the backoff. A
     * Project that cannot have a template gets a warning instead.
     */
    public function requestBuild(TaskVmHost $host, Project $project): void
    {
        if (Cache::has(self::backoffKey($host->nodeId, $project->id))) {
            return;
        }
        $reason = self::unbuildable($project);
        if ($reason !== null) {
            Log::warning('Task VM workspace template cannot be built; workspaces of the Project start from a fresh clone.', [
                'project_id' => $project->id, 'host_node_id' => $host->nodeId, 'reason' => $reason,
            ]);

            return;
        }
        BuildTaskVmWorkspaceTemplate::dispatch($host->nodeId, $project->id);
    }

    public static function backoffKey(int $hostNodeId, int $projectId): string
    {
        return "task-vms:workspace-template-backoff:{$hostNodeId}:{$projectId}";
    }

    /** The Project id of a template volume name, or null for another volume. */
    public static function projectOf(string $name): ?int
    {
        return preg_match('/\A'.preg_quote(self::VolumePrefix, '/').'([1-9][0-9]*)-[0-9]{14}\z/D', $name, $match) === 1 ? (int) $match[1] : null;
    }

    /**
     * The newest ready template of the Project that was cloned from `$repository`, and with `$image` built on that
     * base image. After the Project's repository or the base image changes, no template matches, so the claim starts
     * from a fresh clone.
     *
     * @param  array<string, array<string, string>>  $volumes
     */
    private static function current(array $volumes, int $projectId, string $repository, ?string $image = null): ?string
    {
        $ready = array_filter(
            array_keys($volumes),
            static fn (string $name): bool => self::projectOf($name) === $projectId
                && ($volumes[$name][self::Ready] ?? null) === 'true'
                && ($volumes[$name][self::RepositoryKey] ?? null) === $repository
                && ($image === null || ($volumes[$name][self::ImageKey] ?? null) === $image),
        );
        rsort($ready, SORT_STRING);

        return $ready[0] ?? null;
    }

    /** Attaches the clone as the disk `workspace`. A disk that already names the clone counts as attached. */
    private function attachDisk(TaskVmHost $host, Node $hostNode, TaskVm $vm, string $clone): void
    {
        $result = $this->incus->run($host, $hostNode, [
            'config', 'device', 'add', '--', $vm->name, self::Device, 'disk', "pool={$host->pool}", "source={$clone}",
        ], timeout: 60.0);
        if ($result->succeeded()) {
            return;
        }
        $source = $this->incus->run($host, $hostNode, ['config', 'device', 'get', '--', $vm->name, self::Device, 'source'], timeout: 60.0);
        if (! $source->succeeded() || trim($source->stdout) !== $clone) {
            throw IncusHost::failed("task VM [{$vm->name}]", 'config device add', $result);
        }
    }

    private function checked(string $subject, string $operation, CommandResult $result): CommandResult
    {
        return $result->succeeded() ? $result : throw IncusHost::failed($subject, $operation, $result);
    }
}
