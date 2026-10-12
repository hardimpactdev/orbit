<?php

declare(strict_types=1);

namespace App\Infrastructure\TaskVms;

use App\Actions\TaskVms\AllocateTaskVmAction;
use App\Actions\TaskVms\WakeTaskVmAction;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tasks\TaskCapacityException;
use App\Domain\TaskVms\TaskVmException;
use App\Domain\TaskVms\TaskVmPlacement;
use App\Domain\TaskVms\TaskVmSettings;
use App\Domain\TaskVms\TaskVmState;
use App\Jobs\TaskVms\ResumeTaskVm;
use App\Models\Node;
use App\Models\Task;
use App\Models\TaskVm;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The claim side of a `vm` group: the Node of its ready task VM. Until the VM is ready, the
 * claim waits with a `Task VM:` reason and tries again on the next tick. The first wait allocates the VM.
 *
 * It reads `TaskVmSettings` only here, never when the scheduler is built, so an invalid task VM value
 * makes `vm` groups wait and leaves shared groups alone.
 */
final readonly class TaskVmWorkspace
{
    /** @throws TaskCapacityException while the group's task VM is not ready */
    public function node(Task $group): Node
    {
        $vm = TaskVmPlacement::forGroup($group);
        if (! $vm instanceof TaskVm) {
            try {
                if (! app(TaskVmSettings::class)->enabled) {
                    throw new TaskCapacityException(false, 'Task VM: task VMs are not enabled on this Gateway.');
                }
                $vm = app(AllocateTaskVmAction::class)->execute($group);
            } catch (TaskVmException $exception) {
                throw new TaskCapacityException(false, "Task VM: {$exception->errorCode}: {$exception->getMessage()}");
            }
        }

        $node = $vm->node;
        if ($vm->state === TaskVmState::Ready && $node instanceof Node && $node->status === LifecycleStatus::Active) {
            return $node;
        }

        if ($vm->state === TaskVmState::Parked) {
            ResumeTaskVm::dispatch($vm->id);
        }

        throw new TaskCapacityException(false, match ($vm->state) {
            TaskVmState::Failed => "Task VM: failed: {$vm->error_code}: {$vm->error_message}",
            TaskVmState::Destroying => 'Task VM: destroying.',
            TaskVmState::Parked => WakeTaskVmAction::Resuming,
            default => 'Task VM: provisioning.',
        });
    }

    /** The group's task VM that is not destroyed, or null for a group without one. */
    public function vm(Task $group): ?TaskVm
    {
        return TaskVmPlacement::forGroup($group);
    }

    /**
     * Gives the group's task VM a clone of its Project's workspace template, mounted at `$directory`, the Project's
     * apps directory on the VM, and returns the template checkout. Without a template, or when a step fails, it logs a
     * warning and returns null, and the workspace starts from a fresh clone: a template only makes a claim faster.
     * A volume that could not be detached after a failure stays an error: a fresh clone would land on it. An UpCloud
     * VM has no template.
     *
     * @throws TaskVmException `task_vm.workspace_template_detach_failed`
     */
    public function template(TaskVm $vm, Task $group, string $directory): ?string
    {
        // Templates are volumes on an Incus host. An UpCloud VM starts from a fresh clone.
        if ($vm->provider !== TaskVm::Incus) {
            return null;
        }
        try {
            return app(TaskVmWorkspaceTemplates::class)->attach($vm, $group->project, $directory);
        } catch (Throwable $exception) {
            if (TaskVmException::codeOf($exception) === 'task_vm.workspace_template_detach_failed') {
                throw $exception;
            }
            Log::warning('Task VM workspace template could not be attached; the workspace starts from a fresh clone.', [
                'task_vm' => $vm->name,
                'project_id' => $group->project_id,
                'error_code' => TaskVmException::codeOf($exception),
                'error' => mb_substr($exception->getMessage(), 0, 2000),
            ]);

            return null;
        }
    }
}
