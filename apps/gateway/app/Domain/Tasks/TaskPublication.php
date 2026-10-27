<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Domain\GitHub\GitHubReviewEvent;
use App\Domain\GitHub\GitHubReviewState;
use App\Domain\GitHub\RequiredCheckState;
use App\Models\Activity;
use App\Models\Task;
use App\Models\TaskComment;
use App\Models\TaskReviewedCommit;
use Illuminate\Support\Facades\Cache;

final readonly class TaskPublication
{
    public function __construct(
        private TaskSubtaskRunner $runner,
        private TaskPullRequestWatcher $pullRequestWatcher,
        private TaskPullRequestPublisher $publisher,
        private TaskBroadcasts $broadcasts,
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
        TaskGroupGuard::requireTick($group);
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
        TaskGroupGuard::requireTick($group);
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
        $check = $group->project->mergeCheckName() ?? '';
        $state = $this->merger->pullRequestCheck($group, $head, $check);
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
        // GitHub limits writes. A refused merge is written again at most once a minute for one head.
        if (! Cache::add('tasks.merge-attempt.'.$group->id.'.'.$head, true, 60)) {
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
                TaskAssistance::apply($group, AssistanceKind::Failure, null, $group->merge_reason);
            }

            return;
        }
        $group->update(['merge_status' => TaskMergeStatus::Merged, 'merge_reason' => null, 'merged_sha' => $result->sha, 'merged_at' => now()]);
        TaskFinalReview::log($group, 'pull request merged', ['pull_request' => $group->pr_url, 'head_sha' => $head, 'merge_sha' => $result->sha]);
        $this->broadcasts->groupChanged($group->id);
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
        TaskGroupGuard::requireTick($group);
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

        $reviewed = TaskReviewedCommit::query()->where('task_id', $group->id)->where('sha', $health->headSha)
            ->where('source', TaskReviewedCommitSource::OrbitPush)->where('inherited_approval_sha', $commit)->first();
        if ($reviewed instanceof TaskReviewedCommit && $reviewed->review_task_id !== null
            && TaskFinalReview::isReviewed($group, $commit)
            && TaskComment::query()->where('task_id', $reviewed->review_task_id)->where('type', TaskCommentType::Approved)->where('commit_sha', $commit)->exists()) {
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
