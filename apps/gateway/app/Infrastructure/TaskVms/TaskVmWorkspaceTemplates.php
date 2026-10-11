<?php

declare(strict_types=1);

namespace App\Infrastructure\TaskVms;

use App\Domain\Nodes\ManagedUserAccountResolver;
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
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

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

    /** How long a host waits before a claim asks for another build of a Project whose build failed. */
    public const int FailureBackoffSeconds = 21600;

    /**
     * Mounts the attached disk at the Project's apps directory, and only after the mount succeeded keeps it in
     * `/etc/fstab`, so a resumed VM mounts it again before its services start. A failed mount leaves no fstab line,
     * so a later boot never mounts the disk over a fresh clone. It never mounts over content. It points the caches at
     * the volume and prints `template` when the template checkout is there.
     */
    public const string MountScript = <<<'BASH'
        device=/dev/disk/by-id/scsi-0QEMU_QEMU_HARDDISK_incus_$1
        directory=$2
        user=$3
        group=$4
        template=$5
        cache=$6
        shift 6
        case "$directory" in /*) ;; *) exit 1 ;; esac
        case "$directory" in *[[:space:]]*) exit 1 ;; esac
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
            sudo -n install -d -o "$user" -g "$group" -m 0755 -- "$directory"
            sudo -n mount -o noatime -- "$device" "$directory"
        fi
        test "$(findmnt -n -o SOURCE --target "$directory")" = "$(readlink -f -- "$device")"
        line="$device $directory ext4 noatime,nofail,x-systemd.device-timeout=10s,x-systemd.before=multi-user.target 0 2"
        grep -qxF -- "$line" /etc/fstab || printf '%s\n' "$line" | sudo -n tee -a /etc/fstab >/dev/null
        test "$(stat -c '%U:%G' -- "$directory")" = "$user:$group"
        install -d -m 0755 -- "$HOME/.cache"
        for name in "$@"; do
            install -d -m 0755 -- "$directory/$cache/$name"
            if [ -e "$HOME/.cache/$name" ] && [ ! -L "$HOME/.cache/$name" ]; then
                rm -rf -- "${HOME:?}/.cache/$name"
            fi
            ln -sfn -- "$directory/$cache/$name" "$HOME/.cache/$name"
        done
        if [ -d "$directory/$template" ] && [ ! -L "$directory/$template" ]; then
            test "$(stat -c '%U:%G' -- "$directory/$template")" = "$user:$group"
            printf template
        fi
        BASH;

    public function __construct(
        private IncusHost $incus,
        private TaskVmSettings $settings,
        private DevelopmentSshExecutor $ssh,
        private ManagedUserAccountResolver $accounts,
    ) {}

    /** Whether the Project's groups run on task VMs of the web lane, which use workspace templates. */
    public static function serves(Project $project): bool
    {
        return $project->task_compute === TaskCompute::Vm && $project->slug !== 'orbit';
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
     * @throws TaskVmException when a host or guest step fails; the caller starts the workspace from a fresh clone
     */
    public function attach(TaskVm $vm, Project $project, string $directory): ?string
    {
        $host = $this->settings->host($vm->host_node_id);
        $hostNode = $vm->hostNode;
        $node = $vm->node ?? throw new TaskVmException('task_vm.not_enrolled', "Task VM [{$vm->name}] has no Node yet.");
        $clone = self::cloneName($vm);
        $volumes = $this->volumes($host, $hostNode);
        if (! array_key_exists($clone, $volumes)) {
            $template = self::current($volumes, $project->id, $project->repository_url);
            if ($template === null) {
                $this->requestBuild($host, $project);
                Log::warning('Task VM workspace has no template; it starts from a fresh clone.', ['task_vm' => $vm->name, 'project_id' => $project->id, 'host_node_id' => $host->nodeId]);

                return null;
            }
            $this->checked("the workspace clone of task VM [{$vm->name}]", 'storage volume copy', $this->incus->run($host, $hostNode, [
                'storage', 'volume', 'copy', '--', "{$host->pool}/{$template}", "{$host->pool}/{$clone}",
            ], timeout: 120.0));
        }
        $this->attachDisk($host, $hostNode, $vm, $clone);

        $account = $this->accounts->resolve($node);
        $mounted = $this->ssh->execute($node, new RemoteCommand([
            'bash', '-ceu', self::MountScript, '--', self::Device, $directory, $account->user, $account->group, self::TemplateDirectory, self::CacheDirectory, ...self::Caches,
        ], timeout: 30), 'task-vm-workspace-mount', 'task_vm.workspace_template_failed', 30, 'Task VM workspace');

        if (trim($mounted->stdout) !== 'template') {
            Log::warning('Task VM workspace volume holds no template checkout; it starts from a fresh clone.', ['task_vm' => $vm->name, 'project_id' => $project->id]);

            return null;
        }

        return $directory.'/'.self::TemplateDirectory;
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
     * Deletes ready templates of the host other than `$keep`: the other templates of `$projectId`, and, with
     * `$projects`, the templates of every Project not in that list. A template whose build has not finished stays:
     * only a build, under the host's template lock, deletes those. Incus keeps the data of a deleted template until
     * its last clone is gone, so a running group never loses its workspace.
     *
     * @param  list<int>|null  $projects
     * @return list<string> the deleted templates
     */
    public function prune(TaskVmHost $host, Node $hostNode, ?int $projectId = null, ?string $keep = null, ?array $projects = null): array
    {
        $deleted = [];
        foreach ($this->volumes($host, $hostNode) as $name => $config) {
            $owner = self::projectOf($name);
            if ($owner === null || $name === $keep) {
                continue;
            }
            if (($config[self::Ready] ?? null) !== 'true') {
                continue;
            }
            if ($owner === $projectId || ($projects !== null && ! in_array($owner, $projects, true))) {
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

    /** Queues a build of the Project's template on the host, unless its last build failed within the backoff. */
    public function requestBuild(TaskVmHost $host, Project $project): void
    {
        if (Cache::has(self::backoffKey($host->nodeId, $project->id))) {
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
     * The newest ready template of the Project that was cloned from `$repository`. After the Project's repository
     * changes, no template matches, so the claim asks for a build and starts from a fresh clone.
     *
     * @param  array<string, array<string, string>>  $volumes
     */
    private static function current(array $volumes, int $projectId, string $repository): ?string
    {
        $ready = array_filter(
            array_keys($volumes),
            static fn (string $name): bool => self::projectOf($name) === $projectId
                && ($volumes[$name][self::Ready] ?? null) === 'true'
                && ($volumes[$name][self::RepositoryKey] ?? null) === $repository,
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
