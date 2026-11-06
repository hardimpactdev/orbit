<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Data\Tasks\StartTaskCheckData;
use App\Data\Tasks\StartTaskPublishData;
use App\Data\Tasks\TaskTurnData;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\TaskCheckKind;
use App\Domain\Tasks\TaskReviewFixPrompt;
use App\Domain\Tasks\TaskStatus;
use App\Models\Operation;
use App\Models\Task;
use App\Models\TaskReviewRound;

/**
 * The three admissions of the fix after the findings of a review round: the author turn in the author's thread, the
 * handoff check, and the push of the checked candidate to the branch of the pull request. The gate check admits one
 * at a time and reads the end of the one before.
 */
final readonly class StartTaskReviewFixAction
{
    public function __construct(private StartTaskTurnAction $turns, private StartTaskCheckAction $checks, private StartTaskPublishAction $publish) {}

    public function turn(Task $group, TaskReviewRound $round, string $caller, string $request): Operation
    {
        $subtask = $this->subtask($group);
        $thread = $subtask->implementer_agent_thread_id ?? throw new ResourceOperationException('tasks_pr.no_author_thread', 'The current subtask has no author thread.', 409);

        return $this->record($this->turns->execute($group, new TaskTurnData($subtask->id, 'implementer', TaskReviewFixPrompt::for($round), (string) $thread), $caller, $request), $round, 'fix_turn_operation_id');
    }

    public function check(Task $group, TaskReviewRound $round, string $caller, string $request): Operation
    {
        return $this->record($this->checks->execute($group, new StartTaskCheckData($this->subtask($group)->id, TaskCheckKind::Handoff), $caller, $request), $round, 'fix_check_operation_id');
    }

    public function publish(Task $group, TaskReviewRound $round, string $caller, string $request): Operation
    {
        $check = Operation::query()->find($round->fix_check_operation_id)?->result['check_id'] ?? null;
        if (! is_int($check)) {
            throw new ResourceOperationException('tasks_pr.no_checked_candidate', 'The fix has no passed handoff check.', 409);
        }

        return $this->record($this->publish->execute($group, new StartTaskPublishData($check), $caller, $request), $round, 'fix_publish_operation_id');
    }

    private function subtask(Task $group): Task
    {
        return $group->tasks()->whereNotIn('status', [TaskStatus::Completed, TaskStatus::Failed, TaskStatus::Cancelled])->orderBy('position')->orderBy('id')->first()
            ?? throw new ResourceOperationException('tasks_pr.no_current_subtask', 'The group has no open subtask.', 409);
    }

    private function record(Operation $operation, TaskReviewRound $round, string $column): Operation
    {
        if ($operation->wasRecentlyCreated) {
            $round->update([$column => $operation->id]);
        }

        return $operation;
    }
}
