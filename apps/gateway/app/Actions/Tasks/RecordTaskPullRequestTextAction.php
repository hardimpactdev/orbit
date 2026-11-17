<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\TaskCheckedCandidate;
use App\Domain\Tasks\TaskGroupDone;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskPublishIntent;
use App\Models\Instance;
use App\Models\Operation;
use App\Models\Task;
use App\Models\TaskCheck;
use Illuminate\Support\Facades\DB;

/**
 * The reviewer's pull request text, sent from its task copy during its turn (docs/reference/tasks.md#the-reviewers-pull-request-text).
 * The copy names its group; the running reviewer turn names the check. Orbit records the text on that check with the turn and opens
 * nothing: the publish of the done check takes it, the first to open the pull request and a republish to update the open one.
 */
final readonly class RecordTaskPullRequestTextAction
{
    public function __construct(private RequireTasksExtensionAction $extension, private TaskCheckedCandidate $candidates) {}

    /** What the 202 answer says the text does, for a group without its pull request and for one that has it. */
    public const string OpensWhenDone = 'when the group is done';

    public const string UpdatesOnRepublish = 'updates the pull request on the next publish of the done check';

    /** @return array{check: TaskCheck, opens: string} */
    public function execute(Instance $copy, string $title, string $body): array
    {
        $this->extension->execute();

        return DB::transaction(function () use ($copy, $title, $body): array {
            // The group's row lock orders this write with a turn's admission and a subtask's completion.
            $group = Task::topLevel()->with('project', 'taskable')->whereKey($copy->task_workspace_owner_id)->lockForUpdate()->first();
            if (! $group instanceof Task || $group->taskable_id !== $copy->id || in_array($group->status, TaskGroupStatus::endedStatuses(), true)) {
                throw new ResourceOperationException('tasks.group_closed', 'This task group has ended.', 409);
            }
            $turn = TaskGroupDone::reviewerTurn($group);
            $candidate = $turn?->input['candidate'] ?? null;
            if (! $turn instanceof Operation || $turn->status->terminal() || ! is_string($candidate)) {
                throw new ResourceOperationException('tasks.pr_text_not_reviewer_turn', 'Only a running reviewer turn on a checked commit sends the pull request text.', 409);
            }
            $check = TaskCheck::query()->where('task_id', $turn->input['subtask'] ?? null)->find($turn->input['check'] ?? null);
            if (! $check instanceof TaskCheck || $check->commit_sha !== $candidate || ! $this->candidates->eligible($group, $check)) {
                throw new ResourceOperationException('tasks.commit_not_checked', 'The commit under review is no longer the checked candidate of the current subtask.', 422);
            }
            TaskPublishIntent::refuseHeld($group->project);
            $check->update(['pr_title' => $title, 'pr_body' => $body, 'pr_text_operation_id' => $turn->id]);

            return ['check' => $check, 'opens' => $group->pr_url === null ? self::OpensWhenDone : self::UpdatesOnRepublish];
        });
    }
}
