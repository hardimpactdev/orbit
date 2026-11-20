<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Domain\Operations\OperationKinds;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;
use App\Models\Task;
use App\Models\TaskCheck;

/**
 * Whether a task copy's work is done, from Orbit's own records alone (docs/reference/tasks.md#when-the-work-is-done). A copy reviewer
 * turn records no verdict: the caller's completion of the last subtask after a reviewer turn on exactly the done check is the approval.
 */
final readonly class TaskGroupDone
{
    public function __construct(private TaskCheckedCandidate $candidates) {}

    /**
     * The done check, or null while the work is not done. With `$republish`, a person's publish also finds the done check of a group
     * that has its pull request: the publish pushes that check to the pull request's branch (docs/reference/tasks.md#release-a-waiting-group).
     */
    public function check(Task $group, bool $republish = false): ?TaskCheck
    {
        $group->loadMissing('project', 'taskable', 'tasks');
        if ($group->parent_id !== null || $group->orchestration !== TaskOrchestration::External || ($group->pr_url !== null && ! $republish)
            || in_array($group->status, TaskGroupStatus::endedStatuses(), true) || TaskInstanceWorkspace::forGroup($group) === null) {
            return null;
        }
        $subtasks = $group->tasks;
        if ($subtasks->contains(static fn (Task $task): bool => ! in_array($task->status, [TaskStatus::Completed, TaskStatus::Failed, TaskStatus::Cancelled], true))) {
            return null;
        }
        $last = $subtasks->filter(static fn (Task $task): bool => $task->status === TaskStatus::Completed)->sortBy([['position', 'desc'], ['id', 'desc']])->first();
        if (! $last instanceof Task) {
            return null;
        }
        // The newest handoff check of the group, whatever its status: a newer check than the candidate means the work moved on.
        $newest = TaskCheck::query()->whereIn('task_id', $subtasks->modelKeys())->where('kind', TaskCheckKind::Handoff->value)->orderByDesc('id')->first();
        if (! $newest instanceof TaskCheck || $newest->task_id !== $last->id || ! $this->candidates->eligible($group, $newest)) {
            return null;
        }

        return self::reviewed($group, $last, $newest) && $this->idle($group) ? $newest : null;
    }

    /**
     * The newest turn of the group's reviewer thread reviewed exactly the done check's commit, for the last subtask, and ended done. A copy
     * turn is admitted only on the current subtask, and a subtask of a task copy cannot be completed while a turn of the group runs
     * ({@see TaskTurn::refuseSubtaskMoveWhileRunning()}), so this turn ended before the subtask was completed. The approval of a task with
     * no subtask reads it too (TaskWorkReview).
     */
    public static function reviewed(Task $group, Task $last, TaskCheck $check): bool
    {
        $turn = self::reviewerTurn($group);

        return $turn instanceof Operation && $turn->status === OperationStatus::Succeeded && ($turn->input['subtask'] ?? null) === $last->id
            && ($turn->input['candidate'] ?? null) === $check->commit_sha && ($turn->result['state'] ?? null) === AgentThreadState::Done->value
            && ($turn->result['ended'] ?? null) === true;
    }

    /** The newest turn of the group's reviewer thread, of any status, or null. */
    public static function reviewerTurn(Task $group): ?Operation
    {
        if ($group->reviewer_agent_thread_id === null) {
            return null;
        }

        return Operation::query()->where('kind', OperationKinds::TasksCopyTurn)->where('target_type', OperationKinds::TaskGroupTarget)
            ->where('target_id', $group->id)->where('input->thread_id', $group->reviewer_agent_thread_id)->orderByDesc('id')->first();
    }

    /**
     * The reviewer's pull request text on the check (docs/reference/tasks.md#the-reviewers-pull-request-text): only the text that the
     * reviewer thread's newest turn sent counts. A later reviewer turn that sent none leaves the check without text.
     *
     * @return array{title: string, body: string, operation_id: int}|null
     */
    public static function prText(Task $group, TaskCheck $check): ?array
    {
        $turn = self::reviewerTurn($group);
        if (! $turn instanceof Operation || $check->pr_text_operation_id !== $turn->id || $check->pr_title === null || $check->pr_body === null) {
            return null;
        }

        return ['title' => $check->pr_title, 'body' => $check->pr_body, 'operation_id' => $turn->id];
    }

    /** No earlier copy turn may still run, and no Operation of the group runs. */
    private function idle(Task $group): bool
    {
        try {
            TaskTurn::refuseUnended($group);
        } catch (ResourceOperationException) {
            return false;
        }

        return ! Operation::query()->where('target_type', OperationKinds::TaskGroupTarget)->where('target_id', $group->id)
            ->whereNotIn('status', OperationStatus::terminalValues())->exists();
    }
}
