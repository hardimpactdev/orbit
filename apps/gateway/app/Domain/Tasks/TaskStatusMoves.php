<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Domain\Shared\ResourceOperationException;
use App\Models\Task;

/** Board updates never provision, cancel, complete a group, or reopen finished work. */
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
                    && in_array($to, [TaskStatus::Running, TaskStatus::Reviewing, TaskStatus::Completed, TaskStatus::Failed], true)))) {
            return;
        }

        throw self::refused();
    }

    private static function refused(): ResourceOperationException
    {
        return new ResourceOperationException('tasks.status_move_refused', 'This status move is not allowed. Repeat the current status or use the task lifecycle commands.', 422);
    }
}
