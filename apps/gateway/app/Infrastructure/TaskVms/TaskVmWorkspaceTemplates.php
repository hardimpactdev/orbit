<?php

declare(strict_types=1);

namespace App\Infrastructure\TaskVms;

use App\Domain\TaskVms\TaskVmException;
use App\Domain\TaskVms\TaskVmHost;
use App\Domain\TaskVms\TaskVmSettings;
use App\Domain\TaskVms\TaskVmWorkspaceGuard;
use App\Infrastructure\AppDev\DevelopmentSshExecutor;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Models\Node;
use App\Models\Project;
use App\Models\TaskVm;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Claims a workspace template for a task VM. A template is an Incus block volume `tvm-template-<project id>-<time>`
 * in the host's project and pool ({@see TaskVmWorkspaceTemplate}). Its ext4 filesystem holds the Project's apps
 * directory: a checkout of the default branch in `workspace`, the checkout path of every task VM workspace
 * ({@see self::CheckoutDirectory}), with the output of the Project's setup steps, and the Playwright and Composer
 * caches in `.orbit-cache`. pnpm keeps its store at the root of that filesystem by itself.
 * {@see IncusTaskVmWorkspaceTemplateBuilder} builds it, and {@see TaskVmWorkspaceTemplateLifecycle} decides when.
 *
 * A claim clones the newest template that serves the Project as `<vm name>-workspace`, a ZFS clone that Incus makes
 * in well under a second, attaches it to the running VM as the disk `workspace`, and mounts it at the Project's
 * apps directory. The template checkout is already at the workspace's checkout path, so source preparation takes it
 * in place, and caches that record absolute paths still match. The clone goes
 * with the VM. Without a template, or when a step fails, the workspace starts from a fresh clone, as before.
 */
final readonly class TaskVmWorkspaceTemplates
{
    public const string Device = 'workspace';

    /**
     * The checkout of a task VM workspace, below the Project's apps directory. A task VM holds one workspace, so the
     * name is fixed: the template is built at this path, and tools that key their caches by absolute path, such as
     * Rector, find them after the claim.
     */
    public const string CheckoutDirectory = TaskVmWorkspaceGuard::WorkspaceCheckout;

    public const string CacheDirectory = '.orbit-cache';

    /** The caches below the managed user's `~/.cache` that live on the volume, so a template brings them along. */
    public const array Caches = ['ms-playwright', 'composer'];

    public function __construct(
        private IncusHost $incus,
        private TaskVmSettings $settings,
        private DevelopmentSshExecutor $ssh,
        private TaskVmWorkspaceTemplateVolumes $volumes,
    ) {}

    /** The clone volume of a task VM. */
    public static function cloneName(TaskVm $vm): string
    {
        return $vm->name.'-'.self::Device;
    }

    /**
     * The argv that mounts the disk `workspace` at `$directory` as the managed user, at a claim and in a template
     * builder (`workspace-mount.sh`). It refuses when the user is not `$owner`, the template's `uid:gid`. It prints
     * `template` when the template checkout is there.
     *
     * @return non-empty-list<string>
     */
    public static function mountCommand(string $directory, string $owner): array
    {
        return TaskVmScripts::bash('workspace-mount.sh', [self::Device, $directory, $owner, self::CheckoutDirectory, self::CacheDirectory, ...self::Caches]);
    }

    /**
     * Gives the task VM of `$project` a clone of the Project's current template at `$directory`, the Project's apps
     * directory on the VM. Returns the template checkout, or null when the workspace starts from a fresh clone. Every
     * step is idempotent, so a claim that runs again finds the clone, the disk, and the mount in place.
     *
     * A template counts only when it serves the Project on the VM's base image ({@see TaskVmWorkspaceTemplate::serves()}).
     * When a step after the clone fails, the claim unmounts and detaches the volume and deletes the clone before it
     * throws, so the fresh clone that follows starts on the VM's own disk.
     *
     * @throws TaskVmException when a host or guest step fails; the caller starts the workspace from a fresh clone. With
     *                         `task_vm.workspace_template_detach_failed` the volume may still be mounted, and the claim
     *                         must not go on.
     */
    public function attach(TaskVm $vm, Project $project, string $directory): ?string
    {
        $host = $this->settings->host((int) $vm->host_node_id);
        $hostNode = $vm->incusHostNode();
        $node = $vm->node ?? throw new TaskVmException('task_vm.not_enrolled', "Task VM [{$vm->name}] has no Node yet.");
        $clone = self::cloneName($vm);
        $volumes = $this->volumes->read($host, $hostNode);
        // A clone carries the config of its template, so a claim that runs again mounts it with the same owner.
        $template = $volumes[$clone] ?? null;
        if ($template === null) {
            $template = $this->select($host, $hostNode, $vm, $project, $volumes);
            if ($template === null) {
                return null;
            }
            $this->checked("the workspace clone of task VM [{$vm->name}]", 'storage volume copy', $this->incus->run($host, $hostNode, [
                'storage', 'volume', 'copy', '--', "{$host->pool}/{$template->name}", "{$host->pool}/{$clone}",
            ], timeout: 120.0));
        }

        try {
            $this->attachDisk($host, $hostNode, $vm, $clone);
            $mounted = $this->ssh->execute($node, new RemoteCommand(self::mountCommand($directory, (string) $template->owner), timeout: 30), 'task-vm-workspace-mount', 'task_vm.workspace_template_failed', 30, 'Task VM workspace');
        } catch (Throwable $exception) {
            $this->detach($host, $hostNode, $vm, $node, $directory, $exception);

            throw $exception;
        }

        if (trim($mounted->stdout) !== 'template') {
            Log::warning('Task VM workspace volume holds no template checkout; it starts from a fresh clone.', ['task_vm' => $vm->name, 'project_id' => $project->id]);

            return null;
        }

        return $directory.'/'.self::CheckoutDirectory;
    }

    /**
     * The template for a new clone: the newest that serves the Project on the VM's base image. Without one, it logs
     * why, and asks for a build when the VM runs the host's current base image: a build always boots that image, so it
     * cannot help a VM from an older one.
     *
     * @param  array<string, TaskVmWorkspaceTemplate>  $volumes
     */
    private function select(TaskVmHost $host, Node $hostNode, TaskVm $vm, Project $project, array $volumes): ?TaskVmWorkspaceTemplate
    {
        $image = $this->image($host, $hostNode, $vm);
        $template = TaskVmWorkspaceTemplate::newest($volumes, $project->id, $project->repository_url, $image);
        if ($template !== null) {
            return $template;
        }
        $context = ['task_vm' => $vm->name, 'project_id' => $project->id, 'host_node_id' => $host->nodeId, 'image' => $image];
        if (TaskVmWorkspaceTemplate::newest($volumes, $project->id, $project->repository_url) !== null) {
            $current = $this->incus->aliasTarget($host, $hostNode, TaskVmHost::BaseImage, 15.0);
            if ($current !== $image) {
                Log::warning('Task VM runs an older base image than the workspace templates; it starts from a fresh clone.', [...$context, 'current' => $current]);

                return null;
            }
            TaskVmWorkspaceTemplateLifecycle::requestBuild($host, $project);
            Log::warning('Task VM workspace template was built on another base image; it starts from a fresh clone, and a new build is queued.', $context);

            return null;
        }
        TaskVmWorkspaceTemplateLifecycle::requestBuild($host, $project);
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
     * Unmounts the volume in the guest (`workspace-unmount.sh`), detaches the disk, and deletes the clone, after a
     * failed attach. When that fails too, it throws `task_vm.workspace_template_detach_failed`: a fresh clone could
     * land on the volume.
     */
    private function detach(TaskVmHost $host, Node $hostNode, TaskVm $vm, Node $node, string $directory, Throwable $cause): void
    {
        try {
            $this->ssh->execute($node, new RemoteCommand(
                TaskVmScripts::bash('workspace-unmount.sh', [self::Device, $directory, self::CacheDirectory, ...self::Caches]),
                timeout: 30,
            ), 'task-vm-workspace-unmount', 'task_vm.workspace_template_detach_failed', 30, 'Task VM workspace');
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
        $this->volumes->delete($host, $hostNode, self::cloneName($vm));
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
        foreach (array_keys($this->volumes->read($host, $hostNode)) as $name) {
            if (preg_match('/\A(tvm-[0-9]+)-'.self::Device.'\z/D', $name, $match) === 1 && ! in_array($match[1], $live, true)) {
                $this->volumes->delete($host, $hostNode, $name);
                $deleted[] = $name;
            }
        }

        return $deleted;
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
