<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Models\Operation;
use App\Models\Task;
use App\Models\TaskCheck;

/**
 * Where the review of a task with no subtask stands, from Orbit's records alone (docs/reference/tasks.md#a-task-with-no-subtask). The
 * work's status does not say it: a reviewer turn runs while the work stays `running`, and a completion without a reviewer turn on the
 * done check is no approval.
 */
final readonly class TaskWorkReview
{
    public const string Reviewing = 'reviewing';

    public const string Approved = 'approved';

    public const string NotApproved = 'not_approved';

    public function __construct(private TaskGroupDone $done) {}

    /**
     * `reviewing` while the newest turn of the reviewer thread has not ended; `approved` while the work is done by the done rule
     * (TaskGroupDone), also once its pull request is open; `not_approved` otherwise. Null for a task with subtasks and for an ended task.
     */
    public function of(Task $group): ?string
    {
        $group->loadMissing('tasks');
        if (! $group->tasks->contains(static fn (Task $task): bool => $task->type === TaskType::Work)
            || in_array($group->status, TaskGroupStatus::endedStatuses(), true)) {
            return null;
        }
        $turn = TaskGroupDone::reviewerTurn($group);
        if ($turn instanceof Operation && ! $turn->status->terminal()) {
            return self::Reviewing;
        }

        return $this->done->check($group, republish: true) instanceof TaskCheck ? self::Approved : self::NotApproved;
    }
}
