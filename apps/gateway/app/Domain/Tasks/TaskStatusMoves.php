<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Domain\Shared\ResourceOperationException;
use App\Models\Task;

/**
 * Board updates never provision, cancel, complete a group, or reopen finished work, except the last completed subtask of an open task
 * copy's group (docs/reference/tasks.md#reopen-the-last-subtask).
 */
final class TaskStatusMoves
{
    public static function group(Task $group, TaskGroupStatus $to): void
    {
        $from = $group->status;
        if ($from === $to) {
            return;
        }
        $planning = [TaskGroupStatus::Backlog, TaskGroupStatus::Todo];
        $active = [TaskGroupStatus::Running, TaskGroupStatus::Reviewing, TaskGroupStatus::Settling, TaskGroupStatus::WaitingForReview];
        if ((in_array($from, $planning, true) && in_array($to, $planning, true))
            || (in_array($from, $active, true) && in_array($to, $active, true))) {
            return;
        }

        throw self::refused();
    }

    public static function subtask(Task $group, Task $task, TaskStatus $to): void
    {
        $from = $task->status;
        if ($from === $to) {
            return;
        }
        if (! in_array($group->status, TaskGroupStatus::endedStatuses(), true)
            && (($from === TaskStatus::Todo && $to === TaskStatus::Running)
                || (in_array($from, [TaskStatus::Running, TaskStatus::Reviewing], true)
                    && in_array($to, [TaskStatus::Running, TaskStatus::Reviewing, TaskStatus::Completed, TaskStatus::Failed], true))
                || self::reopens($group, $task, $to))) {
            return;
        }

        throw self::refused();
    }

    /**
     * A reopen moves the group's last subtask by position that is not cancelled from `completed` to `running`, in a group whose workspace
     * is a task copy and whose other subtasks have all ended. The group must not have ended; the caller checks that.
     */
    public static function reopens(Task $group, Task $task, TaskStatus $to): bool
    {
        if ($task->status !== TaskStatus::Completed || $to !== TaskStatus::Running || TaskInstanceWorkspace::forGroup($group) === null) {
            return false;
        }
        $subtasks = Task::query()->where('parent_id', $group->id)->where('status', '!=', TaskStatus::Cancelled)
            ->orderByDesc('position')->orderByDesc('id')->get(['id', 'status']);

        return $subtasks->first()?->id === $task->id && $subtasks->every(static fn (Task $subtask): bool => $subtask->id === $task->id
            || in_array($subtask->status, [TaskStatus::Completed, TaskStatus::Failed], true));
    }

    private static function refused(): ResourceOperationException
    {
        return new ResourceOperationException('tasks.status_move_refused', 'This status move is not allowed. Repeat the current status or use the task lifecycle commands.', 422);
    }
}
