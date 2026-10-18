<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Domain\GitHub\GitHubReviewEvent;
use App\Domain\GitHub\GitHubReviewState;
use App\Domain\GitHub\RequiredCheckState;
use App\Models\Activity;
use App\Models\Instance;
use App\Models\Task;
use App\Models\TaskCheck;
use App\Models\TaskComment;
use App\Models\TaskReviewedCommit;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

final readonly class TaskPublication
{
    public function __construct(
        private TaskSubtaskRunner $runner,
        private TaskPullRequestWatcher $pullRequestWatcher,
        private TaskPullRequestPublisher $publisher,
        private TaskBroadcasts $broadcasts,
        private TaskBaseBranchFetcher $bases,
        private TaskTurnFetcher $turnFetcher,
        private TaskPullRequestMerger $merger,
    ) {}

    /**
     * Pushes the stored commit, then opens the pull request when this approval is the one that publishes it.
     * The open pushes that commit again. The refspec names the commit, never HEAD, and is not a force push.
     * When the pull request URL is already stored, the push updates that pull request and Orbit does not open another (ADR 0164).
     * A failed push or open leaves the subtask in review and keeps commit_sha.
     */
    public function publishApprovedCommit(TaskScheduler $scheduler, Task $group, Task $task, TaskComment $receipt): void
    {
        app(TaskWorkspaceLease::class)->run($group, 'publication', 'approval:'.$receipt->id, fn () => $this->publishApprovedCommitWithWorkspace($scheduler, $group, $task, $receipt), taskId: $task->id);
    }

    private function publishApprovedCommitWithWorkspace(TaskScheduler $scheduler, Task $group, Task $task, TaskComment $receipt): void
    {
        $commit = $receipt->commit_sha;
        if (! is_string($commit) || $commit === '') {
            $scheduler->recordCommunicationFailure($task, $group, 'Orbit could not commit the approved subtask.');

            return;
        }
        if ($task->isFinalReview()) {
            $this->publishReviewedHead($scheduler, $group, $task, $receipt, $commit);

            return;
        }
        if ($group->reviewsBeforePush()) {
            // ADR 0203: the approved commit stays in the workspace until a final review approves the whole branch.
            $this->finishApproval($scheduler, $group, $task, $receipt);

            return;
        }
        if (! $scheduler->retryIsDue($this->publicationBackoffKey($task), 'approved publication')) {
            return;
        }
        if (! $this->pullRequestStillOpen($scheduler, $group, $task, $commit)) {
            return;
        }
        try {
            $this->publisher->push($group, $commit);
        } catch (TaskPullRequestException $exception) {
            // A task VM's push runs in a queued job. A later tick sees it done.
            if (! $exception->pending) {
                $this->failPublication($scheduler, $group, $task, $exception->getMessage());
            }

            return;
        }

        if ($task->opensPullRequest()) {
            $pullRequest = TaskTurnPullRequest::fromArray($receipt->pull_request);
            if (! $pullRequest instanceof TaskTurnPullRequest) {
                $scheduler->recordCommunicationFailure($task, $group, 'The approval of the last subtask needs --pr-summary, --pr-change, and --pr-breaking.');

                return;
            }
            try {
                $url = $this->publisher->publish($group, TaskPullRequestDescription::render($pullRequest, $group->tasks()->whereNotIn('status', [TaskStatus::Cancelled, TaskStatus::Failed])->count(), $group->project->taskCheckCommand()), $commit);
            } catch (TaskPullRequestException $exception) {
                if (! $exception->pending) {
                    $this->failPublication($scheduler, $group, $task, $exception->getMessage());
                }

                return;
            }
            $group->update(['pr_url' => $url]);
        }

        $this->finishApproval($scheduler, $group, $task, $receipt);
    }

    /** Marks a published, or held, approval handled and completes the subtask. */
    private function finishApproval(TaskScheduler $scheduler, Task $group, Task $task, TaskComment $receipt): void
    {
        $scheduler->rememberBackoff($this->publicationBackoffKey($task), null, 'approved publication');
        TaskQuestions::answerPending($task, $receipt);
        $task->update([
            'review_handled_comment_id' => $receipt->id,
            'communication_failures' => 0,
        ]);
        $this->clearPublicationAssistance($task, $group);
        $scheduler->acceptReview($task);
    }

    /**
     * ADR 0203: records the HEAD the final review approved as fully reviewed, then pushes it, opens the pull
     * request when none exists, and approves an incoming pull request on GitHub. A head that is already the
     * incoming pull request head is not pushed. A failed step keeps the final review open and retries it.
     */
    private function publishReviewedHead(TaskScheduler $scheduler, Task $group, Task $task, TaskComment $receipt, string $commit): void
    {
        if (! $scheduler->retryIsDue($this->publicationBackoffKey($task), 'approved publication')) {
            return;
        }
        $incoming = $group->reviewsIncomingPullRequest();
        $pullRequestHead = $incoming && is_string($task->fixup_head_sha) && $task->fixup_head_sha === $commit;
        $reviewed = TaskReviewedCommit::query()->firstOrCreate(
            ['task_id' => $group->id, 'sha' => $commit],
            ['source' => $pullRequestHead ? TaskReviewedCommitSource::PullRequestReview : TaskReviewedCommitSource::OrbitPush, 'review_task_id' => $task->id],
        );
        if ($reviewed->pushed_at === null && $task->fixup_problem !== TaskFinalReview::BaseMergeReview) {
            try {
                if (! $task->checks()->where('kind', TaskCheckKind::BaseMerge)->where('task_comment_id', $receipt->id)->exists()) {
                    $this->turnFetcher->fetch($group);
                }
                $merge = $this->prepareBaseMerge($scheduler, $group, $task, $receipt);
                if ($merge === 'conflict') {
                    $task->update(['status' => TaskStatus::Completed, 'settled_at' => now(), 'review_handled_comment_id' => $receipt->id]);
                    $group->update(['status' => TaskGroupStatus::Settling]);
                    $this->appendAgentBaseMerge($scheduler, $group->fresh(['tasks']) ?? $group, (string) $group->project->default_branch);

                    return;
                }
                if ($merge === 'waiting') {
                    return;
                }
                $snapshot = $scheduler->workspaceSnapshot($group);
                if ($snapshot->head !== $commit) {
                    if ($this->bases->overlappingPaths($group, $commit, $snapshot->head) !== []) {
                        $this->appendDeltaReview($scheduler, $group, $task, $receipt);

                        return;
                    }
                    $commit = $snapshot->head;
                    $pullRequestHead = false;
                    $reviewed = TaskReviewedCommit::query()->firstOrCreate(
                        ['task_id' => $group->id, 'sha' => $commit],
                        ['source' => TaskReviewedCommitSource::BaseMerge, 'review_task_id' => $task->id],
                    );
                }
            } catch (TaskCheckException|TaskPullRequestException $exception) {
                $this->failPublication($scheduler, $group, $task, $exception->getMessage());

                return;
            }
        }
        if (! $pullRequestHead && $reviewed->pushed_at === null) {
            if (! $this->pullRequestStillOpen($scheduler, $group, $task, $commit)) {
                return;
            }
            try {
                $this->publisher->push($group, $commit);
                if ($task->opensPullRequest()) {
                    $pullRequest = TaskTurnPullRequest::fromArray($receipt->pull_request);
                    if (! $pullRequest instanceof TaskTurnPullRequest) {
                        $scheduler->recordCommunicationFailure($task, $group, 'The final review that opens the pull request needs --pr-summary, --pr-change, and --pr-breaking.');

                        return;
                    }
                    $delivered = $group->tasks()->whereNotIn('status', [TaskStatus::Cancelled, TaskStatus::Failed])->withoutFinalReviews()->count();
                    $url = $this->publisher->publish($group, TaskPullRequestDescription::render($pullRequest, $delivered, $group->project->taskCheckCommand()), $commit);
                    $group->update(['pr_url' => $url]);
                }
            } catch (TaskPullRequestException $exception) {
                if ($exception->pending) {
                    return;
                }
                $moved = $incoming ? $this->pullRequestWatcher->health($group)?->headSha : null;
                if (is_string($moved) && $moved !== $commit && ! TaskFinalReview::isReviewed($group, $moved)) {
                    $this->mergeAuthorPush($scheduler, $group, $task, $receipt, $moved);

                    return;
                }
                $this->failPublication($scheduler, $group, $task, $exception->getMessage());

                return;
            }
            $reviewed->update(['pushed_at' => now()]);
            TaskFinalReview::log($group, 'reviewed commit pushed', ['subtask_id' => $task->id, 'commit_sha' => $commit, 'pull_request' => $group->pr_url]);
        }
        if ($incoming && $reviewed->github_review_id === null) {
            try {
                $reviewId = $this->merger->review($group, $commit, GitHubReviewEvent::Approve, $this->approvalReviewBody($receipt, $commit));
            } catch (TaskPullRequestException $exception) {
                $this->failPublication($scheduler, $group, $task, $exception->getMessage());

                return;
            }
            $reviewed->update(['github_review_id' => $reviewId]);
            TaskFinalReview::log($group, 'pull request approved', ['subtask_id' => $task->id, 'commit_sha' => $commit, 'review_id' => $reviewId, 'pull_request' => $group->pr_url]);
        }

        $this->finishApproval($scheduler, $group, $task, $receipt);
    }

    private function approvalReviewBody(TaskComment $receipt, string $commit): string
    {
        return "Orbit reviewed the whole pull request at {$commit} and approves it.\n\n".mb_substr(trim($receipt->body), 0, 60000);
    }

    /**
     * The author pushed to an incoming pull request while Orbit's reviewed work waited, so Orbit's push is no
     * longer a fast-forward. Orbit completes the final review and starts a fixup that merges the author's commits.
     */
    private function mergeAuthorPush(TaskScheduler $scheduler, Task $group, Task $task, TaskComment $receipt, string $head): void
    {
        $branch = TaskRemoteBranch::for($group);
        $group = $group->fresh(['project', 'tasks', 'taskable']) ?? $group;
        $count = TaskFinalReview::fixupsInWindow($group->tasks);
        if ($count >= TaskFinalReview::FixupLimit) {
            $scheduler->stopAtFinalReviewCap($group, $task, $receipt, $count);

            return;
        }
        $fixup = $scheduler->replaceFinalReviewWithFixup($group, $task, $receipt, 'Merge origin/'.$branch,
            'The pull request author pushed '.$head.' to '.$branch.' after Orbit reviewed its own work, so Orbit cannot push without forcing. '
            .'Merge origin/'.$branch.' into the task branch and resolve any conflicts. Do not rebase and do not force-push.');
        if (! $fixup instanceof Task) {
            return;
        }
        TaskFinalReview::log($group, 'pull request branch moved', ['subtask_id' => $task->id, 'head_sha' => $head, 'fixup_id' => $fixup->id]);
        $this->runner->recordSubtaskStart($fixup);
        $this->runner->assignImplementer($scheduler, $fixup);
    }

    /**
     * ADR 0203: merges the pull request through the App when its head is one Orbit fully reviewed, the merge
     * check passed on that exact head, GitHub reports it mergeable, and no trusted account requests changes.
     * A head Orbit did not review is reviewed again on an incoming pull request, and asks for assistance on an
     * Orbit task branch. Each result is stored on the task, and each change of result is recorded in Activity.
     */
    public function mergeWhenReady(TaskScheduler $scheduler, Task $group, TaskPullRequestHealth $health, ?TaskReviewObservation $reviews): void
    {
        app(TaskWorkspaceLease::class)->run($group, 'publication', 'merge-ready:'.$group->id, fn () => $this->mergeWhenReadyWithWorkspace($scheduler, $group, $health, $reviews));
    }

    private function mergeWhenReadyWithWorkspace(TaskScheduler $scheduler, Task $group, TaskPullRequestHealth $health, ?TaskReviewObservation $reviews): void
    {
        $group = $group->fresh(['project', 'tasks', 'taskable']) ?? $group;
        $head = $health->headSha;
        if ($group->assistance_requested && TaskFinalReview::isUnreviewedHeadReason($group->assistance_reason)
            && is_string($head) && TaskFinalReview::isReviewed($group, $head)) {
            // The head is reviewed again, so the request that held the merge no longer applies.
            TaskAssistance::apply($group);
        }
        if (! in_array($group->status, TaskGroupStatus::awaitingCompletion(), true) || $group->assistance_requested
            || $group->tasks->contains(static fn (Task $task): bool => in_array($task->status, [TaskStatus::Todo, TaskStatus::Running, TaskStatus::Reviewing], true))
            || TaskFinalReview::hasUnreviewedWork($group)) {
            return;
        }
        if (! is_string($head) || $head === '') {
            $this->recordMerge($group, TaskMergeStatus::Waiting, 'GitHub did not report the pull request head.');

            return;
        }
        if (! TaskFinalReview::isReviewed($group, $head)) {
            if ($group->reviewsIncomingPullRequest()) {
                $this->recordMerge($group, TaskMergeStatus::Waiting, 'Orbit reviews the new head '.$head.'.');
                $scheduler->beginFinalReview($group, $head);

                return;
            }
            $reason = TaskFinalReview::UnreviewedHeadPrefix.'someone other than Orbit pushed '.$head.' to '.TaskRemoteBranch::for($group).'. Orbit does not merge it. Append a subtask so Orbit reviews and pushes the branch again, or complete the task.';
            $this->recordMerge($group, TaskMergeStatus::Refused, $reason);
            TaskAssistance::apply($group, AssistanceKind::Failure, null, $reason);

            return;
        }
        $default = $group->project->default_branch;
        if (! is_string($health->baseRef) || $health->baseRef !== $default) {
            $this->recordMerge($group, TaskMergeStatus::Refused, 'The pull request base is '.($health->baseRef ?? 'unknown').', not the default branch '.($default ?? 'unknown').'. Orbit merges only into the default branch it reviewed against.');

            return;
        }
        if ($health->conflicts || $health->mergeable !== true) {
            $this->recordMerge($group, TaskMergeStatus::Waiting, 'GitHub has not reported '.$group->pr_url.' mergeable.');

            return;
        }
        // Reconciliation can finish the detached gate before this evaluation. Continue its
        // exact workspace result before the GitHub throttle or any merge of a newer main.
        $finishedMerge = TaskCheck::query()->whereHas('task', static fn ($query) => $query->where('parent_id', $group->id))
            ->where('kind', TaskCheckKind::BaseMerge)->latest('id')->first();
        if ($finishedMerge instanceof TaskCheck && $finishedMerge->status !== TaskCheckStatus::Running) {
            $current = $scheduler->workspaceSnapshot($group);
            $finishedHead = $finishedMerge->head_after ?? $finishedMerge->head_before;
            $finishedTree = $finishedMerge->tree_after ?? $finishedMerge->tree_before;
            if ($finishedHead === $current->head && $finishedTree === $current->tree
                && ! TaskReviewedCommit::query()->where('task_id', $group->id)->where('sha', $current->head)->whereNotNull('pushed_at')->exists()) {
                $this->appendBaseMerge($scheduler, $group, (string) $default);

                return;
            }
        }
        // One full evaluation per head and minute. The pull request read above already runs every tick.
        if (! Cache::add('tasks.merge-gate.'.$group->id.'.'.$head, true, 60)) {
            return;
        }
        $check = $group->project->mergeCheckName() ?? '';
        $state = $this->merger->requiredCheck($group, $head, $check);
        if ($state !== RequiredCheckState::Passed) {
            $this->recordMerge($group, $state === RequiredCheckState::Failed ? TaskMergeStatus::Refused : TaskMergeStatus::Waiting, match ($state) {
                RequiredCheckState::Pending => 'Check '.$check.' is still running on '.$head.'.',
                RequiredCheckState::Missing => 'Check '.$check.' has no run on '.$head.' yet.',
                RequiredCheckState::Failed => 'Check '.$check.' did not pass on '.$head.', or a run of that name came from an App other than github-actions.',
                default => 'Orbit could not read check '.$check.' on '.$head.'.',
            });

            return;
        }
        $blocked = $this->trustedChangesRequested($reviews);
        if ($blocked !== null) {
            $this->recordMerge($group, $reviews?->status === TaskReviewReadStatus::Complete ? TaskMergeStatus::Refused : TaskMergeStatus::Waiting, $blocked);

            return;
        }
        try {
            $result = $this->merger->merge($group, $head);
        } catch (TaskPullRequestException $exception) {
            $this->recordMerge($group, TaskMergeStatus::Waiting, $exception->getMessage());

            return;
        }
        if (! $result->merged) {
            $this->recordMerge($group, TaskMergeStatus::Refused, 'GitHub refused the merge of '.$head.' ('.$result->status.'): '.($result->message !== '' ? $result->message : 'no message').'.');
            if ($health->behind) {
                // A branch protection rule can require an up-to-date branch. Orbit merges the base itself.
                $this->appendBaseMerge($scheduler, $group, (string) $default);
            }

            return;
        }
        $group->update(['merge_status' => TaskMergeStatus::Merged, 'merge_reason' => null, 'merged_sha' => $result->sha]);
        TaskFinalReview::log($group, 'pull request merged', ['pull_request' => $group->pr_url, 'head_sha' => $head, 'merge_sha' => $result->sha]);
        $this->broadcasts->groupChanged($group->id);
    }

    /**
     * GitHub refused a behind branch. Merge locally and gate it; only a Git conflict needs a merge agent.
     * A non-overlapping merge inherits the reviewed first parent's evidence; an overlap gets a delta review.
     * It never asks GitHub to update the branch.
     */
    public function appendBaseMerge(TaskScheduler $scheduler, Task $group, string $base): void
    {
        app(TaskWorkspaceLease::class)->run($group, 'main_merge', 'base-merge:'.$group->id, fn () => $this->appendBaseMergeWithWorkspace($scheduler, $group, $base));
    }

    private function appendBaseMergeWithWorkspace(TaskScheduler $scheduler, Task $group, string $base): void
    {
        TaskExecutionHold::run($group, function () use ($scheduler, $group, $base): void {
            $task = $group->tasks()->whereHas('checks', static fn ($query) => $query
                ->where('kind', TaskCheckKind::BaseMerge)->where('status', TaskCheckStatus::Running))->reorder('position', 'desc')->first()
                ?? $group->tasks()->where('status', TaskStatus::Completed)->where('type', TaskType::FinalReview)
                    ->whereIn('id', TaskReviewedCommit::query()->where('task_id', $group->id)->select('review_task_id'))->reorder('position', 'desc')->first()
                ?? $group->tasks()->where('status', TaskStatus::Completed)->reorder('position', 'desc')->first();
            if (! $task instanceof Task) {
                return;
            }
            try {
                $this->turnFetcher->fetch($group);
                $current = $scheduler->workspaceSnapshot($group);
                $pending = $task->checks()->where('kind', TaskCheckKind::BaseMerge)
                    ->where(function ($query) use ($current): void {
                        $query->where('status', TaskCheckStatus::Running)->orWhere('head_after', $current->head);
                    })->exists();
                if (! TaskFinalReview::isReviewed($group, $current->head)
                    && (! $pending || (! TaskReviewedCommit::query()->where('task_id', $group->id)->where('review_task_id', $task->id)->exists()
                        && (! is_string($current->parent) || ! TaskFinalReview::isReviewed($group, $current->parent))))) {
                    throw new TaskPullRequestException('The workspace head has no review for a mechanical main merge.');
                }
                $gated = TaskReviewedCommit::query()->where('task_id', $group->id)->where('sha', $current->head)
                    ->where('source', TaskReviewedCommitSource::BaseMerge)->whereNull('pushed_at')->exists();
                $merge = $gated ? 'passed' : $this->prepareBaseMerge($scheduler, $group, $task, refreshPassed: true);
                if ($merge === 'conflict') {
                    $this->appendAgentBaseMerge($scheduler, $group, $base);

                    return;
                }
                if ($merge === 'waiting') {
                    return;
                }
                $snapshot = $scheduler->workspaceSnapshot($group);
                $ancestor = TaskReviewedCommit::query()->where('task_id', $group->id)->where('review_task_id', $task->id)
                    ->where('source', '!=', TaskReviewedCommitSource::BaseMerge)->latest('id')->first()
                    ?? TaskReviewedCommit::query()->where('task_id', $group->id)->where('sha', $snapshot->parent)->first();
                $reviewed = TaskReviewedCommit::query()->where('task_id', $group->id)->where('sha', $snapshot->head)->first();
                if (! $reviewed instanceof TaskReviewedCommit) {
                    if ($ancestor instanceof TaskReviewedCommit && $this->bases->overlappingPaths($group, $ancestor->sha, $snapshot->head) !== []) {
                        $this->appendDeltaReview($scheduler, $group, $task);

                        return;
                    }
                    $parent = $ancestor;
                    if (! $parent instanceof TaskReviewedCommit) {
                        throw new TaskPullRequestException('The main merge does not descend directly from a reviewed head.');
                    }
                    $reviewed = TaskReviewedCommit::query()->create(['task_id' => $group->id, 'sha' => $snapshot->head,
                        'source' => TaskReviewedCommitSource::BaseMerge, 'review_task_id' => $parent->review_task_id]);
                }
                if ($reviewed->pushed_at === null) {
                    if (! $this->pullRequestStillOpen($scheduler, $group, $task, $snapshot->head)) {
                        return;
                    }
                    $this->publisher->push($group, $snapshot->head);
                    $reviewed->update(['pushed_at' => now()]);
                    TaskFinalReview::log($group, 'base merge pushed', ['commit_sha' => $snapshot->head]);
                }
            } catch (TaskCheckException|TaskPullRequestException $exception) {
                $this->recordMerge($group, TaskMergeStatus::Waiting, $exception->getMessage());
                if ($exception instanceof TaskCheckException) {
                    $scheduler->requestAssistance($task, $group, $exception->getMessage());
                }
            }
        });
    }

    /** Called under the comment transaction's group/task locks. Queue only a gate retry, not a new review. */
    public function queueBaseMergeRetry(Task $group, Task $task, TaskComment $resolution): bool
    {
        $request = $task->status === TaskStatus::Completed ? $group : $task;
        if (! $task->isFinalReview()
            || ! in_array($task->status, [TaskStatus::Running, TaskStatus::Reviewing, TaskStatus::Completed], true)
            || $request->assistance_kind !== AssistanceKind::Failure || $group->assistance_kind === AssistanceKind::Direction) {
            return false;
        }
        $check = $task->checks()->where('kind', TaskCheckKind::BaseMerge)->latest('id')->first();
        if (! $check instanceof TaskCheck || $check->status !== TaskCheckStatus::Failed || $check->pid === 0
            || $check->failed_step === 'merge_conflict' || $check->head_after !== $check->head_before || $check->tree_after !== $check->tree_before) {
            return false;
        }
        if (in_array($task->status, [TaskStatus::Reviewing, TaskStatus::Completed], true) && $check->task_comment_id !== null) {
            $approval = $task->comments()->whereKey($check->task_comment_id)->where('type', TaskCommentType::Approved)
                ->where('author', TaskThreadRole::Reviewer->value)->whereNotNull('receipt_hash')->whereNotNull('commit_sha')
                ->where('review_attempt', $task->review_attempt)->first();
            if (! $approval instanceof TaskComment || ($task->status === TaskStatus::Reviewing && $approval->id === $task->review_handled_comment_id)) {
                return false;
            }
        } elseif ($check->task_comment_id !== null) {
            return false;
        }

        // A pending row after this failed gate is durable retry intent. Keep the failed row and approval untouched.
        $pending = TaskCheck::query()->create(['task_id' => $task->id, 'task_comment_id' => $check->task_comment_id, 'kind' => TaskCheckKind::BaseMerge,
            'status' => TaskCheckStatus::Running, 'pid' => 0, 'process_started' => '',
            'head_before' => $check->head_before, 'tree_before' => $check->tree_before, 'started_at' => now()]);
        app(TaskWorkspaceLease::class)->recordPendingCheck($group, $pending);
        $resolution->update(['review_attempt' => $task->review_attempt]);
        TaskQuestions::attachResolution($task, $resolution);
        $reason = $request->assistance_reason;
        TaskAssistance::apply($task, source: $resolution);
        $task->update(['communication_failures' => 0, 'resolution_delivered_comment_id' => $resolution->id]);
        if ($group->assistance_reason === $reason && ! $group->tasks()->where('assistance_requested', true)->exists()) {
            TaskAssistance::apply($group, source: $resolution);
        }
        Cache::forget($this->publicationBackoffKey($task));

        return true;
    }

    /**
     * Runs Git and one stored gate, never an agent. The pending row also keeps a merge whose reply was lost
     * subject to the gate. A passed row applies only to the exact current head and tree.
     *
     * @return 'passed'|'waiting'|'conflict'
     */
    public function prepareBaseMerge(TaskScheduler $scheduler, Task $group, Task $task, ?TaskComment $receipt = null, bool $refreshPassed = false): string
    {
        return app(TaskWorkspaceLease::class)->run($group, 'main_merge', 'prepare-merge:'.$task->id, fn () => $this->prepareBaseMergeWithWorkspace($scheduler, $group, $task, $receipt, $refreshPassed), taskId: $task->id);
    }

    /** @return 'passed'|'waiting'|'conflict' */
    private function prepareBaseMergeWithWorkspace(TaskScheduler $scheduler, Task $group, Task $task, ?TaskComment $receipt = null, bool $refreshPassed = false): string
    {
        $query = $task->checks()->where('kind', TaskCheckKind::BaseMerge);
        if (! $refreshPassed) {
            $query->where('task_comment_id', $receipt?->id);
        }
        $check = $query->latest('id')->first();
        $instance = $group->taskable;
        if (! $instance instanceof Instance) {
            throw new TaskCheckException('The task workspace is unavailable.');
        }
        $snapshot = $scheduler->workspaceSnapshot($group);
        if ($check instanceof TaskCheck && $check->status === TaskCheckStatus::Running && $check->pid > 0) {
            $reading = app(TaskWorkspaceLease::class)->readCheck($instance, $check->process());
            if ($reading->state === 'running') {
                return 'waiting';
            }
            $this->runner->recordReading($check, $reading);
            $snapshot = $scheduler->workspaceSnapshot($group);
            if ($check->status === TaskCheckStatus::Passed && $check->head_after === $snapshot->head && $check->tree_after === $snapshot->tree) {
                return 'passed';
            }
        }
        // A resolution reserves a fresh pending check after the failed gate on the same head/tree.
        // This survives a Gateway restart before the tick starts its process, without re-merging main.
        $repairGate = $check instanceof TaskCheck && $check->status === TaskCheckStatus::Running && $check->pid === 0
            && $task->checks()->where('kind', TaskCheckKind::BaseMerge)->where('task_comment_id', $check->task_comment_id)
                ->where('id', '<', $check->id)->latest('id')->first()?->status === TaskCheckStatus::Failed;
        $retryGate = $repairGate || ($check instanceof TaskCheck && $check->status === TaskCheckStatus::Lost);
        if ($repairGate && ($check->head_before !== $snapshot->head || $check->tree_before !== $snapshot->tree)) {
            throw new TaskCheckException('The workspace changed before retrying the main merge Project check #'.$check->id.'. The retained approval cannot publish changed work.');
        }
        if ($check instanceof TaskCheck && $check->status === TaskCheckStatus::Cancelled) {
            throw new TaskCheckException('The main merge gate was cancelled. Resolve the check before continuing.');
        }
        if (! $repairGate && $retryGate && $task->checks()->where('kind', TaskCheckKind::BaseMerge)->where('status', TaskCheckStatus::Lost)->count() >= 2) {
            throw new TaskCheckException('The main merge gate stopped twice without a result.');
        }
        if ($check instanceof TaskCheck && $check->status !== TaskCheckStatus::Running) {
            if ($check->status !== TaskCheckStatus::Passed && ! $retryGate) {
                if ($check->failed_step === 'merge_conflict') {
                    return 'conflict';
                }
                if ($check->failed_step === 'gate') {
                    $task->update(['status' => $receipt instanceof TaskComment || $task->status === TaskStatus::Completed ? TaskStatus::Completed : TaskStatus::Cancelled,
                        'settled_at' => now(), 'review_handled_comment_id' => $receipt->id ?? $task->review_handled_comment_id]);
                    $group->update(['status' => TaskGroupStatus::Settling]);
                    $this->appendAgentBaseMerge($scheduler, $group->fresh(['tasks']) ?? $group, (string) $group->project->default_branch, $check);

                    return 'waiting';
                }
                $reason = 'The main merge Project check #'.$check->id.' failed (not a Git conflict). Repair the environment and post a resolution on final-review subtask #'.$task->id.' to retry. '.$check->output;
                $scheduler->requestAssistance($task, $group, $reason);
                throw new TaskCheckException($reason);
            }
            if (! $retryGate) {
                if ($check->head_after !== $snapshot->head || $check->tree_after !== $snapshot->tree) {
                    throw new TaskCheckException('The workspace changed after the main merge Project check #'.$check->id.'.');
                }
                $published = TaskReviewedCommit::query()->where('task_id', $group->id)->where('sha', $snapshot->head)->whereNotNull('pushed_at')->exists();
                if (! $refreshPassed || ! $published) {
                    // Reconciliation may have finished this gate before entering here.
                    // Publish its exact checked head before considering a newer main tip.
                    return 'passed';
                }
            }
            if ($retryGate && ($check->head_before !== $snapshot->head || $check->tree_before !== $snapshot->tree)) {
                throw new TaskCheckException('The workspace changed after the lost main merge Project check #'.$check->id.'.');
            }
            $check = null;
        }
        if (! $check instanceof TaskCheck) {
            $check = TaskCheck::query()->create(['task_id' => $task->id, 'task_comment_id' => $receipt?->id, 'kind' => TaskCheckKind::BaseMerge,
                'status' => TaskCheckStatus::Running, 'pid' => 0, 'process_started' => '',
                'head_before' => $snapshot->head, 'tree_before' => $snapshot->tree, 'started_at' => now()]);
        }
        // A repaired or lost gate retries the same merged head, not a newer main tip.
        $merge = $retryGate && ! ($repairGate && $refreshPassed) ? new TaskBaseMerge($snapshot->head) : $this->bases->mergeDefault($group);
        $head = $merge->head;
        if ($head === null) {
            $check->update(['status' => TaskCheckStatus::Failed, 'failed_step' => 'merge_conflict',
                'output' => "Conflicting files:\n".implode("\n", $merge->conflictingPaths), 'finished_at' => now()]);

            return 'conflict';
        }
        if ($head === $check->head_before && ! $retryGate) {
            $check->update(['status' => TaskCheckStatus::Passed, 'head_after' => $snapshot->head, 'tree_after' => $snapshot->tree,
                'exit_code' => 0, 'finished_at' => now()]);

            return 'passed';
        }
        $deliverables = ['start' => null, 'commands' => [], 'base_merge' => true];
        $handoff = TaskCheck::query()->whereHas('task', static fn ($query) => $query->where('parent_id', $group->id)->where('status', TaskStatus::Completed))
            ->where('kind', TaskCheckKind::Handoff)->where('status', TaskCheckStatus::Passed)->where('exit_code', 0)->latest('id')->first();
        if ($handoff instanceof TaskCheck && $handoff->tree_after !== null) {
            // Approval commits the handoff tree. The helper verifies it against the first parent's tree before reuse.
            $deliverables['base_merge_passed_tree'] = $handoff->tree_after;
        }
        $process = app(TaskWorkspaceLease::class)->startCheck($group, $task, $group->project->taskCheckCommand(), deliverables: $deliverables);
        $check->update(['pid' => $process->pid, 'process_started' => $process->started,
            'head_before' => $process->head, 'tree_before' => $process->tree]);

        return 'waiting';
    }

    public function appendAgentBaseMerge(TaskScheduler $scheduler, Task $group, string $base, ?TaskCheck $gate = null): void
    {
        if (TaskFinalReview::fixupsInWindow($group->tasks) >= TaskFinalReview::FixupLimit) {
            $reason = TaskFinalReview::FixupCapPrefix.($gate instanceof TaskCheck ? 'Fixing the main merge Project gate' : 'Merging '.$base).' needs an agent fixup, and Orbit already appended '.TaskFinalReview::FixupLimit.' final-review fixups in this window. Append a subtask with tasks:subtask:create, or complete the task.';
            TaskAssistance::apply($group, AssistanceKind::Failure, null, $reason);

            return;
        }
        $appended = DB::transaction(function () use ($group, $base, $gate): bool {
            $locked = Task::topLevel()->lockForUpdate()->findOrFail($group->id);
            $tasks = $this->runner->lockedTasks($locked);
            if (! in_array($locked->status, TaskGroupStatus::awaitingCompletion(), true) || TaskExecutionHold::active($locked) || $locked->assistance_requested
                || $tasks->contains(static fn (Task $task): bool => in_array($task->status, [TaskStatus::Todo, TaskStatus::Running, TaskStatus::Reviewing], true))) {
                return false;
            }
            $locked->loadMissing('project');
            Task::query()->create([
                'parent_id' => $locked->id,
                'position' => TaskFinalReview::nextPosition($tasks),
                'title' => $gate instanceof TaskCheck ? 'Fix main merge Project gate' : 'Merge origin/'.$base,
                'brief' => $gate instanceof TaskCheck
                    ? 'The Project check passes on both merge parents but fails on the merged tree. Fix the integration failure (including stale generated files). Orbit runs the Project check when you hand off. Check #'.$gate->id.":\n\n".$gate->output
                    : 'The mechanical main merge has a Git conflict. Merge origin/'.$base.' into the task branch and resolve the conflicting files. Do not rebase and do not force-push.'."\n\n".$this->conflictOutput($locked),
                'deliverables' => TaskFinalReview::fixupDeliverables(),
                'fixup_problem' => TaskFinalReview::FixupProblem,
                'status' => TaskStatus::Todo,
            ]);

            return true;
        });
        if ($appended) {
            TaskFinalReview::log($group, 'base merge appended', ['base' => $base, 'pull_request' => $group->pr_url]);
            $scheduler->resumeWaitingSubtask($group);
        }
    }

    private function conflictOutput(Task $group): string
    {
        $output = TaskCheck::query()->whereIn('task_id', $group->tasks()->select('id'))
            ->where('kind', TaskCheckKind::BaseMerge)->where('failed_step', 'merge_conflict')->latest('id')->value('output');

        return is_string($output) ? $output : '';
    }

    /** Reuses final-review subtasks and #1235's reviewed-tree delta, without an implementation fixup. */
    private function appendDeltaReview(TaskScheduler $scheduler, Task $group, Task $previous, ?TaskComment $receipt = null): void
    {
        if (TaskFinalReview::deltaReviewsInWindow($group->tasks()->get()) >= TaskFinalReview::DeltaReviewLimit) {
            $reason = 'Orbit already appended '.TaskFinalReview::DeltaReviewLimit.' delta final reviews in this window. Append operator work or resolve the repeated main overlap before continuing.';
            $scheduler->requestAssistance($previous, $group, $reason);

            return;
        }
        DB::transaction(function () use ($group, $previous, $receipt): void {
            $locked = Task::topLevel()->lockForUpdate()->findOrFail($group->id);
            $tasks = $this->runner->lockedTasks($locked);
            if (TaskExecutionHold::active($locked)) {
                return;
            }
            if ($receipt instanceof TaskComment) {
                $previous->update(['status' => TaskStatus::Completed, 'settled_at' => now(), 'review_handled_comment_id' => $receipt->id]);
            }
            if ($tasks->contains(static fn (Task $task): bool => $task->id !== $previous->id && in_array($task->status, [TaskStatus::Todo, TaskStatus::Running, TaskStatus::Reviewing], true))) {
                return;
            }
            Task::query()->create([
                'parent_id' => $group->id, 'position' => TaskFinalReview::nextPosition($tasks),
                'type' => TaskType::FinalReview, 'title' => TaskFinalReview::Title,
                'brief' => 'The mechanical main merge overlaps the group changes. Re-review only the delta since the last approved final-review tree, not the whole branch.',
                'deliverables' => TaskFinalReview::deliverables(), 'fixup_problem' => TaskFinalReview::BaseMergeReview,
                'status' => TaskStatus::Todo,
            ]);
            $locked->update(['status' => TaskGroupStatus::Running]);
        });
        TaskFinalReview::log($group, 'base merge delta review appended', ['previous_subtask_id' => $previous->id]);
        $scheduler->resumeWaitingSubtask($group->fresh(['tasks']) ?? $group);
    }

    /** The reason a trusted request for changes, or an incomplete review read, blocks the merge. Null when nothing blocks. */
    private function trustedChangesRequested(?TaskReviewObservation $reviews): ?string
    {
        if (! $reviews instanceof TaskReviewObservation) {
            return 'Orbit waits to read the pull request reviews again.';
        }
        if ($reviews->status === TaskReviewReadStatus::Disabled) {
            return null;
        }
        if ($reviews->status !== TaskReviewReadStatus::Complete) {
            return 'Orbit could not read every review of the pull request ('.$reviews->status->value.').';
        }
        foreach ($reviews->selection->effective ?? [] as $review) {
            if ($review->state === GitHubReviewState::ChangesRequested) {
                return 'Trusted reviewer '.$review->reviewerLogin.' requested changes in review '.$review->id.'. They must approve or dismiss it first.';
            }
        }

        return null;
    }

    /** Stores the merge gate's result. A changed result is recorded in Activity once. */
    private function recordMerge(Task $group, TaskMergeStatus $status, string $reason): void
    {
        if ($group->merge_status === $status && $group->merge_reason === $reason) {
            return;
        }
        $group->update(['merge_status' => $status, 'merge_reason' => $reason]);
        TaskFinalReview::log($group, 'merge '.$status->value, ['pull_request' => $group->pr_url, 'reason' => $reason]);
    }

    /**
     * ADR 0164: before a commit is pushed to a stored pull request, re-read its state. A merged or closed
     * pull request would never carry the commit, so Orbit does not push it and asks for assistance naming
     * it. An unreadable state is a publication failure that waits out the backoff.
     */
    private function pullRequestStillOpen(TaskScheduler $scheduler, Task $group, Task $task, string $commit): bool
    {
        if (! is_string($group->pr_url) || $group->pr_url === '') {
            return true;
        }
        $state = $this->pullRequestWatcher->status($group);
        if ($state === 'open') {
            return true;
        }
        if ($state === null) {
            $this->failPublication($scheduler, $group, $task, 'Orbit could not read the state of '.$group->pr_url.'.');

            return false;
        }

        $key = $this->publicationBackoffKey($task);
        $scheduler->extendBackoff($key, $scheduler->readBackoff($key, 'approved publication'), 'approved publication');
        $scheduler->requestAssistance($task, $group, TaskScheduler::OrphanedCommitPrefix.'Orbit did not push commit '.$commit.' of subtask #'.$task->id.' because '.$group->pr_url.' is already '.$state.'. Push that commit to a new branch and open a pull request, or cancel the group.');

        return false;
    }

    /** Whether the group waits for an operator because an approved commit missed its merged pull request. */
    public function orphanedCommit(Task $group): bool
    {
        return $group->assistance_requested && is_string($group->assistance_reason)
            && str_starts_with($group->assistance_reason, TaskScheduler::OrphanedCommitPrefix);
    }

    /**
     * ADR 0164: returning to settling and every merged cleanup tick revalidate the pull request. When its
     * head is not the latest approved commit, that commit missed the merge. The group asks for assistance
     * naming the commit and is not completed, so its workspace stays.
     */
    public function checkReturningPullRequest(Task $group, ?TaskPullRequestHealth $health = null): bool
    {
        $health ??= $this->pullRequestWatcher->health($group);
        if (! $health instanceof TaskPullRequestHealth || $health->state !== 'merged' || $health->headSha === null) {
            return false;
        }
        $commit = TaskComment::query()
            ->where('task_group_id', $group->id)
            ->where('type', TaskCommentType::Approved)
            ->whereNotNull('commit_sha')
            ->latest('id')
            ->value('commit_sha');
        if (! is_string($commit) || $commit === '' || $commit === $health->headSha || $this->orphanedCommit($group)) {
            return $this->orphanedCommit($group);
        }

        $inherited = TaskReviewedCommit::query()->where('task_id', $group->id)->where('sha', $health->headSha)
            ->where('source', TaskReviewedCommitSource::BaseMerge)->first();
        if ($inherited instanceof TaskReviewedCommit && $inherited->review_task_id !== null
            && TaskFinalReview::isReviewed($group, $commit)
            && TaskComment::query()->where('task_id', $inherited->review_task_id)->where('type', TaskCommentType::Approved)->where('commit_sha', $commit)->exists()) {
            return false;
        }

        $reason = TaskScheduler::OrphanedCommitPrefix.'Commit '.$commit.' reached task-'.$group->id.' after '.$group->pr_url.' merged at '.$health->headSha.'. Open a pull request for task-'.$group->id.', or complete the group.';
        TaskAssistance::apply($group, AssistanceKind::Failure, null, $reason, replaceFailure: true);

        // A direction request keeps its question, but the missed approval still protects the workspace.
        return true;
    }

    /** A failed push or open waits out the backoff and asks for assistance on the fifth failure. */
    private function failPublication(TaskScheduler $scheduler, Task $group, Task $task, string $reason): void
    {
        $key = $this->publicationBackoffKey($task);
        $scheduler->extendBackoff($key, $scheduler->readBackoff($key, 'approved publication'), 'approved publication');
        $scheduler->recordCommunicationFailure($task, $group, TaskScheduler::PublicationFailedPrefix.$reason);
    }

    /** Clears the publication failure only. A blocked question or any other cause stays on the subtask and the group. */
    private function clearPublicationAssistance(Task $task, Task $group): void
    {
        $task->refresh();
        $group->refresh();
        if (is_string($task->assistance_reason) && str_starts_with($task->assistance_reason, TaskScheduler::PublicationFailedPrefix)) {
            TaskAssistance::apply($task);
        }
        if (is_string($group->assistance_reason) && str_starts_with($group->assistance_reason, TaskScheduler::PublicationFailedPrefix)) {
            TaskAssistance::apply($group);
        }
    }

    public function publicationBackoffKey(Task $task): string
    {
        return 'tasks.approved-publication.'.$task->id;
    }
}
