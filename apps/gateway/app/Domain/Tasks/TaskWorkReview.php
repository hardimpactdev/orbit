<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Models\Operation;
use App\Models\Task;
use App\Models\TaskCheck;

/**
 * Where the review of a task with no subtask stands, from Orbit's records alone (docs/reference/tasks.md#a-task-with-no-subtask). The
 * work's status does not say it: a reviewer turn runs while the work stays `running`, and a completion without a reviewer turn on the
 * newest check is no approval. Approval is of the reviewed check, not readiness to publish it: a publish, the copy's removal or the
 * task's end keep it, and a reopen or a newer handoff check drops it.
 */
final readonly class TaskWorkReview
{
    public const string Reviewing = 'reviewing';

    public const string Approved = 'approved';

    public const string NotApproved = 'not_approved';

    /**
     * `reviewing` while the newest turn of the reviewer thread has not ended. `approved` while the work is completed, its newest handoff
     * check passed, and the reviewer thread's newest turn reviewed exactly that check's commit and ended done (TaskGroupDone::reviewed).
     * `not_approved` otherwise. Null for a task with subtasks.
     */
    public static function of(Task $group): ?string
    {
        $group->loadMissing('tasks');
        $work = $group->tasks->first(static fn (Task $task): bool => $task->type === TaskType::Work);
        if (! $work instanceof Task) {
            return null;
        }
        $turn = TaskGroupDone::reviewerTurn($group);
        if ($turn instanceof Operation && ! $turn->status->terminal()) {
            return self::Reviewing;
        }
        if ($work->status !== TaskStatus::Completed) {
            return self::NotApproved;
        }
        $newest = TaskCheck::query()->where('task_id', $work->id)->where('kind', TaskCheckKind::Handoff->value)->orderByDesc('id')->first();

        return $newest instanceof TaskCheck && $newest->status === TaskCheckStatus::Passed && TaskGroupDone::reviewed($group, $work, $newest)
            ? self::Approved : self::NotApproved;
    }
}
