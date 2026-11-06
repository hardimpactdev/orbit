<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Data\Tasks\TaskTurnData;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\TaskCheckedCandidate;
use App\Domain\Tasks\TaskCheckKind;
use App\Domain\Tasks\TaskPullRequestEvidence;
use App\Domain\Tasks\TaskReviewRoundPrompt;
use App\Domain\Tasks\TaskStatus;
use App\Models\Operation;
use App\Models\Task;
use App\Models\TaskCheck;
use App\Models\TaskReviewRound;
use Illuminate\Support\Facades\DB;

/** Admits one review round of the automatic mode: a fresh reviewer turn on the exact head of the pull request. */
final readonly class StartTaskReviewRoundAction
{
    public function __construct(private StartTaskTurnAction $turns, private TaskCheckedCandidate $candidates, private TaskPullRequestEvidence $evidence) {}

    public function execute(Task $group, string $head, string $caller, string $request): Operation
    {
        return DB::transaction(function () use ($group, $head, $caller, $request): Operation {
            $this->evidence->round($group, $head);
            $round = TaskReviewRound::query()->where('task_group_id', $group->id)->count() + 1;
            if ($round > TaskReviewRound::Limit) {
                throw new ResourceOperationException('tasks_pr.rounds_exhausted', 'The group used its review rounds.', 409);
            }
            $subtask = $group->tasks()->whereNotIn('status', [TaskStatus::Completed, TaskStatus::Failed, TaskStatus::Cancelled])->orderBy('position')->orderBy('id')->first();
            $check = $subtask === null ? null : TaskCheck::query()->where('task_id', $subtask->id)->where('kind', TaskCheckKind::Handoff->value)
                ->where('commit_sha', $head)->orderByDesc('id')->get()->first(fn (TaskCheck $check): bool => $this->candidates->eligible($group, $check));
            if ($subtask === null || ! $check instanceof TaskCheck) {
                throw new ResourceOperationException('tasks_pr.no_checked_candidate', 'The head is not a passed handoff candidate of the current subtask.', 409);
            }
            $operation = $this->turns->execute($group, new TaskTurnData($subtask->id, 'reviewer', TaskReviewRoundPrompt::for($head, $round), 'new', null, $check->id), $caller, $request);
            if ($operation->wasRecentlyCreated) {
                TaskReviewRound::query()->create(['task_group_id' => $group->id, 'head_sha' => $head, 'round' => $round, 'operation_id' => $operation->id]);
            }

            return $operation;
        });
    }
}
