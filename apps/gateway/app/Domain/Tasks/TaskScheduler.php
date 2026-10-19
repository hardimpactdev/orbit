<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Domain\Shared\StoredInteger;
use App\Models\AgentThread;
use App\Models\Task;
use App\Models\TaskCheck;
use App\Models\TaskComment;
use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

final readonly class TaskScheduler
{
    public const string ProvisioningFailedReason = 'Workspace provisioning did not return an instance.';

    public const string StartFailedReason = 'The group could not start after its workspace was provisioned.';

    public const string ReservationExpiredReason = 'The group stayed reserved too long and returned to todo.';

    public const string WorkspaceChangedReminder = 'The workspace working tree no longer matches the review baseline. Orbit cannot attribute this change. Assistance is required to identify and preserve it.';

    public const string TickFailedReason = 'The tick failed on this group. Fix the cause, then post a resolution. The error: ';

    private const string TickFailureKey = 'tasks.tick-failures.';

    /** Assistance set when an approved commit cannot be pushed or its pull request cannot be opened. */
    public const string PublicationFailedPrefix = 'Approved commit publication failed: ';

    /** Assistance set when an approved commit would reach, or reached, a pull request that already merged or closed (ADR 0164). */
    public const string OrphanedCommitPrefix = 'An approved commit is not on the pull request: ';

    /** Assistance set when a settling group has no pull request and no todo subtask (ADR 0164). */
    public const string MissingPullRequestPrefix = 'The settling group has no reviewed pull request URL.';

    /** Assistance set when a review request fails. The exception class follows; the message does not. */
    public const string ReviewRequestFailedReason = 'The review could not be requested';

    /** Re-evaluations of cancelled or unstarted checks on one head before the group asks for assistance (ADR 0164). */
    public const int InfrastructureCheckRetries = 5;

    /** The Pi server reports this when it restarted while a turn was still active (ADR 0116, ADR 0167). */
    public const string PiServerRestartError = 'The Pi server restarted during the turn.';

    /** One continue, on the same thread, after that restart. It does not ask for assistance. */
    public const string PiServerRestartContinue = 'Your previous turn was interrupted by a server restart. Check git status and git diff, finish the subtask, and hand off with the turn command.';

    /** Resumes reserved for one subtask before the next restart asks for assistance (ADR 0167). */
    public const int PiServerRestartResumeLimit = 2;

    /** Reasons the scheduler sets when a claim returns a group to todo. A start, a capacity wait, or a move to backlog clears them. */
    public const array ClaimFailureReasons = [
        self::ProvisioningFailedReason,
        self::StartFailedReason,
        self::ReservationExpiredReason,
    ];

    /**
     * How long a baseline start may stay unrecorded. This matches the SSH command timeout.
     * A claim older than that was interrupted, and its process may still be running.
     */
    public const int BASELINE_START_LIMIT_SECONDS = 900;

    /** Starts the assistance reason when the workspace Route still fails before the implementer or the final review. */
    public const string WorkspaceRouteFailedReason = 'The task workspace Route could not be finished, so no agent started.';

    /** Seconds one tick may spend removing abandoned workspaces, well inside the 300-second tick lock. */
    public const int AbandonedWorkspaceBudgetSeconds = 60;

    /** The first retry delay. The later delays are 2, 5, 10, and 30 minutes, and further failures stay at 30. */
    public const int AbandonedWorkspaceBackoffSeconds = 60;

    public function __construct(
        private TaskClaimAdmission $claims,
        private TaskReviewOutcome $reviews,
        private TaskPublication $publication,
        private TaskSettling $settling,
        private TaskConsultation $consults,
        private TaskSubtaskRunner $runner,
        private TaskExtensionState $extension,
        private PrunePendingTaskThreads $pendingThreads,
    ) {}

    /**
     * @return list<TaskSessionDecision>
     */
    public function tick(): array
    {
        app(TaskWorkspaceLease::class)->beginTick();
        try {
            return $this->tickWithWorkspaceLeases();
        } finally {
            app(TaskWorkspaceLease::class)->endTick();
        }
    }

    /** @return list<TaskSessionDecision> */
    private function tickWithWorkspaceLeases(): array
    {
        if (! $this->extension->enabled()) {
            return [];
        }

        $groups = Task::topLevel()
            ->with(['project', 'tasks', 'taskable'])
            ->whereIn('status', [TaskGroupStatus::Running, TaskGroupStatus::Reviewing, TaskGroupStatus::Settling, TaskGroupStatus::WaitingForReview])
            ->orderBy('id')
            ->get();
        $runningAtStart = [];
        foreach ($groups as $group) {
            if ($group->status === TaskGroupStatus::Running) {
                $runningAtStart[$group->id] = true;
            }
        }

        $failed = [];
        foreach ($groups as $group) {
            $this->isolateGroup($group, $failed, function () use ($group): void {
                app(TaskWorkspaceLease::class)->reconcile($group);
                $this->settling->tickPullRequest($this, $group);
            });
        }

        $decisions = [];

        foreach ($groups as $group) {
            if (! isset($failed[$group->id])) {
                $this->isolateGroup($group, $failed, function () use ($group, $runningAtStart, &$decisions): void {
                    TaskExecutionHold::run($group, function () use ($group, $runningAtStart, &$decisions): void {
                        app(TaskWorkspaceLease::class)->reconcile($group);
                        $this->runner->tickSubtasks($this, $group, isset($runningAtStart[$group->id]), $decisions);
                    });
                });
            }
        }
        foreach ($groups as $group) {
            if (! isset($failed[$group->id])) {
                Cache::forget(self::TickFailureKey.$group->id);
            }
        }

        $this->pendingThreads->run();

        return $decisions;
    }

    /**
     * Runs one group's tick work so its failure never stops the other groups. The failure is reported with the
     * group id, and the next tick tries again. A second failure in a row asks the group and its open subtasks for
     * assistance. Every failure is reported, also while the group asks. A row that breaks the task model cannot heal by
     * itself, so that failure asks at once.
     *
     * @param  array<int, true>  $failed
     */
    private function isolateGroup(Task $group, array &$failed, Closure $work): void
    {
        Context::scope(function () use ($group, &$failed, $work): void {
            try {
                $work();
            } catch (TaskWorkspaceWaiting) {
                return;
            } catch (Throwable $exception) {
                $failed[$group->id] = true;
                $key = self::TickFailureKey.$group->id;
                $failures = StoredInteger::fromOrZero(Cache::get($key)) + 1;
                Cache::put($key, $failures, now()->addDay());
                report($exception);
                if ($failures >= ($exception instanceof TaskHierarchyException ? 1 : 2)
                    && ! DB::table('tasks')->where('id', $group->id)->value('assistance_requested')) {
                    $this->escalateTickFailure($group, $exception);
                }
            }
        }, ['task_group_id' => $group->id]);
    }

    /** Raw writes: the model refuses every save of a broken row, and assistance must still reach the operator. */
    private function escalateTickFailure(Task $group, Throwable $exception): void
    {
        $reason = Str::limit(self::TickFailedReason.$exception->getMessage(), 1999, '…');
        TaskAssistance::apply($group, AssistanceKind::Failure, null, $reason, replaceFailure: true);
        foreach (Task::query()->where('parent_id', $group->id)->whereIn('status', [TaskStatus::Running, TaskStatus::Reviewing])->get() as $task) {
            TaskAssistance::apply($task, AssistanceKind::Failure, null, $reason, replaceFailure: true);
        }
    }

    public function handleReviewerOutcome(Task $group, Task $task, TaskSessionObservation $observation): bool
    {
        return $this->reviews->handleReviewerOutcome($this, $group, $task, $observation);
    }

    public function retryCommittedApproval(Task $group, Task $task): bool
    {
        return $this->reviews->retryCommittedApproval($this, $group, $task);
    }

    public function publishApprovedCommit(Task $group, Task $task, TaskComment $receipt): void
    {
        $this->publication->publishApprovedCommit($this, $group, $task, $receipt);
    }

    public function replaceFinalReviewWithFixup(Task $group, Task $task, TaskComment $receipt, string $title, string $brief): ?Task
    {
        return $this->reviews->replaceFinalReviewWithFixup($group, $task, $receipt, $title, $brief);
    }

    public function stopAtFinalReviewCap(Task $group, Task $task, TaskComment $receipt, int $count): void
    {
        $this->reviews->stopAtFinalReviewCap($group, $task, $receipt, $count);
    }

    public function beginFinalReview(Task $group, ?string $head = null): void
    {
        $this->reviews->beginFinalReview($this, $group, $head);
    }

    public function startFinalReview(Task $group, Task $task, bool $alreadyFetched): void
    {
        $this->reviews->startFinalReview($this, $group, $task, $alreadyFetched);
    }

    public function mergeWhenReady(Task $group, TaskPullRequestHealth $health, ?TaskReviewObservation $reviews): void
    {
        $this->publication->mergeWhenReady($this, $group, $health, $reviews);
    }

    /** Whether the group waits for an operator because an approved commit missed its merged pull request. */
    public function orphanedCommit(Task $group): bool
    {
        return $this->publication->orphanedCommit($group);
    }

    public function checkReturningPullRequest(Task $group, ?TaskPullRequestHealth $health = null): bool
    {
        return $this->publication->checkReturningPullRequest($group, $health);
    }

    public function publicationBackoffKey(Task $task): string
    {
        return $this->publication->publicationBackoffKey($task);
    }

    /**
     * ADR 0200: a group whose own changes against origin/main touch a path in `task_vms.topology_paths`
     * needs a declared topology on some subtask of the group. Null when the rule does not apply.
     */
    public function topologyRequiredItem(Task $group, ?TaskDeliverableEvidence $evidence): ?TaskRubricItem
    {
        return $this->consults->topologyRequiredItem($group, $evidence);
    }

    /**
     * ADR 0133: a receipt confirms every deliverable it must, as the turn command requires. A hand-written
     * receipt that misses one fails the `deliverables` item.
     */
    public function confirmationItem(Task $task, ?TaskComment $receipt, TaskThreadRole $role): ?TaskRubricItem
    {
        return $this->consults->confirmationItem($task, $receipt, $role);
    }

    public function receiptItem(?TaskTurnReceipt $read, ?TaskComment $receipt): TaskRubricItem
    {
        return $this->consults->receiptItem($read, $receipt);
    }

    /**
     * Stores a receipt that fits the turn as a comment, then removes the file. A crash before the
     * removal reads the receipt again, and its hash matches the stored comment.
     *
     * @throws TaskTurnReceiptException
     */
    public function collectReceipt(Task $group, Task $task, TaskThreadRole $role): ?TaskTurnReceipt
    {
        return $this->consults->collectReceipt($group, $task, $role);
    }

    /**
     * Returns the latest stored receipt of this attempt that the scheduler has not acted on.
     */
    public function pendingReceipt(Task $task, TaskThreadRole $role): ?TaskComment
    {
        return $this->consults->pendingReceipt($task, $role);
    }

    public function receiptOutcome(TaskComment $receipt): ?TaskTurnOutcome
    {
        return $this->consults->receiptOutcome($receipt);
    }

    public function waitingItem(TaskThreadObservation $thread): ?TaskRubricItem
    {
        return $this->consults->waitingItem($thread);
    }

    /**
     * Sends one reminder per attempt, then asks for assistance.
     *
     * @param  list<TaskRubricItem>  $items
     * @return bool whether the scheduler reminded the agent or asked for assistance
     */
    public function remindOrAssist(Task $group, Task $task, TaskThreadObservation $thread, array $items, ?TaskCheck $failedCheck = null): bool
    {
        return $this->consults->remindOrAssist($this, $group, $task, $thread, $items, $failedCheck);
    }

    /** @param list<TaskRubricItem> $items
     * @return list<TaskRubricItem>
     */
    public function failedItems(array $items): array
    {
        return $this->consults->failedItems($items);
    }

    public function clearCommunicationFailures(Task $task): void
    {
        $this->consults->clearCommunicationFailures($task);
    }

    public function recordCommunicationFailure(Task $task, Task $group, string $reason): void
    {
        $this->consults->recordCommunicationFailure($task, $group, $reason);
    }

    /**
     * An implementer's blocked receipt asks the reviewer first. The third block in one attempt
     * asks for direction at once, with both earlier answers in the reason.
     */
    public function beginConsult(Task $group, Task $task, TaskComment $receipt, TaskSessionObservation $observation): void
    {
        $this->consults->beginConsult($group, $task, $receipt, $observation);
    }

    /** Reads the consult receipt. `answered` continues the implementer. `blocked` asks for direction. */
    public function handleConsult(Task $group, Task $task): void
    {
        $this->consults->handleConsult($this, $group, $task);
    }

    /** A direction resolution is waiting to be relayed because the subtask has no reviewer yet. */
    public function pendingRelayResolution(Task $task): ?TaskComment
    {
        return $this->consults->pendingRelayResolution($task);
    }

    /** Starts the reviewer and sends the operator's resolution as a relay, not as a review packet. */
    public function beginDirectionRelay(Task $group, Task $task, TaskComment $resolution): void
    {
        $this->consults->beginDirectionRelay($group, $task, $resolution);
    }

    /** Reads the relay receipt. `answered` records the operator's answer. `blocked` keeps the same question. */
    public function handleDirectionRelay(Task $group, Task $task): void
    {
        $this->consults->handleDirectionRelay($this, $group, $task);
    }

    /** Finishes a reviewing direction hold from the resolution comment already stored. */
    public function replayHeldDirectionReview(Task $task): void
    {
        $this->consults->replayHeldDirectionReview($task);
    }

    /**
     * @param  array<string, int>  $handled
     */
    public function requestAssistance(Task $task, Task $group, string $reason, ?TaskSessionObservation $observation = null, AssistanceKind $kind = AssistanceKind::Failure, ?string $question = null, array $handled = [], ?TaskComment $source = null): void
    {
        $this->consults->requestAssistance($task, $group, $reason, $observation, $kind, $question, $handled, $source);
    }

    /**
     * Clears successful provider retries and reconciles pending Pi restart resumes from the acting thread (ADR 0167).
     * An accepted key is not sent again. A different turn does not reuse it either.
     */
    public function reconcilePiRestart(Task $task, TaskThreadObservation $acting): void
    {
        $this->consults->reconcilePiRestart($task, $acting);
    }

    /** Reserves a delayed, idempotent continuation of the same work before asking for assistance. */
    public function retryProviderFailure(Task $task, Task $group, TaskThreadObservation $acting): bool
    {
        return $this->consults->retryProviderFailure($task, $group, $acting);
    }

    /**
     * Resumes a Pi turn that failed only because the server restarted (ADR 0167).
     *
     * @return 'handled'|'assist'|'skip' handled owns the tick, assist asks for assistance, skip keeps today's failure path
     */
    public function resumePiServerRestart(Task $task, Task $group, TaskThreadObservation $acting): string
    {
        return $this->consults->resumePiServerRestart($task, $group, $acting);
    }

    public function clearUnavailable(Task $group): void
    {
        $this->runner->clearUnavailable($group);
    }

    public function unavailableDecision(Task $group): TaskSessionDecision
    {
        return $this->runner->unavailableDecision($group);
    }

    /** @param list<int> $skipped */
    public function claimNext(array &$skipped = []): ?Task
    {
        return $this->claims->claimNext($this, $skipped);
    }

    public function releaseStaleReservations(): int
    {
        return $this->claims->releaseStaleReservations();
    }

    /**
     * Delay before the next push or removal attempt after this many failures.
     * The first failure waits one minute, then 2, 5, 10, and 30 minutes.
     */
    public static function retryDelaySeconds(int $failures): int
    {
        return TaskSettling::retryDelaySeconds($failures);
    }

    public function removeAbandonedWorkspaces(): int
    {
        return $this->settling->removeAbandonedWorkspaces();
    }

    /**
     * @param  array{failures: int, due: int}|null  $backoff
     */
    public function extendBackoff(string $key, ?array $backoff, string $label): void
    {
        $this->settling->extendBackoff($key, $backoff, $label);
    }

    /** The next attempt waits until `due`. A missing backoff, or a cache read that fails, means try now. */
    public function retryIsDue(string $key, string $label): bool
    {
        return $this->settling->retryIsDue($key, $label);
    }

    /**
     * Reads a retry backoff. A cache error is logged and read as no backoff, so one bad read never stops the
     * sweep or the tick.
     *
     * @return array{failures: int, due: int}|null
     */
    public function readBackoff(string $key, string $label): ?array
    {
        return $this->settling->readBackoff($key, $label);
    }

    /**
     * Stores or clears a retry backoff. A cache error is logged and the attempt continues.
     *
     * @param  array{failures: int, due: int}|null  $backoff
     */
    public function rememberBackoff(string $key, ?array $backoff, string $label, int $seconds = 0): bool
    {
        return $this->settling->rememberBackoff($key, $backoff, $label, $seconds);
    }

    public static function isClaimFailureReason(?string $reason): bool
    {
        return in_array($reason, self::ClaimFailureReasons, true)
            || (is_string($reason) && str_starts_with($reason, self::ProvisioningFailedReason.' '));
    }

    public function claimAvailable(): int
    {
        return $this->claims->claimAvailable($this);
    }

    /** @throws TaskCheckException */
    public function workspaceSnapshot(Task $group): TaskWorkspaceSnapshot
    {
        return $this->reviews->workspaceSnapshot($group);
    }

    public function nudgeReviewer(Task $task, ?TaskThreadObservation $reviewer): void
    {
        $this->reviews->nudgeReviewer($this, $task, $reviewer);
    }

    /** The reviewer thread for this subtask, preferring the one the group currently points at. */
    public function subtaskReviewer(Task $task): ?AgentThread
    {
        return $this->consults->subtaskReviewer($task);
    }

    /**
     * A working thread never receives a turn; the scheduler sends it on a later tick.
     */
    public function isWorking(?TaskThreadObservation $thread): bool
    {
        return $this->consults->isWorking($thread);
    }

    public function newerTurnHasStopped(?string $previousTurnId, TaskThreadObservation $thread): bool
    {
        return $this->consults->newerTurnHasStopped($previousTurnId, $thread);
    }

    public function settleImplementer(Task $task, ?TaskThreadObservation $reviewer = null): Task
    {
        return $this->reviews->settleImplementer($this, $task, $reviewer);
    }

    public function startTask(Task $task): Task
    {
        return $this->runner->startTask($this, $task);
    }

    /** @param Closure(Task): void $stop */
    public function cancelRunningSubtask(Task $parent, Task $task, Closure $stop): Task
    {
        return $this->settling->cancelRunningSubtask($this, $parent, $task, $stop);
    }

    public function acceptReview(Task $task): Task
    {
        return $this->reviews->acceptReview($this, $task);
    }

    public function settle(
        Task $group,
        bool $requestMissingPullRequest = true,
        bool $checkReturningPullRequest = true,
    ): Task {
        return $this->settling->settle($this, $group, $requestMissingPullRequest, $checkReturningPullRequest);
    }

    /** Review-source problems are observations, not a hold on otherwise authorized ongoing work. */
    public function progressBlockedByAssistance(Task $group): bool
    {
        return $this->settling->progressBlockedByAssistance($group);
    }

    public function queueBaselineRetryOnGreenTip(Task $group, Task $task): bool
    {
        return $this->settling->queueBaselineRetryOnGreenTip($group, $task);
    }

    public function resumeStrandedSubtask(Task $group): void
    {
        $this->settling->resumeStrandedSubtask($this, $group);
    }

    public function resumeWaitingSubtask(Task $group): void
    {
        $this->settling->resumeWaitingSubtask($this, $group);
    }

    /** The base ref stored on a conflict fixup, or null when the subtask is not one. */
    public function conflictBase(Task $task): ?string
    {
        return $this->settling->conflictBase($task);
    }

    public static function isMissingPullRequestReason(?string $reason): bool
    {
        return TaskSettling::isMissingPullRequestReason($reason);
    }

    /** Another assistance cause blocks a resume. The missing-pull-request reason does not. */
    public static function resumeBlocked(Task $group): bool
    {
        return TaskSettling::resumeBlocked($group);
    }

    public function failSpawn(?Task $group, ?Task $task, string $agent): void
    {
        $this->consults->failSpawn($group, $task, $agent);
    }
}
