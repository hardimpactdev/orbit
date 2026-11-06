<?php

declare(strict_types=1);

namespace App\Actions\Projects;

use App\Actions\Operations\StartOperationAction;
use App\Domain\Nodes\SwampNode;
use App\Domain\Operations\OperationIdempotency;
use App\Domain\Operations\OperationKinds;
use App\Domain\Operations\OperationStore;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskPullRequestEvidence;
use App\Models\Operation;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Support\Str;

/**
 * Admits the merge of a pull request that no Task group watches. The Gateway reads GitHub with read-only tokens and records the intent
 * with the vault key name of the write token. The step asks the Gateway for the token when it runs, and Swamp calls GitHub with it.
 * See docs/cli/project/pr-merge.mmd.
 */
final readonly class StartProjectPullRequestMergeAction
{
    public function __construct(private StartOperationAction $operations, private OperationIdempotency $idempotency, private TaskPullRequestEvidence $evidence) {}

    public function execute(Project $project, int $number, string $head, string $caller, string $request): Operation
    {
        if (($existing = $this->idempotency->replay(OperationKinds::ProjectPrMerge)) !== null) {
            return $existing;
        }
        if (! $project->reviewsAndMerges()) {
            throw new ResourceOperationException('project.review_and_merge_disabled', 'This Project does not take part in review and merge.', 409);
        }
        $watched = Task::topLevel()->where('project_id', $project->id)->where('watched_pr_number', $number)->whereNull('watched_pr_completion')
            ->whereNotIn('status', array_map(static fn (TaskGroupStatus $status): string => $status->value, TaskGroupStatus::endedStatuses()))->exists();
        if ($watched) {
            throw new ResourceOperationException('project.pr_in_task_group', 'A Task group watches this pull request; merge it with tasks:pr-merge.', 409);
        }
        $target = $this->evidence->outside($project, $number, $head);
        // Only the vault key name is admitted. The step mints its token when it runs ({@see TaskGitHubTokens}).
        $secrets = ['write_token' => ['vault' => 'orbit-control-inputs', 'key' => 'pr-merge-write-'.Str::uuid()]];

        return $this->operations->execute(OperationKinds::ProjectPrMerge, 'project', $project->id, SwampNode::id(), [
            'project_id' => $project->id, 'repository' => $target->repository->owner.'/'.$target->repository->name, 'pr_number' => $number, 'head_sha' => $head,
            'node_id' => SwampNode::id(), 'execution_secrets' => $secrets,
        ], $caller, $request, lockKey: OperationStore::lockKey('project', $project->id));
    }
}
