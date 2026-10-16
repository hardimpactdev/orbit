<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Actions\Tasks\RequestEndedPullRequestAssistanceAction;
use App\Domain\GitHub\GitHubReviewEvent;
use App\Models\AgentThread;
use App\Models\Instance;
use App\Models\Task;
use App\Models\TaskCheck;
use App\Models\TaskComment;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

final readonly class TaskReviewOutcome
{
    private const string StaleReviewInspectionFailure = 'Orbit could not inspect the workspace while recovering a stale review after a main merge: ';

    public function __construct(
        private TaskSubtaskRunner $runner,
        private AgentSpawner $spawner,
        private TaskWorkspaceStateReader $workspace,
        private TaskPullRequestWatcher $pullRequestWatcher,
        private CoderSettleNotifier $coder,
        private TaskSessionActor $actor,
        private TaskWorkspaceSigner $signer,
        private TaskBriefCoverage $coverage,
        private TaskCheckRunner $checks,
        private TaskBaseBranchFetcher $bases,
        private TaskTurnFetcher $turnFetcher,
        private TaskTopologyAdmission $topologyAdmission,
        private TaskPullRequestMerger $merger,
        private TaskSessionObserver $observer,
    ) {}

    public function handleReviewerOutcome(TaskScheduler $scheduler, Task $group, Task $task, TaskSessionObservation $observation): bool
    {
        $reviewer = $observation->thread(TaskThreadRole::Reviewer);
        // Before this subtask's review is requested, the observed reviewer can be an earlier
        // subtask's thread. A Pi restart of that turn is not this review. Request the review
        // first, and recover only a review that was already requested (ADR 0167, ADR 0169).
        if ($reviewer === null || $task->review_notified_attempt !== $task->review_attempt) {
            $this->nudgeReviewer($scheduler, $task, $reviewer);

            return true;
        }
        $scheduler->reconcilePiRestart($task, $reviewer);
        $state = AgentThreadState::tryFrom($reviewer->sessState);
        if ($state === AgentThreadState::Failed) {
            if (! $scheduler->retryProviderFailure($task, $group, $reviewer) && $scheduler->resumePiServerRestart($task, $group, $reviewer) !== 'handled') {
                $scheduler->requestAssistance($task, $group, 'The reviewer thread failed.', $observation);
            }

            return true;
        }
        if (! in_array($state, [AgentThreadState::Idle, AgentThreadState::Done, AgentThreadState::AskingForInput], true)) {
            return false;
        }
        if (! $scheduler->newerTurnHasStopped($task->review_notified_turn_id, $reviewer)) {
            return true;
        }

        // A prior verdict already admitted this exact follow-up. Retry its durable command
        // before requesting a new checkout verdict while the follow-up still owns the lease.
        $findings = $scheduler->pendingReceipt($task, TaskThreadRole::Reviewer);
        $implementer = $observation->thread(TaskThreadRole::Implementer);
        $implementerThread = $implementer === null ? null : AgentThread::query()->find($implementer->threadId);
        if ($findings instanceof TaskComment && $scheduler->receiptOutcome($findings) === TaskTurnOutcome::ChangesRequested
            && ! $task->isFinalReview() && $implementerThread instanceof AgentThread
            && app(TaskWorkspaceLease::class)->retrying($implementerThread, 'tasks.findings:'.$findings->id)) {
            $this->relayFindings($scheduler, $group, $task, $observation, $findings);

            return true;
        }
        $thread = AgentThread::query()->find($reviewer->threadId);
        $key = TaskConsultation::reminderKey($task, TaskThreadRole::Reviewer);
        $pending = $thread instanceof AgentThread ? app(TaskWorkspaceLease::class)->pendingSend($thread, $key) : null;
        if ($thread instanceof AgentThread && $pending !== null) {
            try {
                app(AgentDriverRegistry::class)->get($thread->driver)->send($thread, $pending['message'], $pending['key']);
                $task->update(['review_reminder_attempt' => $task->review_attempt,
                    'review_reminder_input_id' => $reviewer->pendingUserInputId ?? $reviewer->pendingApprovalId, 'communication_failures' => 0]);
            } catch (AgentDriverException $exception) {
                $scheduler->recordCommunicationFailure($task, $group, $exception->getMessage());
            }

            return true;
        }

        try {
            return app(TaskWorkspaceLease::class)->run($group, 'verdict', 'review-outcome:'.$task->id,
                fn (): bool => $this->applyStoppedReviewerOutcome($scheduler, $group, $task, $observation, $reviewer), taskId: $task->id);
        } catch (TaskCheckException $exception) {
            $scheduler->recordCommunicationFailure($task, $group, $exception->getMessage());

            return true;
        }
    }

    private function applyStoppedReviewerOutcome(TaskScheduler $scheduler, Task $group, Task $task, TaskSessionObservation $observation, TaskThreadObservation $reviewer): bool
    {
        try {
            $read = $scheduler->collectReceipt($group, $task, TaskThreadRole::Reviewer);
        } catch (TaskTurnReceiptException $exception) {
            $scheduler->recordCommunicationFailure($task, $group, $exception->getMessage());

            return true;
        }
        $receipt = $scheduler->pendingReceipt($task, TaskThreadRole::Reviewer);
        $outcome = $receipt instanceof TaskComment ? $scheduler->receiptOutcome($receipt) : null;
        if ($receipt instanceof TaskComment && TaskQuestions::awaitsCause($task) && ! $receipt->cause instanceof QuestionCause) {
            $scheduler->remindOrAssist($group, $task, $reviewer, [
                new TaskRubricItem('turn_receipt', false, 'This review follows a direction resolution and needs --cause with one of: brief_unclear, contract_gap, scope, environment, missed_contract.'),
            ]);

            return true;
        }
        if ($outcome === TaskTurnOutcome::Approved && $scheduler->isWorking($observation->thread(TaskThreadRole::Implementer))) {
            // Orbit commits the whole workspace, so the approval waits until the implementer stops changing it.
            return true;
        }
        if ($outcome === TaskTurnOutcome::ChangesRequested && $scheduler->isWorking($observation->thread(TaskThreadRole::Implementer))) {
            return true;
        }
        if ($receipt instanceof TaskComment
            && in_array($outcome, [TaskTurnOutcome::Blocked, TaskTurnOutcome::ChangesRequested, TaskTurnOutcome::Approved], true)) {
            $decision = $this->reviewWorkspaceDecision($scheduler, $group, $task, $reviewer, $receipt, $observation);
            if ($decision === 'orbit_commit') {
                try {
                    $recovered = $this->recoveredCommit($task, $this->workspaceSnapshot($group));
                } catch (TaskCheckException $exception) {
                    $scheduler->recordCommunicationFailure($task, $group, $exception->getMessage());

                    return true;
                }
                if ($recovered === null) {
                    $scheduler->recordCommunicationFailure($task, $group, 'Orbit could not commit the approved subtask.');

                    return true;
                }
                $receipt->update(['commit_sha' => $recovered]);
                $scheduler->publishApprovedCommit($group, $task, $receipt);

                return true;
            }
            if ($decision !== 'apply') {
                return true;
            }
        }
        if ($receipt instanceof TaskComment && $outcome === TaskTurnOutcome::Approved && $this->committedApproval($receipt)) {
            // The commit is already stored and the workspace still holds it. Retry the push only; do not commit again.
            $scheduler->publishApprovedCommit($group, $task, $receipt);

            return true;
        }
        if ($receipt instanceof TaskComment && $outcome === TaskTurnOutcome::Blocked) {
            $scheduler->requestAssistance($task, $group, TaskAssistance::ReviewerBlockedPrefix.$receipt->body, $observation, AssistanceKind::Direction, TaskAssistance::questionFromBlockedReason($receipt->body), ['review_handled_comment_id' => $receipt->id], $receipt);

            return true;
        }
        if ($receipt instanceof TaskComment && $outcome === TaskTurnOutcome::ChangesRequested && $task->isFinalReview()) {
            $this->applyFinalReviewFindings($scheduler, $group, $task, $receipt);

            return true;
        }
        if ($receipt instanceof TaskComment && $outcome === TaskTurnOutcome::ChangesRequested) {
            $this->relayFindings($scheduler, $group, $task, $observation, $receipt);

            return true;
        }

        $instance = $group->taskable;
        $pullRequest = null;
        $evidenceSummary = null;
        $items = [$scheduler->receiptItem($read, $receipt)];
        if ($receipt instanceof TaskComment && $outcome === TaskTurnOutcome::Approved) {
            $onBranch = $instance instanceof Instance && $this->workspace->currentBranch($instance) === 'task-'.$group->id;
            $items[] = new TaskRubricItem('branch', $onBranch, 'The workspace branch is not task-'.$group->id.'. Switch back to it.');
            $confirmation = $scheduler->confirmationItem($task, $receipt, TaskThreadRole::Reviewer);
            if ($confirmation instanceof TaskRubricItem) {
                $items[] = $confirmation;
            }
            try {
                [$evidence, $evidenceSummary] = $this->topologyEvidenceItem($group, $task);
            } catch (TaskCheckException $exception) {
                $scheduler->recordCommunicationFailure($task, $group, $exception->getMessage());

                return true;
            }
            if ($evidence instanceof TaskRubricItem) {
                $items[] = $evidence;
            }
            if ($task->opensPullRequest()) {
                $pullRequest = TaskTurnPullRequest::fromArray($receipt->pull_request);
                $items[] = new TaskRubricItem('pull_request_fields', $pullRequest instanceof TaskTurnPullRequest, 'The approval of the last subtask needs --pr-summary, --pr-change, and --pr-breaking.');
            }
        }
        $waiting = $scheduler->waitingItem($reviewer);
        if ($waiting instanceof TaskRubricItem) {
            $items[] = $waiting;
        }
        if ($scheduler->failedItems($items) === [] && $pullRequest instanceof TaskTurnPullRequest) {
            try {
                $missing = $this->coverage->missing(
                    $group,
                    $pullRequest,
                    $receipt->id,
                    $pullRequest->changes,
                );
            } catch (TaskSessionClassificationException $exception) {
                $scheduler->recordCommunicationFailure($task, $group, $exception->getMessage());

                return true;
            }
            foreach ($missing as $title) {
                $items[] = new TaskRubricItem('brief_coverage', false, 'The pull request change list does not cover the subtask "'.$title.'".');
            }
        }
        if (! $receipt instanceof TaskComment || $scheduler->failedItems($items) !== []) {
            if ($scheduler->remindOrAssist($group, $task, $reviewer, $items) && $receipt instanceof TaskComment) {
                $task->update(['review_handled_comment_id' => $receipt->id]);
            }

            return true;
        }
        // The counted records go into the approval, and so into its commit, for a person to audit.
        if (is_string($evidenceSummary) && ! str_contains($receipt->body, $evidenceSummary)) {
            $receipt->update(['body' => rtrim($receipt->body)."\n\n".$evidenceSummary]);
        }

        if ($task->isFinalReview()) {
            // ADR 0203: a final review commits nothing. Its approval names the HEAD it reviewed, and only that HEAD is pushed.
            $head = $task->review_workspace_head;
            if (! is_string($head) || preg_match('/\A[0-9a-f]{40}(?:[0-9a-f]{24})?\z/D', $head) !== 1) {
                $scheduler->recordCommunicationFailure($task, $group, 'Orbit could not read the head the final review approved.');

                return true;
            }
            $receipt->update(['commit_sha' => $head]);
            TaskFinalReview::log($group, 'final review approved', ['subtask_id' => $task->id, 'comment_id' => $receipt->id, 'commit_sha' => $head]);
            $scheduler->publishApprovedCommit($group, $task, $receipt);

            return true;
        }
        $commit = $instance instanceof Instance ? $this->signer->commit($instance, $task->title."\n\n".$receipt->body) : null;
        if ($commit === null && $instance instanceof Instance) {
            // The commit can land and the SHA response can still be lost. The parent and tree identify Orbit's commit.
            try {
                $commit = $this->recoveredCommit($task, $this->workspaceSnapshot($group));
            } catch (TaskCheckException $exception) {
                $scheduler->recordCommunicationFailure($task, $group, $exception->getMessage());

                return true;
            }
        }
        if ($commit === null) {
            $scheduler->recordCommunicationFailure($task, $group, 'Orbit could not commit the approved subtask.');

            return true;
        }
        $receipt->update(['commit_sha' => $commit]);
        $scheduler->publishApprovedCommit($group, $task, $receipt);

        return true;
    }

    /**
     * ADR 0160: the approval already names its commit, so a later tick pushes that commit and does not make another.
     */
    private function committedApproval(TaskComment $receipt): bool
    {
        return is_string($receipt->commit_sha) && $receipt->commit_sha !== '';
    }

    /**
     * Pushes an approved commit that is already stored, and reports whether this tick did that.
     * The tick calls this before it observes the reviewer, so an unavailable reviewer does not skip the retry.
     * A failed push asks for assistance on the fifth failure, and the tick keeps retrying it. This does not commit again.
     */
    public function retryCommittedApproval(TaskScheduler $scheduler, Task $group, Task $task): bool
    {
        return TaskExecutionHold::run($group, fn (): bool => $this->retryAdmittedApproval($scheduler, $group, $task)) ?? true;
    }

    private function retryAdmittedApproval(TaskScheduler $scheduler, Task $group, Task $task): bool
    {
        $receipt = $scheduler->pendingReceipt($task, TaskThreadRole::Reviewer);
        if (! $receipt instanceof TaskComment || $scheduler->receiptOutcome($receipt) !== TaskTurnOutcome::Approved || ! $this->committedApproval($receipt)) {
            return false;
        }
        if (! $scheduler->retryIsDue($scheduler->publicationBackoffKey($task), 'approved publication')) {
            return true;
        }

        try {
            $current = $this->workspaceSnapshot($group);
        } catch (TaskCheckException $exception) {
            $scheduler->recordCommunicationFailure($task, $group, $exception->getMessage());

            return true;
        }
        $tree = $task->review_workspace_tree;
        $merge = $task->isFinalReview() ? $task->checks()->where('kind', TaskCheckKind::BaseMerge)
            ->where('task_comment_id', $receipt->id)->latest('id')->first() : null;
        $mergedHead = $merge instanceof TaskCheck && $current->head === ($merge->head_after ?? $merge->head_before)
            && $current->tree === ($merge->tree_after ?? $merge->tree_before);
        if (! $mergedHead && ($current->head !== $receipt->commit_sha || (is_string($tree) && $tree !== '' && $current->tree !== $tree))) {
            // ADR 0133: the workspace no longer holds the approved commit. The reviewer outcome path reminds or asks for assistance.
            return false;
        }

        $scheduler->publishApprovedCommit($group, $task, $receipt);

        return true;
    }

    /**
     * ADR 0203: a final review requested changes. On an incoming pull request whose head it reviewed, Orbit posts
     * the findings as a `REQUEST_CHANGES` review first. Then, in one transaction, it completes the final review and
     * starts a fixup subtask with the findings. The fourth set of findings in one window asks for assistance.
     */
    private function applyFinalReviewFindings(TaskScheduler $scheduler, Task $group, Task $task, TaskComment $receipt): void
    {
        $group = $group->fresh(['project', 'tasks', 'taskable']) ?? $group;
        $count = TaskFinalReview::fixupsInWindow($group->tasks);
        if ($count >= TaskFinalReview::FixupLimit) {
            $this->stopAtFinalReviewCap($group, $task, $receipt, $count);

            return;
        }
        $head = $task->review_workspace_head;
        if ($group->reviewsIncomingPullRequest() && is_string($head) && $head !== '' && $head === $task->fixup_head_sha) {
            $key = 'tasks.final-review-request-changes.'.$receipt->id;
            if (Cache::get($key) === null) {
                try {
                    $reviewId = $this->merger->review($group, $head, GitHubReviewEvent::RequestChanges, "Orbit reviewed the whole pull request at {$head} and requests changes. Orbit applies them itself.\n\n".mb_substr(trim($receipt->body), 0, 60000));
                } catch (TaskPullRequestException $exception) {
                    $scheduler->recordCommunicationFailure($task, $group, $exception->getMessage());

                    return;
                }
                Cache::forever($key, $reviewId);
                TaskFinalReview::log($group, 'pull request changes requested', ['subtask_id' => $task->id, 'commit_sha' => $head, 'review_id' => $reviewId, 'pull_request' => $group->pr_url]);
            }
        }

        $fixup = $this->replaceFinalReviewWithFixup($group, $task, $receipt, TaskFinalReview::FixupTitle, TaskFinalReview::fixupBrief($task, $receipt));
        if (! $fixup instanceof Task) {
            return;
        }
        TaskFinalReview::log($group, 'final review requested changes', ['subtask_id' => $task->id, 'comment_id' => $receipt->id, 'fixup_id' => $fixup->id]);
        $this->runner->recordSubtaskStart($fixup);
        $this->runner->assignImplementer($scheduler, $fixup);
    }

    /**
     * Completes the final review and starts a final-review fixup in one transaction. The caller starts its implementer.
     */
    public function replaceFinalReviewWithFixup(Task $group, Task $task, TaskComment $receipt, string $title, string $brief): ?Task
    {
        return DB::transaction(function () use ($group, $task, $receipt, $title, $brief): ?Task {
            $lockedGroup = Task::topLevel()->lockForUpdate()->findOrFail($group->id);
            $locked = Task::query()->lockForUpdate()->findOrFail($task->id);
            if (TaskExecutionHold::active($lockedGroup) || $locked->status !== TaskStatus::Reviewing || $locked->review_handled_comment_id === $receipt->id) {
                return null;
            }
            $lockedGroup->loadMissing('project');
            $fixup = Task::query()->create([
                'parent_id' => $lockedGroup->id,
                'position' => TaskFinalReview::nextPosition($this->runner->lockedTasks($lockedGroup)),
                'title' => $title,
                'brief' => $brief,
                'deliverables' => TaskFinalReview::fixupDeliverables($lockedGroup->project->taskCheckCommand()),
                'fixup_problem' => TaskFinalReview::FixupProblem,
                'status' => TaskStatus::Todo,
            ]);
            TaskQuestions::answerPending($locked, $receipt);
            $locked->update([
                'status' => TaskStatus::Completed,
                'settled_at' => $locked->settled_at ?? now(),
                'review_handled_comment_id' => $receipt->id,
                'communication_failures' => 0,
            ]);
            $this->runner->markRunning($fixup, $this->runner->lockedTasks($lockedGroup));
            $lockedGroup->status = TaskGroupStatus::Running;
            $lockedGroup->save();

            return $fixup->fresh(['parent.tasks', 'parent.project', 'parent.taskable']) ?? $fixup;
        });
    }

    /**
     * ADR 0203: the final review is complete and its findings wait for a person. The task settles and asks for
     * assistance. An operator subtask appended to it resumes the task and, when it completes, opens a new window.
     */
    public function stopAtFinalReviewCap(Task $group, Task $task, TaskComment $receipt, int $count): void
    {
        $reason = TaskFinalReview::FixupCapPrefix.'Orbit already appended '.$count.' fixups for final review findings in this window. Read the findings of subtask #'.$task->id.', then append a subtask with tasks:subtask:create, or complete the task.';
        $stopped = DB::transaction(function () use ($group, $task, $receipt, $reason): bool {
            $lockedGroup = Task::topLevel()->lockForUpdate()->findOrFail($group->id);
            $locked = Task::query()->lockForUpdate()->findOrFail($task->id);
            if (TaskExecutionHold::active($lockedGroup) || $locked->status !== TaskStatus::Reviewing || $locked->review_handled_comment_id === $receipt->id) {
                return false;
            }
            TaskQuestions::answerPending($locked, $receipt);
            $locked->update(['status' => TaskStatus::Completed, 'settled_at' => $locked->settled_at ?? now(), 'review_handled_comment_id' => $receipt->id, 'communication_failures' => 0]);
            $lockedGroup->status = TaskGroupStatus::Settling;
            $lockedGroup->save();

            return TaskAssistance::apply($lockedGroup, AssistanceKind::Failure, null, $reason);
        });
        TaskFinalReview::log($group, 'final review requested changes', ['subtask_id' => $task->id, 'comment_id' => $receipt->id, 'fixup_id' => null, 'reason' => $reason]);
        if ($stopped) {
            $this->coder->assistance($group, $reason);
        }
    }

    /**
     * ADR 0203: appends a final review when none is open and starts it. On an incoming pull request,
     * `$head` is the pull request head that the final review moves the workspace to.
     */
    public function beginFinalReview(TaskScheduler $scheduler, Task $group, ?string $head = null): void
    {
        $appended = DB::transaction(function () use ($group, $head): bool {
            $locked = Task::topLevel()->lockForUpdate()->findOrFail($group->id);
            if (! in_array($locked->status, TaskGroupStatus::awaitingCompletion(), true) || TaskExecutionHold::active($locked)
                || ($locked->assistance_requested && TaskFinalReview::isCapReason($locked->assistance_reason))) {
                return false;
            }
            $tasks = $this->runner->lockedTasks($locked);
            if ($tasks->contains(static fn (Task $task): bool => in_array($task->status, [TaskStatus::Todo, TaskStatus::Running, TaskStatus::Reviewing], true))) {
                return false;
            }
            $locked->loadMissing('project');
            Task::query()->create([
                'parent_id' => $locked->id,
                'position' => TaskFinalReview::nextPosition($tasks),
                'type' => TaskType::FinalReview,
                'title' => TaskFinalReview::Title,
                'brief' => TaskFinalReview::brief($locked),
                'deliverables' => TaskFinalReview::deliverables(),
                'fixup_head_sha' => $head,
                'status' => TaskStatus::Todo,
            ]);

            return true;
        });
        if ($appended) {
            TaskFinalReview::log($group, 'final review appended', ['pull_request_head' => $head]);
            $scheduler->resumeWaitingSubtask($group);
        }
    }

    /**
     * ADR 0203: a final review skips the implementer and the task check. On an incoming pull request without
     * unpushed approved work, the workspace first moves to the pull request head. The diff base is the merge
     * base with the default branch, so the reviewer sees the whole branch.
     */
    public function startFinalReview(TaskScheduler $scheduler, Task $group, Task $task, bool $alreadyFetched): void
    {
        app(TaskWorkspaceLease::class)->run($group, 'verdict', 'final-review:'.$task->id, fn () => $this->startFinalReviewWithLease($scheduler, $group, $task, $alreadyFetched), taskId: $task->id);
    }

    private function startFinalReviewWithLease(TaskScheduler $scheduler, Task $group, Task $task, bool $alreadyFetched): void
    {
        if ($task->status !== TaskStatus::Running || ! $this->runner->workspaceRouteReady($scheduler, $group, $task)) {
            return;
        }
        try {
            if (! $alreadyFetched) {
                $this->turnFetcher->fetch($group);
            }
            $preparingMerge = $task->fixup_problem === TaskFinalReview::BaseMergeReview || $task->checks()->where('kind', TaskCheckKind::BaseMerge)->exists();
            if ($group->reviewsIncomingPullRequest() && ! TaskFinalReview::hasUnreviewedWork($group) && ! $preparingMerge) {
                // The author can push before the review starts. Review the head the pull request has now.
                $current = $this->pullRequestWatcher->health($group)?->headSha;
                if (is_string($current) && $current !== '' && $current !== $task->fixup_head_sha) {
                    $task->update(['fixup_head_sha' => $current]);
                    if ($alreadyFetched) {
                        $this->turnFetcher->fetch($group);
                    }
                }
                $head = $task->fixup_head_sha;
                if (! is_string($head) || $head === '') {
                    throw new TaskPullRequestException('Orbit could not read the pull request head.');
                }
                $this->bases->moveTo($group, $head);
            }
            $merge = $task->fixup_problem === TaskFinalReview::BaseMergeReview ? 'passed' : $scheduler->prepareBaseMerge($group, $task);
            if ($merge === 'conflict') {
                $task->update(['status' => TaskStatus::Cancelled, 'settled_at' => now()]);
                $group->update(['status' => TaskGroupStatus::Settling]);
                $scheduler->appendAgentBaseMerge($group, (string) $group->project->default_branch);

                return;
            }
            if ($merge === 'waiting') {
                return;
            }
            $base = $this->bases->mergeBase($group);
        } catch (TaskWorkspaceWaiting) {
            return;
        } catch (Throwable $exception) {
            $scheduler->recordCommunicationFailure($task, $group, $exception->getMessage());

            return;
        }
        $started = DB::transaction(function () use ($task, $base): bool {
            $locked = Task::query()->lockForUpdate()->findOrFail($task->id);
            $lockedGroup = Task::topLevel()->lockForUpdate()->findOrFail($locked->parent_id);
            if (TaskExecutionHold::active($lockedGroup) || $locked->status !== TaskStatus::Running) {
                return false;
            }
            $locked->update(['status' => TaskStatus::Reviewing, 'subtask_start_commit' => $base, 'communication_failures' => 0]);
            $lockedGroup->update(['status' => TaskGroupStatus::Reviewing]);

            return true;
        });
        if ($started) {
            $scheduler->clearCommunicationFailures($task);
            $this->nudgeReviewer($scheduler, $task->fresh() ?? $task, null);
        }
    }

    private function relayFindings(TaskScheduler $scheduler, Task $group, Task $task, TaskSessionObservation $observation, TaskComment $findings): void
    {
        $implementer = $observation->thread(TaskThreadRole::Implementer);
        if ($implementer === null) {
            $scheduler->requestAssistance($task, $group, 'The implementer thread is unavailable for the review findings.', $observation);

            return;
        }
        if ($scheduler->isWorking($implementer)) {
            return;
        }
        $key = 'tasks.findings:'.$findings->id;
        try {
            if (! $this->runner->prepareTurn($scheduler, $group, $task, TaskThreadRole::Implementer, $implementer->threadId, sendKey: $key)) {
                return;
            }
            $this->actor->relayReviewBody($group, $implementer, $findings->body, $key);
        } catch (AgentDriverException|TaskTurnReceiptException $exception) {
            $scheduler->recordCommunicationFailure($task, $group, $exception->getMessage());

            return;
        }
        TaskQuestions::answerPending($task, $findings);
        $task->update([
            'status' => TaskStatus::Running,
            'review_handled_comment_id' => $findings->id,
            'review_attempt' => $task->review_attempt + 1,
            'review_reminder_attempt' => null,
            'review_reminder_input_id' => null,
            'completion_attempt' => $task->completion_attempt + 1,
            'completion_handoff_attempt' => $task->completion_attempt + 1,
            'completion_handoff_turn_id' => $implementer->turnId,
            'communication_failures' => 0,
            'completion_handoff_comment_id' => null,
            'completion_reminder_attempt' => null,
            'completion_reminder_input_id' => null,
        ]);
        $group->update(['status' => TaskGroupStatus::Running]);
    }

    /**
     * ADR 0200: the approval of a subtask that declares a topology needs recorded evidence on each declared node,
     * started after the implementer's last handoff. Returns the rubric item and the summary of the counted records,
     * which the approval carries for a person to audit; both null when the subtask declares none.
     *
     * @return array{0: ?TaskRubricItem, 1: ?string}
     *
     * @throws TaskCheckException when the evidence log cannot be read
     */
    private function topologyEvidenceItem(Task $group, Task $task): array
    {
        $nodes = $task->topology->nodes();
        if ($nodes === []) {
            return [null, null];
        }
        $handoff = $task->completion_handoff_comment_id === null ? null : TaskComment::query()->find($task->completion_handoff_comment_id);
        $since = $handoff instanceof TaskComment ? $handoff->posted_at : ($task->started_at ?? now());
        $evidence = $this->topologyAdmission->evidence($group, $since);
        $missing = $evidence->missing($nodes);

        return [
            new TaskRubricItem('topology_evidence', $missing === [], $missing === [] ? '' : TaskTopologyEvidence::reminder($group->id, $missing)),
            $missing === [] ? $evidence->summary($nodes) : null,
        ];
    }

    /**
     * ADR 0133: a reviewer turn is read-only. The receipt stays unapplied while the workspace differs
     * from the pair recorded with the review request, unless a newer implementer turn explains the
     * difference or the commit below is Orbit's. One reminder, then the scheduler waits for a newer
     * stopped reviewer turn before it looks again. That later turn applies the outcome when the
     * workspace matches, and asks for assistance when the working tree still differs. A changed
     * HEAD starts a fresh delta review without attributing the change to an actor. Another poll of the reminded
     * turn does neither. A failed read is a communication failure, not a change. A review notified
     * before a baseline existed has nothing to compare, so its outcome applies as before.
     * A commit already stored on the receipt is Orbit's. Publication retries only while HEAD is
     * that commit and the working tree still matches. The HEAD from before the approval is not
     * accepted after Orbit has committed, so a reset that drops the commit is refused.
     *
     * @return 'apply'|'orbit_commit'|'wait'|'reopened'|'reminded'|'unreadable'
     */
    private function reviewWorkspaceDecision(TaskScheduler $scheduler, Task $group, Task $task, TaskThreadObservation $reviewer, TaskComment $receipt, TaskSessionObservation $observation): string
    {
        try {
            $current = $this->workspaceSnapshot($group);
        } catch (TaskCheckException $exception) {
            $scheduler->recordCommunicationFailure($task, $group, $exception->getMessage());

            return 'unreadable';
        }
        if ($this->workspaceMatchesReview($task, $receipt, $current)) {
            return 'apply';
        }
        if (! $task->isFinalReview() && $scheduler->receiptOutcome($receipt) === TaskTurnOutcome::Approved && ! $this->committedApproval($receipt) && $this->recoveredCommit($task, $current) !== null) {
            return 'orbit_commit';
        }
        $implementer = $observation->thread(TaskThreadRole::Implementer);
        if ($this->newerImplementerTurn($task, $implementer)) {
            if ($implementer instanceof TaskThreadObservation && $scheduler->newerTurnHasStopped($task->completion_handoff_turn_id, $implementer)) {
                $this->reopenHandoff($group, $task, $receipt);

                return 'reopened';
            }

            return 'wait';
        }
        if ($this->ownedWorkspaceChange($group, $task, $current)) {
            $this->restartReviewOnHead($scheduler, $group, $task, $reviewer, $current, $receipt->id);

            return 'reopened';
        }
        $scheduler->requestAssistance($task, $group, TaskScheduler::WorkspaceChangedReminder, $observation, handled: ['review_handled_comment_id' => $receipt->id]);

        return 'reminded';
    }

    /**
     * Older deployments could consume a receipt and ask for assistance after Orbit changed the workspace.
     * Run before the assistance gate; only an exact recorded Orbit check or merge tree can lift that failure.
     */
    public function recoverStaleReview(TaskScheduler $scheduler, Task $group, Task $task): bool
    {
        if ($task->status !== TaskStatus::Reviewing || ! $task->assistance_requested
            || ! $this->workspaceFailure($task)
            || ($group->assistance_requested && ! $this->workspaceFailure($group))
            || $group->tasks()->where('id', '!=', $task->id)->where('assistance_requested', true)->exists()) {
            return false;
        }

        try {
            return app(TaskWorkspaceLease::class)->run($group, 'verdict', 'recover-review:'.$task->id,
                fn (): bool => $this->recoverOwnedReview($scheduler, $group, $task), taskId: $task->id);
        } catch (TaskCheckException $exception) {
            $reason = self::StaleReviewInspectionFailure.$exception->getMessage();
            $this->reportStaleReviewInspectionFailure($group, $task, $reason);
            $scheduler->recordCommunicationFailure($task, $group, $reason);

            return false;
        }
    }

    private function recoverOwnedReview(TaskScheduler $scheduler, Task $group, Task $task): bool
    {
        $head = $task->review_workspace_head;
        if (! is_string($head) || $head === '') {
            return false;
        }
        try {
            // The surrounding workspace lease reconciles remote checks under the execution
            // lock before this verdict can inspect the checkout or lift assistance.
            $current = $this->workspaceSnapshot($group);
        } catch (TaskCheckException $exception) {
            $reason = self::StaleReviewInspectionFailure.$exception->getMessage();
            $this->reportStaleReviewInspectionFailure($group, $task, $reason);
            $scheduler->recordCommunicationFailure($task, $group, $reason);

            return false;
        }
        if (! $this->ownedWorkspaceChange($group, $task, $current)) {
            return false;
        }
        $observation = $this->observer->observe($group, $task);
        $reviewer = $observation->thread(TaskThreadRole::Reviewer);
        if (! $observation->available || $reviewer === null || $scheduler->isWorking($reviewer)
            || $scheduler->isWorking($observation->thread(TaskThreadRole::Implementer))) {
            return false;
        }

        return $this->restartReviewOnHead($scheduler, $group, $task, $reviewer, $current, $task->review_handled_comment_id, recoverAssistance: true);
    }

    public function reportStaleReviewCheckFailure(Task $group, Task $task, string $reason): void
    {
        $this->reportStaleReviewInspectionFailure($group, $task, self::StaleReviewInspectionFailure.$reason);
    }

    private function reportStaleReviewInspectionFailure(Task $group, Task $task, string $reason): void
    {
        // Only replace this recovery's failure. An operator may have requested unrelated
        // assistance while the workspace inspection was in flight.
        DB::transaction(function () use ($group, $task, $reason): void {
            $lockedTask = Task::query()->lockForUpdate()->findOrFail($task->id);
            $lockedGroup = Task::topLevel()->lockForUpdate()->findOrFail($group->id);
            if ($lockedTask->status !== TaskStatus::Reviewing || ! $lockedTask->assistance_requested || ! $this->workspaceFailure($lockedTask)) {
                return;
            }
            TaskAssistance::apply($lockedTask, AssistanceKind::Failure, null, $reason, replaceFailure: true);
            if ($lockedGroup->assistance_requested && $this->workspaceFailure($lockedGroup)
                && ! $lockedGroup->tasks()->where('id', '!=', $task->id)->where('assistance_requested', true)->exists()) {
                TaskAssistance::apply($lockedGroup, AssistanceKind::Failure, null, $reason, replaceFailure: true);
            }
        });
        $task->refresh();
        $group->refresh();
    }

    private function workspaceFailure(Task $task): bool
    {
        $reason = $task->assistance_reason;

        return $task->assistance_kind === AssistanceKind::Failure && is_string($reason)
            && (str_starts_with($reason, self::StaleReviewInspectionFailure) || in_array($reason, [
                'Checks still failed after the reminder. '.TaskScheduler::WorkspaceChangedReminder,
                TaskScheduler::WorkspaceChangedReminder,
                'Checks still failed after the reminder. The workspace working tree no longer matches the review baseline. Orbit cannot attribute this change to the reviewer. Restore the reviewed tree or request the needed changes from the implementer.',
                'Checks still failed after the reminder. The workspace changed during the review. Revert your changes and request the changes from the implementer instead.',
            ], true));
    }

    private function ownedWorkspaceChange(Task $group, Task $task, TaskWorkspaceSnapshot $current): bool
    {
        return is_string($task->review_workspace_head) && is_string($task->review_workspace_tree)
            && app(TaskWorkspaceLease::class)->ownedChange($group, $task->review_workspace_head, $task->review_workspace_tree, $current, $task->review_workspace_lease_id);
    }

    private function restartReviewOnHead(TaskScheduler $scheduler, Task $group, Task $task, TaskThreadObservation $reviewer, TaskWorkspaceSnapshot $current, ?int $handledComment, bool $recoverAssistance = false): bool
    {
        // Keep the previous tree until the packet is sent so it remains the delta base.
        $previousHead = $task->review_workspace_head;
        $advanced = DB::transaction(function () use ($group, $task, $handledComment, $recoverAssistance): bool {
            $lockedTask = Task::query()->lockForUpdate()->findOrFail($task->id);
            $lockedGroup = Task::topLevel()->lockForUpdate()->findOrFail($group->id);
            if ($lockedTask->status !== TaskStatus::Reviewing || $lockedTask->review_attempt !== $task->review_attempt) {
                return false;
            }
            if ($recoverAssistance) {
                if (! $lockedTask->assistance_requested || ! $this->workspaceFailure($lockedTask)
                    || ($lockedGroup->assistance_requested && ! $this->workspaceFailure($lockedGroup))
                    || $lockedGroup->tasks()->where('id', '!=', $task->id)->where('assistance_requested', true)->exists()) {
                    return false;
                }
                $lockedGroup->update(TaskAssistance::cleared());
            }
            $lockedTask->update([
                ...($recoverAssistance ? TaskAssistance::cleared() : []),
                'review_handled_comment_id' => $handledComment,
                'review_attempt' => $lockedTask->review_attempt + 1,
                'review_reminder_attempt' => null,
                'review_reminder_input_id' => null,
            ]);

            return true;
        });
        if (! $advanced) {
            return false;
        }
        $task->refresh();
        $group->refresh();
        TaskFinalReview::log($group, 'workspace head changed during review', [
            'subtask_id' => $task->id, 'previous_head' => $previousHead, 'head' => $current->head,
            'cause' => 'orbit_workspace_lease',
        ]);
        $this->nudgeReviewer($scheduler, $task, $reviewer);

        return true;
    }

    private function workspaceMatchesReview(Task $task, TaskComment $receipt, TaskWorkspaceSnapshot $current): bool
    {
        $head = $task->review_workspace_head;
        $tree = $task->review_workspace_tree;
        $storedCommit = $receipt->commit_sha;
        $orbitCommit = is_string($storedCommit) && $storedCommit !== '' ? $storedCommit : null;
        $treeMatches = ! is_string($tree) || $tree === '' || $current->tree === $tree;
        $headMatches = $orbitCommit !== null
            ? $current->head === $orbitCommit
            : ! is_string($head) || $head === '' || $current->head === $head;

        return $headMatches && $treeMatches;
    }

    /**
     * Orbit's commit after a lost response: HEAD's parent is the HEAD recorded with the review
     * request, and HEAD's tree is the tree recorded with that request (ADR 0133).
     */
    private function recoveredCommit(Task $task, TaskWorkspaceSnapshot $current): ?string
    {
        $head = $task->review_workspace_head;
        $tree = $task->review_workspace_tree;
        if (! is_string($head) || $head === '' || ! is_string($tree) || $tree === '' || $current->head === '' || $current->head === $head) {
            return null;
        }
        if ($current->parent !== $head || $current->commitTree !== $tree) {
            return null;
        }

        return $current->head;
    }

    private function newerImplementerTurn(Task $task, ?TaskThreadObservation $implementer): bool
    {
        $handoff = $task->completion_handoff_turn_id;

        return is_string($handoff) && $handoff !== ''
            && $implementer instanceof TaskThreadObservation
            && is_string($implementer->turnId) && $implementer->turnId !== ''
            && $implementer->turnId !== $handoff;
    }

    /**
     * A newer implementer turn changed the workspace during review. The reviewer outcome is not
     * applied and the reviewer is not reminded. The subtask needs a new receipt and a passing check.
     */
    private function reopenHandoff(Task $group, Task $task, TaskComment $receipt): void
    {
        $task->update([
            'status' => TaskStatus::Running,
            'review_handled_comment_id' => $receipt->id,
            'review_attempt' => $task->review_attempt + 1,
            'review_reminder_attempt' => null,
            'review_reminder_input_id' => null,
            'completion_attempt' => $task->completion_attempt + 1,
            'completion_handoff_attempt' => null,
            'completion_handoff_comment_id' => null,
            'completion_reminder_attempt' => null,
            'completion_reminder_input_id' => null,
            'communication_failures' => 0,
        ]);
        $group->update(['status' => TaskGroupStatus::Running]);
    }

    /** @throws TaskCheckException */
    public function workspaceSnapshot(Task $group): TaskWorkspaceSnapshot
    {
        $instance = $group->taskable;
        if (! $instance instanceof Instance) {
            throw new TaskCheckException('The task workspace is unavailable.');
        }

        return $this->checks->snapshot($instance);
    }

    /**
     * Starts a fresh reviewer for this subtask's first review, or continues that subtask's reviewer.
     * A working continued reviewer gets no request. An earlier subtask's reviewer does not
     * delay a fresh review. The task stays unnotified until the request is sent.
     */
    public function nudgeReviewer(TaskScheduler $scheduler, Task $task, ?TaskThreadObservation $reviewer): void
    {
        if ($task->review_notified_attempt === $task->review_attempt) {
            return;
        }
        try {
            TaskExecutionHold::run($task->parent, function () use ($scheduler, $task, $reviewer): void {
                $this->nudgeAdmittedReviewer($scheduler, $task, $reviewer);
            });
        } catch (TaskWorkspaceWaiting) {
            // The durable waiter is retried on the next scheduler tick.
        }
    }

    private function nudgeAdmittedReviewer(TaskScheduler $scheduler, Task $task, ?TaskThreadObservation $reviewer): void
    {
        if ($task->review_notified_attempt === $task->review_attempt) {
            return;
        }
        $existing = $scheduler->subtaskReviewer($task);
        if ($existing !== null && $reviewer !== null && $reviewer->threadId === $existing->id && $scheduler->isWorking($reviewer)) {
            return;
        }
        $group = $task->parent()->with('taskable')->firstOrFail();
        try {
            $reserved = $existing === null ? $this->spawner->reserveReviewer($task) : $existing->id;
            $dispatch = function () use ($scheduler, $group, $task, $existing, $reserved): bool {
                if (! $this->runner->prepareTurn($scheduler, $group, $task, TaskThreadRole::Reviewer, $reserved)) {
                    return false;
                }
                if ($existing === null) {
                    $threadId = $this->spawner->spawnReviewer($task);
                    if ($threadId === null) {
                        throw new AgentDriverException('The reviewer conversation could not be started.');
                    }
                } else {
                    $this->spawner->requestReview($task);
                    $continued = $scheduler->subtaskReviewer($task);
                    $threadId = $continued instanceof AgentThread ? $continued->id : $existing->id;
                }
                $group->update(['reviewer_agent_thread_id' => $threadId]);

                return true;
            };
            $leases = app(TaskWorkspaceLease::class);
            if ($reserved === null) {
                $sent = $leases->run($group, 'reviewer', 'review-dispatch:'.$task->id, $dispatch, taskId: $task->id);
            } else {
                $sent = $leases->withThreadLock(AgentThread::query()->findOrFail($reserved), $dispatch);
            }
            if (! $sent) {
                return;
            }
        } catch (TaskWorkspaceWaiting) {
            return;
        } catch (Throwable $exception) {
            report($exception);
            $scheduler->recordCommunicationFailure($task, $group, TaskScheduler::ReviewRequestFailedReason.' ('.class_basename($exception).').');

            return;
        }

        $task->update([
            'review_notified_attempt' => $task->review_attempt,
            'review_notified_turn_id' => $reviewer?->turnId,
        ]);
        $scheduler->clearCommunicationFailures($task);
    }

    public function settleImplementer(TaskScheduler $scheduler, Task $task, ?TaskThreadObservation $reviewer = null): Task
    {
        $group = DB::transaction(function () use ($task): Task {
            $locked = Task::query()->lockForUpdate()->findOrFail($task->id);
            $group = Task::topLevel()
                ->with(['tasks', 'project', 'taskable'])
                ->lockForUpdate()
                ->findOrFail($locked->parent_id);

            if (TaskExecutionHold::active($group) || RequestEndedPullRequestAssistanceAction::isReason($group->assistance_reason)
                || $group->status !== TaskGroupStatus::Running || $locked->status !== TaskStatus::Running) {
                return $group->fresh(['tasks', 'project', 'taskable']) ?? $group;
            }

            $locked->status = TaskStatus::Reviewing;
            $locked->save();
            $group->status = TaskGroupStatus::Reviewing;
            $group->save();

            return $group->fresh(['tasks', 'project', 'taskable']) ?? $group;
        });

        $reviewing = $group->tasks->first(
            static fn (Task $candidate): bool => $candidate->id === $task->id,
        );

        if ($reviewing instanceof Task && $reviewing->status === TaskStatus::Reviewing) {
            $this->nudgeReviewer($scheduler, $reviewing, $reviewer);
        }

        return $group->fresh(['tasks', 'project', 'taskable']) ?? $group;
    }

    public function acceptReview(TaskScheduler $scheduler, Task $task): Task
    {
        $next = null;
        $group = DB::transaction(function () use ($task, &$next): Task {
            $locked = Task::query()->lockForUpdate()->findOrFail($task->id);
            $group = Task::topLevel()
                ->with(['tasks', 'project', 'taskable'])
                ->lockForUpdate()
                ->findOrFail($locked->parent_id);

            if (TaskExecutionHold::active($group) || RequestEndedPullRequestAssistanceAction::isReason($group->assistance_reason)
                || $group->status !== TaskGroupStatus::Reviewing || $locked->status !== TaskStatus::Reviewing) {
                return $group->fresh(['tasks', 'project', 'taskable']) ?? $group;
            }

            $locked->status = TaskStatus::Completed;
            $locked->settled_at ??= now();
            $locked->save();

            $tasks = $this->runner->lockedTasks($group);
            $next = $this->runner->lowestTodo($tasks);

            if ($next instanceof Task) {
                try {
                    $this->runner->markRunning($next, $tasks);
                    $group->status = TaskGroupStatus::Running;
                } catch (TaskSequenceException) {
                    $next = null;
                    $group->status = $this->runner->runningSibling($tasks) instanceof Task
                        ? TaskGroupStatus::Running
                        : TaskGroupStatus::Reviewing;
                }
            } else {
                $group->status = TaskGroupStatus::Settling;
            }

            $group->save();

            return $group->fresh(['tasks', 'project', 'taskable']) ?? $group;
        });

        if ($next instanceof Task && $next->status === TaskStatus::Running) {
            $this->runner->recordSubtaskStart($next);
            $this->runner->assignImplementer($scheduler, $next);
        }

        if (in_array($group->status, TaskGroupStatus::awaitingCompletion(), true)) {
            return $scheduler->settle($group);
        }

        return $group->fresh(['tasks', 'project', 'taskable']) ?? $group;
    }
}
