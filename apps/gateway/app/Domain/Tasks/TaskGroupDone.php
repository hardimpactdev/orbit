<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Domain\Operations\OperationKinds;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;
use App\Models\Task;
use App\Models\TaskCheck;
use Illuminate\Support\Carbon;

/**
 * Whether a task copy's work is done, from Orbit's own records alone (docs/reference/tasks.md#when-the-work-is-done). A copy reviewer
 * turn records no verdict: the caller's completion of the last subtask after a reviewer turn on exactly the done check is the approval.
 */
final readonly class TaskGroupDone
{
    public function __construct(private TaskCheckedCandidate $candidates) {}

    /** The done check, or null while the work is not done. */
    public function check(Task $group): ?TaskCheck
    {
        $group->loadMissing('project', 'taskable', 'tasks');
        if ($group->parent_id !== null || $group->orchestration !== TaskOrchestration::External || $group->pr_url !== null
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

        return $this->reviewed($group, $last, $newest) && $this->idle($group) ? $newest : null;
    }

    /**
     * The newest turn of the group's reviewer thread reviewed exactly the done check's commit, for the last subtask, and ended done.
     * A copy turn is admitted only on the current subtask, so its subtask was open then; the subtask's last change, its completion,
     * is not earlier than the turn's end.
     */
    private function reviewed(Task $group, Task $last, TaskCheck $check): bool
    {
        if ($group->reviewer_agent_thread_id === null) {
            return false;
        }
        $turn = Operation::query()->where('kind', OperationKinds::TasksCopyTurn)->where('target_type', OperationKinds::TaskGroupTarget)
            ->where('target_id', $group->id)->where('input->thread_id', $group->reviewer_agent_thread_id)->orderByDesc('id')->first();
        $finished = $turn?->finished_at;
        $completed = $last->updated_at;

        return $turn instanceof Operation && $turn->status === OperationStatus::Succeeded && ($turn->input['subtask'] ?? null) === $last->id
            && ($turn->input['candidate'] ?? null) === $check->commit_sha && ($turn->result['state'] ?? null) === AgentThreadState::Done->value
            && ($turn->result['ended'] ?? null) === true && $finished instanceof Carbon && $completed instanceof Carbon && $finished->lessThanOrEqualTo($completed);
    }

    /** No earlier copy turn may still run, and no Operation of the group runs. */
    private function idle(Task $group): bool
    {
        try {
            TaskCopyTurn::refuseUnended($group);
        } catch (ResourceOperationException) {
            return false;
        }

        return ! Operation::query()->where('target_type', OperationKinds::TaskGroupTarget)->where('target_id', $group->id)
            ->whereNotIn('status', OperationStatus::terminalValues())->exists();
    }
}
