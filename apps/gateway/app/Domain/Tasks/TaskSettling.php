<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Actions\Tasks\CompleteTaskGroupAction;
use App\Actions\Tasks\RemoveTaskWorkspaceAction;
use App\Actions\Tasks\RequestEndedPullRequestAssistanceAction;
use App\Actions\Tasks\RetryTaskBaselineAction;
use App\Actions\Tasks\WatchTaskBranchPullRequestAction;
use App\Actions\TaskVms\WakeTaskVmAction;
use App\Domain\GitHub\RequiredCheckState;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Shared\StoredInteger;
use App\Models\Instance;
use App\Models\Task;
use App\Models\TaskCheck;
use App\Models\TaskComment;
use App\Models\TaskReviewedCommit;
use Closure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

final readonly class TaskSettling
{
    public function __construct(
        private TaskSubtaskRunner $runner,
        private TaskSettleMetricsCollector $metrics,
        private TaskPullRequestWatcher $pullRequestWatcher,
        private TaskPullRequestUpdater $pullRequestUpdater,
        private CompleteTaskGroupAction $completeGroup,
        private CoderSettleNotifier $coder,
        private BriefCoverageLabeler $coverageLabeler,
        private TaskPullRequestPublisher $publisher,
        private TaskBroadcasts $broadcasts,
        private RemoveTaskWorkspaceAction $workspaces,
        private TaskBaseBranchFetcher $bases,
        private WatchTaskBranchPullRequestAction $branchPullRequests,
        private RequestEndedPullRequestAssistanceAction $endedPullRequests,
        private TaskTurnFetcher $turnFetcher,
        private RetryTaskBaselineAction $retryBaseline,
        private TaskPullRequestReviewWatcher $reviewWatcher,
        private TaskGitHubReviewConsumption $reviewConsumption,
        private TaskGitHubReviewFeedback $reviewFeedback,
        private TaskPullRequestMerger $merger,
    ) {}

    public function tickPullRequest(TaskScheduler $scheduler, Task $group): void
    {
        $this->reportMissingReviewFixups($group);
        if (in_array($group->status, [TaskGroupStatus::Running, TaskGroupStatus::Reviewing], true)
            && in_array($group->watched_pr_completion, ['merged', 'closed'], true)) {
            try {
                $this->completeGroup->execute($group);
            } catch (Throwable $exception) {
                report($exception);
            }

            return;
        }
        $this->branchPullRequests->execute($group);
        if ($this->endedPullRequests->execute($group)) {
            return;
        }
        if (! in_array($group->status, TaskGroupStatus::awaitingCompletion(), true)) {
            return;
        }
        if (! is_string($group->pr_url) || $group->pr_url === '') {
            if ($this->runner->lowestTodo($group->tasks) instanceof Task) {
                $this->resumeWaitingSubtask($scheduler, $group);
            } elseif (TaskFinalReview::due($group, $group->tasks)) {
                $scheduler->beginFinalReview($group);
            } else {
                $this->requestMissingPullRequest($group);
            }

            return;
        }
        $reviews = $this->observeReviewFeedback($group);
        $health = $this->pullRequestWatcher->health($group);
        $status = $health?->state;
        // With open work, only the branch watch decides which pull request ended. Never
        // auto-complete from pr_url here: it can name an older, reviewed pull request.
        if (in_array($status, ['merged', 'closed'], true) && $group->tasks->contains(
            static fn (Task $task): bool => in_array($task->status, [TaskStatus::Todo, TaskStatus::Running, TaskStatus::Reviewing], true),
        )) {
            return;
        }
        if ($status === 'merged') {
            try {
                $this->coverageLabeler->label($group, $health);
            } catch (Throwable $exception) {
                try {
                    report($exception);
                } catch (Throwable) {
                }
            }
            // Revalidate even when a prior tick committed settling but stopped before its hold write.
            $missedApproval = $scheduler->checkReturningPullRequest($group, $health);
            if (! $missedApproval && ! $scheduler->orphanedCommit($group)) {
                $this->completeMergedGroup($group);
            }
        } elseif ($status === 'closed') {
            TaskAssistance::apply($group, AssistanceKind::Failure, null, 'The expected pull request closed without merging.', replaceFailure: true);
        } elseif ($health instanceof TaskPullRequestHealth) {
            $pendingMerge = TaskCheck::query()->whereHas('task', static fn ($query) => $query->where('parent_id', $group->id))
                ->where('kind', TaskCheckKind::BaseMerge)->where('status', TaskCheckStatus::Running)->exists();
            $pendingPush = TaskReviewedCommit::query()->where('task_id', $group->id)
                ->where('source', TaskReviewedCommitSource::BaseMerge)->whereNull('pushed_at')->exists();
            if ($group->reviewsBeforePush() && ! $group->assistance_requested && ($pendingMerge || $pendingPush)) {
                if ($health->baseRef === $group->project->default_branch) {
                    $scheduler->appendBaseMerge($group, (string) $health->baseRef);
                }

                return;
            }
            $this->healOpenPullRequest($scheduler, $group, $health, $reviews);
            if ($group->reviewsBeforePush()) {
                $scheduler->mergeWhenReady($group, $health, $reviews);
            }
        }
    }

    /**
     * Delay before the next push or removal attempt after this many failures.
     * The first failure waits one minute, then 2, 5, 10, and 30 minutes.
     */
    public static function retryDelaySeconds(int $failures): int
    {
        $schedule = [TaskScheduler::AbandonedWorkspaceBackoffSeconds, 120, 300, 600, 1800];
        $index = min(max($failures, 1), count($schedule)) - 1;

        return $schedule[$index];
    }

    /**
     * Removes the workspace of a cancelled or completed group, attached or found by its `task-{group id}` name and
     * branch, and of a settling group whose merged pull request cleanup failed. An unattached workspace waits while
     * a live claim can still own it. The tick does not remove the workspace of a group that is still active.
     *
     * One query selects the candidates. A failed removal is reported, asks for assistance, and backs off per
     * Instance, so a workspace that keeps failing never blocks the others. The sweep stops starting removals once
     * it has spent its time budget; the rest wait for the next tick. Success clears removal assistance only.
     * A cancelled group pushes its stored approval before the checkout is deleted. A user Instance attached to a
     * non-managed group is never a candidate.
     */
    public function removeAbandonedWorkspaces(): int
    {
        $started = now();
        $removed = 0;

        foreach ($this->abandonedWorkspaces() as $workspace) {
            if ($started->diffInSeconds(now(), true) >= TaskScheduler::AbandonedWorkspaceBudgetSeconds) {
                break;
            }

            $backoffKey = 'tasks.workspace-removal.'.$workspace->id;
            $backoff = $this->readBackoff($backoffKey, 'workspace removal');
            if ($backoff !== null && $backoff['due'] > now()->getTimestamp()) {
                continue;
            }

            $instance = Instance::query()->find($workspace->id);
            $group = Task::topLevel()->find($workspace->getAttribute('ended_task_group_id'));
            if (! $instance instanceof Instance || ! $group instanceof Task || ! $this->shouldRemoveWorkspace($group, $instance)) {
                continue;
            }
            // A parked task VM starts first. The removal waits for it without a failure or a backoff.
            if (! app(WakeTaskVmAction::class)->forInstance($instance)) {
                continue;
            }

            try {
                $didRemove = app(TaskExecutionLock::class)->synchronized($group->id, function () use ($group, $instance, $backoffKey): bool {
                    $group->refresh();
                    if (! $this->shouldRemoveWorkspace($group, $instance)) {
                        return false;
                    }
                    $group->setRelation('taskable', $instance);
                    $leases = app(TaskWorkspaceLease::class);
                    $leases->cancelQueued($group, 'cancel-push:'.$group->id);
                    $leases->run($group, 'teardown', 'teardown:'.$instance->id, function () use ($group, $instance): void {
                        $this->pushCancelledApproval($group, $instance);
                        $this->workspaces->remove($instance);
                    });
                    $this->rememberBackoff($backoffKey, null, 'workspace removal');
                    $this->releaseRemovedWorkspace($group, $instance->id);
                    Log::warning('Removed the workspace of an ended task group.', ['task_group_id' => $group->id, 'instance_id' => $instance->id]);

                    return true;
                });
                if ($didRemove) {
                    $removed++;
                }
            } catch (TaskWorkspaceWaiting) {
                continue;
            } catch (Throwable $exception) {
                // The push of a cancelled approval from a task VM runs in a queued job. A later sweep removes the workspace.
                if ($exception instanceof TaskPullRequestException && $exception->pending) {
                    continue;
                }
                report($exception);
                $prefix = in_array($group->status, TaskGroupStatus::awaitingCompletion(), true)
                    ? RemoveTaskWorkspaceAction::MergeCleanupFailedPrefix
                    : RemoveTaskWorkspaceAction::RemovalFailedPrefix;
                $this->workspaces->recordFailure($group, $exception, $prefix);
                $this->extendBackoff($backoffKey, $backoff, 'workspace removal');
            }
        }

        return $removed;
    }

    /** Retries merged pull request cleanup at once the first time, then on the same per-Instance backoff as the sweep. */
    private function completeMergedGroup(Task $group): void
    {
        $attached = $group->taskable;
        $instance = $attached instanceof Instance ? $attached : $this->workspaces->find($group);
        $backoffKey = $instance instanceof Instance ? 'tasks.workspace-removal.'.$instance->id : null;
        $backoff = is_string($backoffKey) ? $this->readBackoff($backoffKey, 'workspace removal') : null;

        if ($backoff !== null && $backoff['due'] > now()->getTimestamp()) {
            return;
        }
        // A parked task VM starts first. The merge cleanup waits for it without a failure or a backoff.
        if ($instance instanceof Instance && ! app(WakeTaskVmAction::class)->forInstance($instance)) {
            return;
        }

        try {
            if (TaskPullRequestHealth::isReason($group->assistance_reason)) {
                $group->update(TaskAssistance::cleared());
            }
            $this->completeGroup->execute($group, finishWhenRemovalFails: false);
            if (is_string($backoffKey)) {
                $this->rememberBackoff($backoffKey, null, 'workspace removal');
            }
        } catch (TaskWorkspaceWaiting) {
            return;
        } catch (Throwable $exception) {
            TaskAssistance::apply($group, AssistanceKind::Failure, null, RemoveTaskWorkspaceAction::MergeCleanupFailedPrefix.$exception->getMessage(), replaceFailure: true);
            if (is_string($backoffKey)) {
                $this->extendBackoff($backoffKey, $backoff, 'workspace removal');
            }
        }
    }

    /** Pushes the latest stored approval before a cancelled checkout is deleted. A group with no approval is unchanged. */
    private function pushCancelledApproval(Task $group, Instance $instance): void
    {
        if ($group->status !== TaskGroupStatus::Cancelled) {
            return;
        }
        $commit = TaskFinalReview::cancelPushCommit($group);
        if ($commit === null) {
            return;
        }
        $group->setRelation('taskable', $instance);
        $group->loadMissing('project');
        $this->publisher->push($group, $commit);
    }

    private function shouldRemoveWorkspace(Task $group, Instance $instance): bool
    {
        if ($instance->project_id !== $group->project_id || $this->attachedToUnmanagedGroup($instance)) {
            return false;
        }

        $attached = $group->taskable_id === $instance->id;
        $named = $group->taskable_id === null
            && $instance->name === TaskWorkspaceName::for($group)
            && $instance->branch_override === $instance->name;

        if (! $attached && ! $named) {
            return false;
        }

        if (in_array($group->status, [TaskGroupStatus::Cancelled, TaskGroupStatus::Completed], true)) {
            return $attached
                || ! $group->reserved_at instanceof Carbon
                || $group->reserved_at->lessThanOrEqualTo(RemoveTaskWorkspaceAction::reservationCutoff());
        }

        return in_array($group->status, TaskGroupStatus::awaitingCompletion(), true)
            && is_string($group->assistance_reason)
            && str_starts_with($group->assistance_reason, RemoveTaskWorkspaceAction::MergeCleanupFailedPrefix);
    }

    private function releaseRemovedWorkspace(Task $group, int $instanceId): void
    {
        $group->refresh();

        if ($group->taskable_id === $instanceId) {
            $group->taskable()->dissociate();
            $group->save();
        }

        $this->workspaces->clearFailure($group);
    }

    /**
     * @param  array{failures: int, due: int}|null  $backoff
     */
    public function extendBackoff(string $key, ?array $backoff, string $label): void
    {
        $failures = ($backoff['failures'] ?? 0) + 1;
        $delay = self::retryDelaySeconds($failures);
        $this->rememberBackoff($key, ['failures' => $failures, 'due' => now()->addSeconds($delay)->getTimestamp()], $label, $delay * 2);
    }

    /** The next attempt waits until `due`. A missing backoff, or a cache read that fails, means try now. */
    public function retryIsDue(string $key, string $label): bool
    {
        $backoff = $this->readBackoff($key, $label);

        return $backoff === null || $backoff['due'] <= now()->getTimestamp();
    }

    /**
     * Reads a retry backoff. A cache error is logged and read as no backoff, so one bad read never stops the
     * sweep or the tick.
     *
     * @return array{failures: int, due: int}|null
     */
    public function readBackoff(string $key, string $label): ?array
    {
        try {
            $backoff = Cache::get($key);
        } catch (Throwable $exception) {
            Log::warning('The '.$label.' backoff could not be read.', ['key' => $key, 'exception' => $exception::class, 'reason' => $exception->getMessage()]);

            return null;
        }

        return is_array($backoff) && is_int($backoff['failures'] ?? null) && is_int($backoff['due'] ?? null)
            ? ['failures' => $backoff['failures'], 'due' => $backoff['due']]
            : null;
    }

    /**
     * Stores or clears a retry backoff. A cache error is logged and the attempt continues.
     *
     * @param  array{failures: int, due: int}|null  $backoff
     */
    public function rememberBackoff(string $key, ?array $backoff, string $label, int $seconds = 0): bool
    {
        try {
            if ($backoff === null) {
                $written = Cache::forget($key);
                if (! $written && ! Cache::has($key)) {
                    return true;
                }
            } elseif ($seconds === 0) {
                $written = Cache::forever($key, $backoff);
            } else {
                $written = Cache::put($key, $backoff, now()->addSeconds($seconds));
            }

            if (! $written) {
                Log::warning('The '.$label.' backoff could not be written.', ['key' => $key, 'reason' => 'Cache store returned false.']);
            }

            return $written;
        } catch (Throwable $exception) {
            Log::warning('The '.$label.' backoff could not be written.', ['key' => $key, 'exception' => $exception::class, 'reason' => $exception->getMessage()]);

            return false;
        }
    }

    /** A user Instance that backs a non-managed group is never a task workspace the sweep may delete. */
    private function attachedToUnmanagedGroup(Instance $instance): bool
    {
        return Task::topLevel()
            ->where('taskable_type', TaskableType::Instance)
            ->where('taskable_id', $instance->id)
            ->where('execution_mode', '!=', TaskExecutionMode::Managed->value)
            ->exists();
    }

    /** @return Collection<int, Instance> */
    private function abandonedWorkspaces(): Collection
    {
        $workspaceName = match (DB::connection()->getDriverName()) {
            'mysql', 'mariadb' => "CONCAT('task-', tasks.id)",
            default => "'task-' || tasks.id",
        };

        $cutoff = RemoveTaskWorkspaceAction::reservationCutoff();
        $mergePrefix = RemoveTaskWorkspaceAction::MergeCleanupFailedPrefix.'%';

        return Instance::query()
            ->select('instances.*', 'tasks.id as ended_task_group_id')
            ->join('tasks', function ($join) use ($workspaceName): void {
                $join->on('tasks.project_id', '=', 'instances.project_id')
                    ->whereNull('tasks.parent_id')
                    ->where(function ($link) use ($workspaceName): void {
                        $link->whereColumn('tasks.taskable_id', 'instances.id')
                            ->orWhere(function ($named) use ($workspaceName): void {
                                $named->whereRaw("instances.name = {$workspaceName}")
                                    ->whereColumn('instances.branch_override', 'instances.name')
                                    ->whereNull('tasks.taskable_id');
                            });
                    });
            })
            ->where('tasks.execution_mode', TaskExecutionMode::Managed->value)
            ->whereNotExists(function ($userGroup): void {
                $userGroup->selectRaw('1')
                    ->from('tasks as user_groups')
                    ->whereNull('user_groups.parent_id')
                    ->whereColumn('user_groups.taskable_id', 'instances.id')
                    ->where('user_groups.taskable_type', TaskableType::Instance)
                    ->where('user_groups.execution_mode', '!=', TaskExecutionMode::Managed->value);
            })
            ->where(function ($ended) use ($cutoff, $mergePrefix): void {
                $ended->where(function ($finished) use ($cutoff): void {
                    $finished->whereIn('tasks.status', [TaskGroupStatus::Cancelled->value, TaskGroupStatus::Completed->value])
                        ->where(function ($reservation) use ($cutoff): void {
                            $reservation->whereColumn('tasks.taskable_id', 'instances.id')
                                ->orWhereNull('tasks.reserved_at')
                                ->orWhere('tasks.reserved_at', '<=', $cutoff);
                        });
                })->orWhere(function ($settling) use ($mergePrefix): void {
                    $settling->whereIn('tasks.status', TaskGroupStatus::awaitingCompletion())
                        ->where('tasks.assistance_reason', 'like', $mergePrefix);
                });
            })
            ->orderBy('instances.id')
            ->get();
    }

    /**
     * Cancels a todo subtask without starting anything, or cancels a running subtask and starts the next
     * one. `$stop` makes the remote calls that stop a running subtask's implementer and check. It runs
     * outside any database transaction, so a slow Node or agent never holds the Gateway's SQLite write
     * lock. An exception from `$stop` leaves the subtask and its check running. The state change then
     * applies only when the subtask is still running: when it moved on while `$stop` ran, its new state
     * stands and the cancel returns a conflict.
     *
     * @param  Closure(Task): void  $stop
     */
    public function cancelRunningSubtask(TaskScheduler $scheduler, Task $parent, Task $task, Closure $stop): Task
    {
        $parent->requireManagedExecution();
        $candidate = Task::query()->where('parent_id', $parent->id)->findOrFail($task->id);

        if ($candidate->status === TaskStatus::Todo) {
            $group = DB::transaction(function () use ($parent, $task): Task {
                $locked = Task::query()->where('parent_id', $parent->id)->lockForUpdate()->findOrFail($task->id);
                $group = Task::topLevel()->where('execution_mode', TaskExecutionMode::Managed)
                    ->lockForUpdate()
                    ->findOrFail($locked->parent_id);

                if ($locked->status !== TaskStatus::Todo || ! in_array($group->status, [
                    TaskGroupStatus::Todo,
                    TaskGroupStatus::Running,
                    TaskGroupStatus::Reviewing,
                    TaskGroupStatus::Settling,
                    TaskGroupStatus::WaitingForReview,
                ], true)) {
                    throw new ResourceOperationException(
                        errorCode: 'tasks.subtask_not_running',
                        message: __('Only a todo or running subtask can be cancelled.'),
                        status: 409,
                    );
                }

                if (TaskExecutionHold::active($group)) {
                    return $group->fresh(['tasks', 'project', 'taskable']) ?? $group;
                }
                $assistanceReason = $this->markSubtaskCancelled($locked);
                $tasks = $this->runner->lockedTasks($group);
                $this->clearCancelledSubtaskAssistance($group, $locked, $assistanceReason);
                if (! $this->hasOpenSubtask($tasks)) {
                    $group->status = TaskGroupStatus::Settling;
                }
                $group->save();

                return $group->fresh(['tasks', 'project', 'taskable']) ?? $group;
            });

            if (in_array($group->status, TaskGroupStatus::awaitingCompletion(), true)) {
                return $this->settle($scheduler, $group, requestMissingPullRequest: false, checkReturningPullRequest: false);
            }

            return $group->fresh(['tasks', 'project', 'taskable']) ?? $group;
        }

        if ($candidate->status !== TaskStatus::Running) {
            throw new ResourceOperationException(
                errorCode: 'tasks.subtask_not_running',
                message: __('Only a todo or running subtask can be cancelled.'),
                status: 409,
            );
        }

        $stop($candidate);

        $next = null;
        $group = DB::transaction(function () use ($parent, $task, &$next): Task {
            $locked = Task::query()->where('parent_id', $parent->id)->lockForUpdate()->findOrFail($task->id);
            $group = Task::topLevel()->where('execution_mode', TaskExecutionMode::Managed)
                ->lockForUpdate()
                ->findOrFail($locked->parent_id);

            if ($locked->status !== TaskStatus::Running) {
                throw new ResourceOperationException(
                    errorCode: 'tasks.subtask_not_running',
                    message: __('The subtask stopped running while Orbit stopped it, so its new state stands.'),
                    status: 409,
                );
            }

            if (TaskExecutionHold::active($group)) {
                return $group->fresh(['tasks', 'project', 'taskable']) ?? $group;
            }
            $assistanceReason = $this->markSubtaskCancelled($locked);
            $tasks = $this->runner->lockedTasks($group);
            $this->clearCancelledSubtaskAssistance($group, $locked, $assistanceReason);
            if (RequestEndedPullRequestAssistanceAction::isReason($group->assistance_reason)) {
                return $group->fresh(['tasks', 'project', 'taskable']) ?? $group;
            }

            $next = $this->runner->lowestTodo($tasks);
            if ($next instanceof Task) {
                try {
                    $this->runner->markRunning($next, $tasks);
                    $group->status = TaskGroupStatus::Running;
                } catch (TaskSequenceException) {
                    $next = null;
                    $group->status = $this->statusWithOpenSubtasks($group, $tasks);
                }
            } elseif ($this->hasOpenSubtask($tasks)) {
                $group->status = $this->statusWithOpenSubtasks($group, $tasks);
            } else {
                $group->status = TaskGroupStatus::Settling;
            }
            $group->save();

            return $group->fresh(['tasks', 'project', 'taskable']) ?? $group;
        });

        if ($next instanceof Task && $next->status === TaskStatus::Running) {
            try {
                $this->runner->beginRunningTask($scheduler, $next);
            } catch (TaskWorkspaceWaiting) {
                // Cancellation succeeded; the next subtask keeps its FIFO admission.
            }
        }

        if (in_array($group->status, TaskGroupStatus::awaitingCompletion(), true)) {
            return $this->settle($scheduler, $group);
        }

        return $group->fresh(['tasks', 'project', 'taskable']) ?? $group;
    }

    public function settle(TaskScheduler $scheduler,
        Task $group,
        bool $requestMissingPullRequest = true,
        bool $checkReturningPullRequest = true,
    ): Task {
        return app(TaskWorkspaceLease::class)->run($group, 'settle', 'settle:'.$group->id, fn () => $this->settleWithLease($scheduler, $group, $requestMissingPullRequest, $checkReturningPullRequest));
    }

    private function settleWithLease(TaskScheduler $scheduler,
        Task $group,
        bool $requestMissingPullRequest = true,
        bool $checkReturningPullRequest = true,
    ): Task {
        $group->requireManagedExecution();
        $group->loadMissing(['project', 'tasks', 'taskable']);

        if (! in_array($group->status, TaskGroupStatus::awaitingCompletion(), true)) {
            return $group->fresh(['tasks', 'project', 'taskable']) ?? $group;
        }

        $url = $group->pr_url;

        if (TaskFinalReview::due($group, $group->tasks)) {
            $scheduler->beginFinalReview($group);

            return $group->fresh(['tasks', 'project', 'taskable']) ?? $group;
        }

        if (! is_string($url) || $url === '') {
            if ($requestMissingPullRequest && ! $this->runner->lowestTodo($group->tasks) instanceof Task) {
                $this->requestMissingPullRequest($group);
            }

            return $group->fresh(['tasks', 'project', 'taskable']) ?? $group;
        }

        // ADR 0164: returning to settling refreshes metrics and does not post task_group.settled again.
        $returning = $group->settled_at !== null;
        if ($returning && $checkReturningPullRequest) {
            $scheduler->checkReturningPullRequest($group);
        }
        $metrics = $this->metrics->collect($group);
        $group->tokens = $metrics->tokens;
        $group->line_diff = $metrics->lineDiff;
        $group->duration_ms = $metrics->durationMs;
        $group->questions = $metrics->questions;
        $group->escalations = $metrics->escalations;
        $group->settled_at ??= now();
        if ($group->status === TaskGroupStatus::Settling) {
            $group->status = TaskGroupStatus::WaitingForReview;
        }
        $group->save();

        $settled = $group->fresh(['tasks', 'project', 'taskable']) ?? $group;

        if ($settled->notify_coder && ! $returning) {
            $this->coder->notify($settled);
        }

        return $settled->fresh(['tasks', 'project', 'taskable']) ?? $settled;
    }

    /**
     * Appends one fixup when a current problem still has one left, or asks for assistance when every
     * current problem is at the cap. A waiting subtask starts instead, and no fixup is appended.
     *
     * ADR 0164 bounds the fixups. No fixup is appended while the head is the one the last fixup was
     * created for, or while a check on the head has been pending for 60 minutes or less. A completed
     * genuine failure is still reported during that wait. A conflict does not wait. A check pending for
     * more than 60 minutes is infrastructure, with cancelled checks and checks that could not start.
     * A fixup that changed nothing asks for assistance instead of a second try on the same result.
     * A group keeps at most three Gateway fixups in total.
     */
    private function healOpenPullRequest(TaskScheduler $scheduler, Task $group, TaskPullRequestHealth $health, ?TaskReviewObservation $reviews): void
    {
        if ($this->hasBusyTask($group->tasks)) {
            return;
        }

        // An operator subtask resumes past a review-and-merge request that waits for one (ADR 0203).
        if ($this->runner->lowestTodo($group->tasks) instanceof Task
            && (! $this->otherAssistance($group) || TaskFinalReview::isResumableReason($group->assistance_reason))) {
            $this->resumeWaitingSubtask($scheduler, $group);

            return;
        }

        if ($this->otherAssistance($group)) {
            return;
        }

        // ADR 0203: unreviewed work gets its final review first. No settling fixup builds on it meanwhile.
        if ($group->reviewsBeforePush() && TaskFinalReview::hasUnreviewedWork($group)) {
            $scheduler->beginFinalReview($group);

            return;
        }

        // ADR 0203: GitHub's merge commit would land without Orbit's review, so a review-and-merge task never
        // asks GitHub to update its branch. A conflict gets a merge fixup instead, and a behind branch still merges.
        if (($health->conflicts || $health->behind) && ! $group->reviewsBeforePush()) {
            $update = is_string($health->headSha) && $health->headSha !== ''
                ? $this->pullRequestUpdater->updateBranch($group, $health->headSha)
                : TaskBranchUpdate::Unavailable;
            if ($update !== TaskBranchUpdate::Conflict) {
                $this->reportPullRequestHealth($group, new TaskPullRequestHealth('open', $update === TaskBranchUpdate::Accepted ? [] : [
                    'GitHub could not update the base branch. Orbit will retry after a fresh observation.',
                ]));

                return;
            }
            if (! $health->conflicts) {
                // A concurrent base change can reveal a conflict after the health read.
                return;
            }
        }

        if ($health->infrastructureChecks === []) {
            $this->rememberBackoff($this->infrastructureBackoffKey($group), null, 'infrastructure check');
        }

        if ($health->problems === []) {
            $this->reportPullRequestHealth($group, $health);
        }

        $fixups = $this->runner->orderedTasks($group->tasks)
            ->filter(static fn (Task $task): bool => $task->isSettlingFixup())
            ->values();
        $latest = $fixups->last();
        if ($latest instanceof Task && is_string($latest->fixup_head_sha) && $latest->fixup_head_sha === $health->headSha) {
            if ($this->fixupChangedNothing($latest)) {
                $reason = 'Fixup subtask #'.$latest->id.' changed nothing, so Orbit does not try again on the same result.';
                if ($health->problems === [] && ($reviews?->selection->requests ?? []) !== []) {
                    $this->reviewFeedbackAssistance($group, $reason, 'unchanged-head');
                } else {
                    $this->reportPullRequestHealth($group, $health, $reason);
                }
            }

            return;
        }

        $this->changeReviewFeedback($group, 'unchanged-head', null);

        // A check pending for 60 minutes or less is not a result yet. Report a completed genuine
        // failure, and append no check fixup. A conflict does not wait. Infrastructure backoff starts
        // only once every current problem is infrastructure and nothing is still inside that span.
        if ($health->checksYoungPending && ! $health->conflicts) {
            if ($health->failedChecks !== []) {
                $this->reportPullRequestHealth($group, $health);
            }

            return;
        }

        if (! $health->conflicts && $health->failedChecks === [] && $health->infrastructureChecks !== []) {
            $this->awaitInfrastructureChecks($group, $health);

            return;
        }

        $plan = $this->nextFixup($group, $health, $health->checksYoungPending);
        if (! $plan instanceof TaskSettlingFixup && ! $health->checksPending && $health->infrastructureChecks === []) {
            if ($this->appendReviewFeedback($group, $health, $reviews)) {
                $this->resumeWaitingSubtask($scheduler, $group);

                return;
            }
        }
        if (! $plan instanceof TaskSettlingFixup) {
            $this->reportPullRequestHealth(
                $group,
                $health,
                $this->reachedFixupIdentityCaps($group, $health, $health->checksYoungPending),
            );

            return;
        }

        if (array_sum($this->fixupCountsSinceOperatorWork($group)) >= TaskSettlingFixup::GroupLimit) {
            $this->reportPullRequestHealth($group, $health, 'Orbit already appended '.TaskSettlingFixup::GroupLimit.' fixups to this group.');

            return;
        }

        if ($this->appendFixup($group, $this->mergeBaseFirstWhenGreenAhead($group, $health, $plan), $health->headSha) instanceof Task) {
            $this->resumeWaitingSubtask($scheduler, $group);
        }
    }

    /**
     * A failed check may already be fixed on the base. When the base tip passed the merge check, or
     * `Required checks` without one, and is strictly ahead of the head's merge base, the fixup merges the base first.
     * The GitHub reads run before appendFixup takes its lock.
     */
    private function mergeBaseFirstWhenGreenAhead(Task $group, TaskPullRequestHealth $health, TaskSettlingFixup $plan): TaskSettlingFixup
    {
        if (! str_starts_with($plan->identity, 'check:') || ! is_string($health->baseRef) || $health->baseRef === ''
            || ! is_string($health->headSha) || $health->headSha === '') {
            return $plan;
        }
        $check = $group->project->mergeCheckName() ?? TaskPullRequestCheck::ROLLUP_NAMES[0];

        return $this->merger->baseTipGreenAhead($group, $health->baseRef, $health->headSha, $check)
            ? $plan->mergingBaseFirst($health->baseRef)
            : $plan;
    }

    private static function isReviewFeedbackReason(?string $reason): bool
    {
        return TaskGitHubReviewFeedback::isReason($reason);
    }

    /** Source-specific causes are durable; presentation never replaces unrelated assistance. */
    private function changeReviewFeedback(Task $group, string $source, ?string $reason): void
    {
        if ($this->reviewFeedback->change($group, [$source => $reason])) {
            $this->broadcasts->groupChanged($group->id);
            if ($group->assistance_requested && is_string($group->assistance_reason)) {
                $this->coder->assistance($group, $group->assistance_reason);
            }
        }
    }

    private function reviewFeedbackAssistance(Task $group, string $reason, string $source): void
    {
        $this->changeReviewFeedback($group, $source, $reason);
    }

    private function reportMissingReviewFixups(Task $group): void
    {
        $missing = $this->reviewConsumption->missingFixups($group);
        foreach ($this->reviewFeedback->causes($group) as $source => $reason) {
            if (str_starts_with($source, 'missing:') && ! isset($missing[$source])) {
                $this->changeReviewFeedback($group, $source, null);
            }
        }
        foreach ($missing as $source => $reason) {
            $this->reviewFeedbackAssistance($group, $reason, $source);
        }
    }

    private function reviewReadBackoffKey(Task $group, string $operation): string
    {
        return 'tasks.github-review-read.'.$group->id.($operation === 'list' ? '' : '.'.$operation);
    }

    private function reviewReadResult(Task $group, TaskReviewReadStatus $status, string $operation = 'list'): void
    {
        $key = $this->reviewReadBackoffKey($group, $operation);
        if ($status === TaskReviewReadStatus::Unreadable) {
            $backoff = $this->readBackoff($key, 'GitHub review read');
            if ($backoff !== null && $backoff['failures'] >= TaskScheduler::InfrastructureCheckRetries) {
                $this->reviewFeedbackAssistance($group, 'The trusted review source remains unreadable ('.$operation.'). Restore review-read access.', $operation);
            }
            $this->extendBackoff($key, $backoff, 'GitHub review read');

            return;
        }
        if ($status === TaskReviewReadStatus::Changed) {
            return;
        }
        if ($status === TaskReviewReadStatus::Overflow) {
            $this->reviewFeedbackAssistance($group, 'The complete GitHub review source exceeds the pagination limit ('.$operation.'). Split the findings or append a scoped operator subtask.', $operation);

            return;
        }
        $this->rememberBackoff($key, null, 'GitHub review read');
        if ($status === TaskReviewReadStatus::InvalidTrust) {
            $this->reviewFeedbackAssistance($group, 'The operator reviewer configuration is invalid. Correct the trusted numeric accounts.', 'trust');

            return;
        }
        $this->changeReviewFeedback($group, $operation, null);
        if ($operation === 'list') {
            $this->changeReviewFeedback($group, 'trust', null);
        }
    }

    private function observeReviewFeedback(Task $group): ?TaskReviewObservation
    {
        if (! $this->retryIsDue('tasks.github-review-read.'.$group->id, 'GitHub review read')) {
            return null;
        }
        $observation = $this->reviewWatcher->reviews($group, fresh: true);
        $this->reviewReadResult($group, $observation->status);
        if (in_array($observation->status, [TaskReviewReadStatus::Complete, TaskReviewReadStatus::Disabled], true)) {
            $eligible = array_map(static fn ($review): int => $review->id, $observation->selection->requests ?? []);
            if ($eligible === []) {
                $this->changeReviewFeedback($group, 'unchanged-head', null);
            }
            foreach ($this->reviewFeedback->causes($group) as $source => $reason) {
                if (preg_match('/^review:([0-9]+):/', $source, $match) === 1 && ! in_array((int) $match[1], $eligible, true)) {
                    $this->rememberBackoff($this->reviewReadBackoffKey($group, $source), null, 'GitHub review read');
                    $this->changeReviewFeedback($group, $source, null);
                }
            }
        }

        return $observation;
    }

    /** Final source reads stay outside the append transaction. Local eligibility is checked again inside it. */
    private function appendReviewFeedback(Task $group, TaskPullRequestHealth $health, ?TaskReviewObservation $observation): bool
    {
        if ($observation?->status !== TaskReviewReadStatus::Complete || $observation->repository === null || $observation->number === null) {
            return false;
        }
        $dispatchGroup = clone $group;
        $counts = $this->fixupCountsSinceOperatorWork($group);
        foreach ($observation->selection->requests ?? [] as $review) {
            if ($this->reviewConsumption->consumed($group, $observation->repository->owner.'/'.$observation->repository->name, $observation->number, $review->id)) {
                continue;
            }
            if (($counts['review:'.$review->reviewerId] ?? 0) >= TaskSettlingFixup::Limit || array_sum($counts) >= TaskSettlingFixup::GroupLimit) {
                $this->reviewFeedbackAssistance($group, 'The automatic fixup cap was reached for account '.$review->reviewerId.' or this group. Append a scoped operator subtask.', 'review:'.$review->id.':cap');

                continue;
            }
            $this->changeReviewFeedback($group, 'review:'.$review->id.':cap', null);
            $findings = 'review:'.$review->id.':findings';
            $validation = 'review:'.$review->id.':validation';
            if (! $this->retryIsDue($this->reviewReadBackoffKey($group, $findings), 'GitHub review findings')
                || ! $this->retryIsDue($this->reviewReadBackoffKey($group, $validation), 'GitHub review revalidation')) {
                continue;
            }
            $result = $this->reviewWatcher->reviewCandidate($group, $review->id, fresh: true);
            $this->reviewReadResult($group, $result->status, $findings);
            if ($result->status !== TaskReviewReadStatus::Complete || $result->candidate === null) {
                return false;
            }
            $candidate = $result->candidate;
            if ($candidate->head !== $health->headSha) {
                return false;
            }
            try {
                $packet = TaskReviewFindingsPacket::fromCandidate($candidate);
            } catch (\InvalidArgumentException|\LengthException $exception) {
                $this->reviewFeedbackAssistance($group, $exception->getMessage(), 'review:'.$review->id.':packet');

                return false;
            }
            $this->changeReviewFeedback($group, 'review:'.$review->id.':packet', null);
            $result = $this->reviewWatcher->revalidateReviewCandidate($group, $candidate);
            $this->reviewReadResult($group, $result->status, $validation);
            if ($result->status !== TaskReviewReadStatus::Complete || $result->candidate === null) {
                return false;
            }

            return $this->appendFixup($dispatchGroup, TaskSettlingFixup::reviewPlan($dispatchGroup->project->taskCheckCommand(), $packet), $candidate->head, $candidate) instanceof Task;
        }

        return false;
    }

    /**
     * Whether a fixup left the pull request head where it was: it never reached an approval, or its
     * approved commit is the head it was created for. A fixup that did commit waits for GitHub to see the push.
     */
    private function fixupChangedNothing(Task $fixup): bool
    {
        $commit = TaskComment::query()
            ->where('task_id', $fixup->id)
            ->where('type', TaskCommentType::Approved)
            ->whereNotNull('commit_sha')
            ->latest('id')
            ->value('commit_sha');

        return ! is_string($commit) || $commit === '' || $commit === $fixup->fixup_head_sha;
    }

    /**
     * Only cancelled checks, checks that could not start, or checks pending for more than 60 minutes
     * stand between the pull request and a merge. Those are not the branch's fault, so no fixup is
     * appended. The group re-evaluates on the backoff of 1, 2, 5, 10, and 30 minutes, and asks for
     * assistance when they persist after that.
     */
    private function awaitInfrastructureChecks(Task $group, TaskPullRequestHealth $health): void
    {
        $key = $this->infrastructureBackoffKey($group);
        $backoff = $this->readBackoff($key, 'infrastructure check');
        if ($backoff !== null && $backoff['due'] > now()->getTimestamp()) {
            return;
        }
        if ($backoff !== null && $backoff['failures'] >= TaskScheduler::InfrastructureCheckRetries) {
            $this->reportPullRequestHealth($group, $health, 'Those checks were cancelled or could not start, and did not recover. Re-run them.');

            return;
        }
        $this->extendBackoff($key, $backoff, 'infrastructure check');
    }

    private function infrastructureBackoffKey(Task $group): string
    {
        return 'tasks.pull-request-infrastructure.'.$group->id;
    }

    /** Whether assistance was requested for a cause other than the open pull request's own problems. */
    private function otherAssistance(Task $group): bool
    {
        return $group->assistance_requested && ! TaskPullRequestHealth::isReason($group->assistance_reason)
            && ! self::isReviewFeedbackReason($group->assistance_reason);
    }

    /** Review-source problems are observations, not a hold on otherwise authorized ongoing work. */
    public function progressBlockedByAssistance(Task $group): bool
    {
        return $group->assistance_requested && ! self::isReviewFeedbackReason($group->assistance_reason);
    }

    /**
     * A baseline that failed on a red default-branch commit retries once the branch tip is green and contains
     * that commit, as an operator resolution would. GitHub is read before the lock, at most every five minutes
     * per check. The queue then locks the rows and validates the retry again. The recovery after it resets.
     */
    public function queueBaselineRetryOnGreenTip(Task $group, Task $task): bool
    {
        $check = $this->retryBaseline->unrequested($task);
        if (! $check instanceof TaskCheck || ! Cache::add('tasks.baseline-green-tip.'.$check->id, true, 300)) {
            return false;
        }
        $name = $group->project->mergeCheckName() ?? TaskPullRequestCheck::ROLLUP_NAMES[0];
        if ($this->merger->requiredCheck($group, $check->head_before, $name) !== RequiredCheckState::Failed) {
            return false;
        }
        $tip = $this->merger->greenDefaultTipAfter($group, $check->head_before, $name);
        if ($tip === null) {
            return false;
        }

        return TaskExecutionHold::run($group, fn (): bool => DB::transaction(function () use ($task, $check, $name, $tip): bool {
            if ($this->retryBaseline->unrequested($task)?->id !== $check->id) {
                return false;
            }
            $comment = TaskComment::query()->create([
                'task_group_id' => $task->parent_id, 'task_id' => $task->id, 'completion_attempt' => $task->completion_attempt,
                'type' => TaskCommentType::Resolution, 'author' => 'orbit', 'posted_at' => now(),
                'body' => 'The baseline failed on '.$check->head_before.', where '.$name.' failed. The default branch tip '.$tip
                    .' contains it and passed '.$name.', so Orbit retries the baseline.',
            ]);

            return $this->retryBaseline->queue($task, $comment);
        })) === true;
    }

    /** @param  Collection<int, Task>  $tasks */
    private function hasBusyTask(Collection $tasks): bool
    {
        return $tasks->contains(
            static fn (Task $task): bool => in_array($task->status, [TaskStatus::Running, TaskStatus::Reviewing], true),
        );
    }

    private function nextFixup(Task $group, TaskPullRequestHealth $health, bool $conflictOnly = false): ?TaskSettlingFixup
    {
        $counts = $this->fixupCountsSinceOperatorWork($group);

        foreach (TaskSettlingFixup::plans($group->project->taskCheckCommand(), $health->conflicts, $health->baseRef, $health->failedChecks) as $plan) {
            if ($conflictOnly && $plan->conflictBase() === null) {
                continue;
            }
            if (($counts[$plan->identity] ?? 0) < TaskSettlingFixup::Limit) {
                return $plan;
            }
        }

        return null;
    }

    /** Explain each current problem whose per-identity cap has been reached in the active window. */
    private function reachedFixupIdentityCaps(Task $group, TaskPullRequestHealth $health, bool $conflictOnly): ?string
    {
        $counts = $this->fixupCountsSinceOperatorWork($group);
        $reasons = [];

        foreach (TaskSettlingFixup::plans($group->project->taskCheckCommand(), $health->conflicts, $health->baseRef, $health->failedChecks) as $plan) {
            if ($conflictOnly && $plan->conflictBase() === null) {
                continue;
            }
            $count = $counts[$plan->identity] ?? 0;
            if ($count >= TaskSettlingFixup::Limit) {
                $reasons[] = 'Orbit reached the cap of '.TaskSettlingFixup::Limit.' fixups for '.$plan->identity.' in the current window ('.$count.' counted).';
            }
        }

        return $reasons === [] ? null : implode(' ', $reasons);
    }

    /** @return array<string, int> */
    private function fixupCountsSinceOperatorWork(Task $group): array
    {
        $counts = $this->reviewConsumption->orphanCharges($group, $group->tasks);
        foreach ($this->fixupsSinceOperatorWork($this->runner->orderedTasks($group->tasks)) as $task) {
            if ($task->isSettlingFixup()) {
                $counts[(string) $task->fixup_problem] = ($counts[(string) $task->fixup_problem] ?? 0) + 1;
            }
        }

        return $counts;
    }

    /**
     * Fixup caps include only entries positioned after the latest completed non-fixup task.
     * Task order is defined by position (with id as a tie-breaker), so position defines this reset boundary.
     * Every fixup status counts; unfinished non-fixup tasks do not reset the window.
     *
     * @param  Collection<int, Task>  $tasks
     * @return Collection<int, Task>
     */
    private function fixupsSinceOperatorWork(Collection $tasks): Collection
    {
        $lastCompletedOperatorPosition = $tasks
            ->filter(static fn (Task $task): bool => $task->isOperatorWork() && $task->status === TaskStatus::Completed)
            ->max('position');

        return $tasks
            ->filter(static fn (Task $task): bool => is_string($task->fixup_problem)
                && $task->fixup_problem !== ''
                && ($lastCompletedOperatorPosition === null || $task->position > $lastCompletedOperatorPosition))
            ->values();
    }

    private function appendFixup(Task $group, TaskSettlingFixup $plan, ?string $headSha, ?TaskReviewCandidate $candidate = null): ?Task
    {
        return DB::transaction(function () use ($group, $plan, $headSha, $candidate): ?Task {
            $locked = Task::topLevel()->where('execution_mode', TaskExecutionMode::Managed)
                ->lockForUpdate()
                ->findOrFail($group->id);
            if (! in_array($locked->status, TaskGroupStatus::awaitingCompletion(), true) || $this->otherAssistance($locked)) {
                return null;
            }

            $tasks = $this->runner->lockedTasks($locked);
            if ($this->hasBusyTask($tasks) || $this->runner->lowestTodo($tasks) instanceof Task) {
                return null;
            }
            $locked->setRelation('tasks', $tasks);
            if ($candidate !== null) {
                $trust = TaskReviewTrust::fromConfig($candidate->repository, config('orbit.tasks.github_reviewers', []));
                if ($locked->pr_url !== $group->pr_url || $locked->project_id !== $group->project_id
                    || ! is_string($locked->pr_url) || $candidate->repository->pullRequestNumber($locked->pr_url) !== $candidate->number
                    || ! $trust->valid || $trust->revision !== $candidate->trustRevision
                    || $this->reviewConsumption->consumed($locked, $candidate->repository->owner.'/'.$candidate->repository->name, $candidate->number, $candidate->review->id)) {
                    return null;
                }
            }
            $latest = $this->runner->orderedTasks($tasks)->filter(static fn (Task $task): bool => $task->isSettlingFixup())->last();
            if ($latest instanceof Task && $latest->fixup_head_sha === $headSha) {
                return null;
            }
            $counts = $this->fixupCountsSinceOperatorWork($locked);
            $count = $counts[$plan->identity] ?? 0;
            $total = array_sum($counts);
            if ($count >= TaskSettlingFixup::Limit || $total >= TaskSettlingFixup::GroupLimit) {
                return null;
            }

            $fixup = Task::query()->create([
                'parent_id' => $locked->id,
                'position' => StoredInteger::fromOrZero($tasks->max('position')) + 1,
                'title' => $plan->title,
                'brief' => $plan->brief,
                'deliverables' => $plan->deliverables,
                'fixup_problem' => $plan->identity,
                'fixup_head_sha' => $headSha,
                'status' => TaskStatus::Todo,
            ]);
            if ($candidate !== null) {
                $this->reviewConsumption->record($locked, $fixup, $candidate, $tasks);
            }

            return $fixup;
        });
    }

    /**
     * Starts a later subtask left todo after the group returned to running, including a conflict fixup
     * whose base fetch failed. The group's first subtask is started by claim, not by this retry.
     */
    public function resumeStrandedSubtask(TaskScheduler $scheduler, Task $group): void
    {
        if ($this->progressBlockedByAssistance($group) || $this->hasBusyTask($group->tasks)) {
            return;
        }

        $todo = $this->runner->lowestTodo($group->tasks);
        if (! $todo instanceof Task || ! $this->followedAnotherSubtask($todo, $group->tasks)) {
            return;
        }

        $this->resumeWaitingSubtask($scheduler, $group);
    }

    /**
     * Returns a settling group to running and starts its lowest todo subtask. A check fixup or an operator
     * subtask becomes running in that same commit; the implementer starts afterwards. A conflict fixup
     * returns the group to running before the base fetch, and a failed fetch leaves that subtask todo.
     */
    public function resumeWaitingSubtask(TaskScheduler $scheduler, Task $group): void
    {
        TaskExecutionHold::run($group, fn () => $this->resumeAdmittedSubtask($scheduler, $group));
    }

    private function resumeAdmittedSubtask(TaskScheduler $scheduler, Task $group): void
    {
        if ($group->relationLoaded('tasks') && $this->hasBusyTask($group->tasks)) {
            return;
        }

        $group = $group->fresh(['project', 'tasks', 'taskable']) ?? $group;
        if (! in_array($group->status, [TaskGroupStatus::Settling, TaskGroupStatus::WaitingForReview, TaskGroupStatus::Running], true)) {
            return;
        }
        if ($group->status === TaskGroupStatus::Running && $this->progressBlockedByAssistance($group)) {
            return;
        }
        if (in_array($group->status, TaskGroupStatus::awaitingCompletion(), true) && self::resumeBlocked($group)) {
            return;
        }
        if ($this->hasBusyTask($group->tasks)) {
            return;
        }

        $todo = $this->runner->lowestTodo($group->tasks);
        if (! $todo instanceof Task) {
            return;
        }

        if (! app(WakeTaskVmAction::class)->forGroup($group)) {
            return;
        }

        $base = $this->conflictBase($todo);
        if ($base !== null && in_array($group->status, TaskGroupStatus::awaitingCompletion(), true)) {
            $this->leaveSettling($group);
        }
        if (! $this->prepareResumedWorkspace($scheduler, $group, $todo)) {
            return;
        }

        $started = $this->activateResumedTask($todo);
        if ($started instanceof Task) {
            $this->runner->beginRunningTask($scheduler, $started, alreadyFetched: true);
        }
    }

    /**
     * Reuses the general turn fetch, then fast-forwards the workspace to `origin/task-{group id}` when
     * it is strictly behind. A failure waits out #760's backoff of 1, 2,
     * 5, 10, and 30 minutes, leaves the subtask todo, and asks for assistance on the fifth failure.
     * On a group with no pull request, a missing `origin/task-{group id}` is not a failure.
     */
    private function prepareResumedWorkspace(TaskScheduler $scheduler, Task $group, Task $todo): bool
    {
        return app(TaskWorkspaceLease::class)->run($group, 'main_merge', 'resume:'.$todo->id, fn () => $this->prepareResumedWorkspaceWithLease($scheduler, $group, $todo), taskId: $todo->id);
    }

    private function prepareResumedWorkspaceWithLease(TaskScheduler $scheduler, Task $group, Task $todo): bool
    {
        $key = 'tasks.resume-fetch.'.$todo->id;
        if (! $this->retryIsDue($key, 'resume fetch')) {
            return false;
        }
        try {
            $fresh = $group->fresh(['project', 'taskable']) ?? $group;
            $this->turnFetcher->fetch($fresh);
            $this->bases->fastForward($fresh, ! is_string($fresh->pr_url) || $fresh->pr_url === '');
        } catch (TaskWorkspaceWaiting) {
            return false;
        } catch (Throwable $exception) {
            $this->extendBackoff($key, $this->readBackoff($key, 'resume fetch'), 'resume fetch');
            $scheduler->recordCommunicationFailure($todo, $group, $exception->getMessage());

            return false;
        }
        $this->rememberBackoff($key, null, 'resume fetch');
        $scheduler->clearCommunicationFailures($todo);

        return true;
    }

    /** Records the return to running before a conflict fixup's base fetch, which stays outside the commit. */
    private function leaveSettling(Task $group): void
    {
        $this->clearResumeAssistance($group);
        $group->status = TaskGroupStatus::Running;
        $group->save();
    }

    /**
     * Marks the resumed subtask running, and returns a settling group to running, in one transaction.
     * The caller starts the implementer after the commit.
     */
    private function activateResumedTask(Task $todo): ?Task
    {
        try {
            return DB::transaction(function () use ($todo): ?Task {
                $locked = Task::query()->lockForUpdate()->findOrFail($todo->id);
                $group = Task::topLevel()->where('execution_mode', TaskExecutionMode::Managed)
                    ->lockForUpdate()
                    ->findOrFail($locked->parent_id);
                if (TaskExecutionHold::active($group) || ! in_array($group->status, [TaskGroupStatus::Settling, TaskGroupStatus::WaitingForReview, TaskGroupStatus::Running], true)) {
                    return null;
                }
                if ($group->status === TaskGroupStatus::Running && $this->progressBlockedByAssistance($group)) {
                    return null;
                }
                if (in_array($group->status, TaskGroupStatus::awaitingCompletion(), true) && self::resumeBlocked($group)) {
                    return null;
                }

                $tasks = $this->runner->lockedTasks($group);
                if ($this->hasBusyTask($tasks)) {
                    return null;
                }
                if (in_array($group->status, TaskGroupStatus::awaitingCompletion(), true)) {
                    $this->clearResumeAssistance($group);
                    $group->status = TaskGroupStatus::Running;
                    $group->save();
                }

                $this->runner->markRunning($locked, $tasks);

                return $locked->fresh(['parent.tasks', 'parent.project', 'parent.taskable']) ?? $locked;
            });
        } catch (TaskSequenceException) {
            return null;
        }
    }

    /** @param  Collection<int, Task>  $tasks */
    private function followedAnotherSubtask(Task $task, Collection $tasks): bool
    {
        return $this->runner->orderedTasks($tasks)->contains(
            static fn (Task $candidate): bool => $candidate->position < $task->position
                || ($candidate->position === $task->position && $candidate->id < $task->id),
        );
    }

    /** The base ref stored on a conflict fixup, or null when the subtask is not one. */
    public function conflictBase(Task $task): ?string
    {
        $problem = $task->fixup_problem;
        if (! is_string($problem) || ! str_starts_with($problem, 'conflict:')) {
            return null;
        }

        return substr($problem, strlen('conflict:'));
    }

    private function requestMissingPullRequest(Task $group): void
    {
        $reason = TaskScheduler::MissingPullRequestPrefix.' Cancel the group to push its approved commits to task-'.$group->id.' and remove its workspace.';
        if (TaskAssistance::apply($group, AssistanceKind::Failure, null, $reason)) {
            $this->coder->assistance($group, $reason);
        }
    }

    public static function isMissingPullRequestReason(?string $reason): bool
    {
        return is_string($reason) && str_starts_with($reason, TaskScheduler::MissingPullRequestPrefix);
    }

    /** Another assistance cause blocks a resume. The missing-pull-request reason does not. */
    public static function resumeBlocked(Task $group): bool
    {
        return $group->assistance_requested
            && ! TaskPullRequestHealth::isReason($group->assistance_reason)
            && ! self::isMissingPullRequestReason($group->assistance_reason)
            && ! self::isReviewFeedbackReason($group->assistance_reason)
            && ! TaskFinalReview::isResumableReason($group->assistance_reason);
    }

    /** Clears the pull-request and missing-pull-request reasons when a resumed subtask starts. */
    private function clearResumeAssistance(Task $group): void
    {
        if (! TaskPullRequestHealth::isReason($group->assistance_reason) && ! self::isMissingPullRequestReason($group->assistance_reason)
            && ! TaskFinalReview::isResumableReason($group->assistance_reason)) {
            return;
        }
        $group->fill(TaskAssistance::cleared());
    }

    /**
     * Asks for assistance once per distinct set of pull request problems, and withdraws only its own
     * request when the pull request is healthy again. Another cause of assistance is left alone.
     */
    private function reportPullRequestHealth(Task $group, TaskPullRequestHealth $health, ?string $extra = null): void
    {
        $group->refresh();
        $ownRequest = TaskPullRequestHealth::isReason($group->assistance_reason);

        if ($health->problems === []) {
            if ($ownRequest) {
                $group->update(TaskAssistance::cleared());
            }

            return;
        }

        $reason = $health->reason($extra);
        if ($group->assistance_requested && (! $ownRequest || $group->assistance_reason === $reason)) {
            return;
        }

        if (TaskAssistance::apply($group, AssistanceKind::Failure, null, $reason, replaceFailure: $ownRequest)) {
            $this->coder->assistance($group, $reason);
        }
    }

    private function markSubtaskCancelled(Task $task): ?string
    {
        TaskCheck::query()->where('task_id', $task->id)
            ->where('status', TaskCheckStatus::Running->value)
            ->update(['status' => TaskCheckStatus::Cancelled->value, 'finished_at' => now(), 'updated_at' => now()]);

        $assistanceReason = $task->assistance_reason;
        $task->update([
            'status' => TaskStatus::Cancelled,
            'settled_at' => now(),
            'completion_summary' => 'Cancelled by operator.',
            ...TaskAssistance::cleared(),
        ]);

        return $assistanceReason;
    }

    private function clearCancelledSubtaskAssistance(Task $group, Task $task, ?string $assistanceReason): void
    {
        if (RequestEndedPullRequestAssistanceAction::isReason($group->assistance_reason)
            || ! $group->assistance_requested || $assistanceReason === null || $group->assistance_reason !== $assistanceReason) {
            return;
        }

        $otherAssistance = $group->tasks()
            ->whereKeyNot($task->id)
            ->where('assistance_requested', true)
            ->exists();
        if (! $otherAssistance) {
            $group->fill(TaskAssistance::cleared());
        }
    }

    /** @param  Collection<int, Task>  $tasks */
    private function hasOpenSubtask(Collection $tasks): bool
    {
        return $tasks->contains(static fn (Task $task): bool => in_array($task->status, [
            TaskStatus::Todo,
            TaskStatus::Reserved,
            TaskStatus::Running,
            TaskStatus::Reviewing,
        ], true));
    }

    /** @param  Collection<int, Task>  $tasks */
    private function statusWithOpenSubtasks(Task $group, Collection $tasks): TaskGroupStatus
    {
        if ($this->runner->runningSibling($tasks) instanceof Task || $tasks->contains(static fn (Task $task): bool => $task->status === TaskStatus::Running)) {
            return TaskGroupStatus::Running;
        }
        if ($tasks->contains(static fn (Task $task): bool => $task->status === TaskStatus::Reviewing)) {
            return TaskGroupStatus::Reviewing;
        }
        if ($tasks->contains(static fn (Task $task): bool => $task->status === TaskStatus::Reserved)) {
            return TaskGroupStatus::Reserved;
        }

        return $group->groupStatus();
    }
}
