<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Domain\Shared\ResourceOperationException;
use App\Domain\TaskVms\TaskVmSlotReleaseProjection;
use App\Domain\TaskVms\TaskVmState;
use App\Models\Task;
use App\Models\TaskVm;
use App\Support\ValidatedData;

/**
 * The records of a task group after its slot is released (docs/cli/tasks/vm-release.mmd), from the input that admission captured.
 * The cancel of a group and the end of a proof run both settle through it, inside the transaction that holds the locked group.
 */
final readonly class TaskGroupEnding
{
    /**
     * Frees the slot, deleting its Node and workspace Instance, unbinds the workspace and cancels the group with its open subtasks.
     *
     * @param  array<string,mixed>  $captured
     */
    public function settle(Task $group, array $captured, string $kind): void
    {
        if (($captured['task_vm_id'] ?? null) !== null) {
            $slot = TaskVm::query()->lockForUpdate()->findOrFail(ValidatedData::integer($captured['task_vm_id']));
            if ($slot->group_id !== null && $slot->group_id !== $group->id) {
                throw new ResourceOperationException('operation.execution_forbidden', 'Cleanup no longer owns its slot.', 422);
            }
            app(TaskVmSlotReleaseProjection::class)->record($slot, true, null, true);
        }
        $group->taskable()->dissociate();
        $group->fill(['status' => $kind === 'tasks.maintenance_finish' || $group->status === TaskGroupStatus::Completed ? TaskGroupStatus::Completed : TaskGroupStatus::Cancelled,
            'settled_at' => now(), 'reserved_at' => null, 'capacity_wait_reason' => null])->save();
        $group->tasks()->whereNotIn('status', [TaskStatus::Completed, TaskStatus::Failed, TaskStatus::Cancelled])
            ->update(['status' => TaskStatus::Cancelled, 'settled_at' => now()]);
    }

    /** A release that did not pass keeps the group for another cancel; its slot is failed, so no claim takes it.
     * @param  array<string,mixed>  $captured
     */
    public function failed(array $captured, ?string $errorCode): void
    {
        if (($captured['task_vm_id'] ?? null) !== null) {
            $slot = TaskVm::query()->lockForUpdate()->find(ValidatedData::integer($captured['task_vm_id']));
            if ($slot instanceof TaskVm) {
                $slot->update(['state' => TaskVmState::Failed, 'error_code' => $errorCode ?? 'task_vm.slot_release_failed']);
            }
        }
    }
}
