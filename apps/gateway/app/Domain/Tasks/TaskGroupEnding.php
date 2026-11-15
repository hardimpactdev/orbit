<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Models\Task;

/**
 * The records of a task group that ends without its work published: a cancel with no workspace, and the destroy of a task copy
 * (docs/cli/task/cancel.mmd). Call it inside the transaction that holds the locked group.
 */
final readonly class TaskGroupEnding
{
    /** Unbinds the workspace and cancels the group with its open subtasks; a completed group stays completed. */
    public function settle(Task $group, string $kind): void
    {
        $group->taskable()->dissociate();
        $group->fill(['status' => $kind === 'tasks.maintenance_finish' || $group->status === TaskGroupStatus::Completed ? TaskGroupStatus::Completed : TaskGroupStatus::Cancelled,
            'settled_at' => now(), 'reserved_at' => null, 'capacity_wait_reason' => null])->save();
        $group->tasks()->whereNotIn('status', [TaskStatus::Completed, TaskStatus::Failed, TaskStatus::Cancelled])
            ->update(['status' => TaskStatus::Cancelled, 'settled_at' => now()]);
    }
}
