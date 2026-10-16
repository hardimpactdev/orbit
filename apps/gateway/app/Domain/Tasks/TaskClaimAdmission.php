<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Actions\Tasks\RemoveTaskWorkspaceAction;
use App\Actions\TaskVms\FailTaskVmAction;
use App\Domain\TaskVms\TaskVmFailedException;
use App\Domain\TaskVms\TaskVmState;
use App\Models\Activity;
use App\Models\Instance;
use App\Models\Task;
use App\Models\TaskVm;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

final readonly class TaskClaimAdmission
{
    private const string WorkspaceStartedActivity = 'Task workspace started.';

    public function __construct(
        private TaskSubtaskRunner $runner,
        private InstanceProvisioning $provisioning,
        private CoderSettleNotifier $coder,
        private TaskBroadcasts $broadcasts,
        private RemoveTaskWorkspaceAction $workspaces,
        private FailTaskVmAction $failedVms,
    ) {}

    /**
     * Claims the oldest todo group that fits, provisions its Instance, and starts its first task.
     *
     * @param  list<int>  $skipped  Groups whose provisioning or start failed. A caller that passes the same list to later
     *                              calls tries each failing group at most once.
     */
    public function claimNext(TaskScheduler $scheduler, array &$skipped = []): ?Task
    {
        // Failed rows from an earlier tick still need assistance, but never another reservation.
        foreach (TaskVm::query()->where('state', TaskVmState::Failed)
            ->whereHas('group', static fn ($group) => $group
                ->where('status', TaskGroupStatus::Todo)->where('assistance_requested', false))->get() as $vm) {
            $this->failedVms->requestAssistance($vm, new TaskVmFailedException($vm)->getMessage());
        }
        $resumed = $this->resumeWaitingSandbox($scheduler, $skipped);
        if ($resumed instanceof Task) {
            return $resumed;
        }
        while (true) {
            $reserved = DB::transaction(function () use ($skipped): ?Task {
                $group = Task::topLevel()
                    ->where('status', TaskGroupStatus::Todo)
                    ->whereNotIn('id', TaskVm::query()->where('state', TaskVmState::Failed)->select('group_id')->whereNotNull('group_id'))
                    ->when($skipped !== [], fn ($query) => $query->whereNotIn('id', $skipped))
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->first();
                if (! $group instanceof Task) {
                    return null;
                }
                $group->capacity_wait_reason = null;
                $group->status = TaskGroupStatus::Reserved;
                $group->reserved_at = now();
                $group->save();

                return $group->fresh(['tasks', 'project', 'taskable']) ?? $group;
            });

            if (! $reserved instanceof Task) {
                return null;
            }

            try {
                $instance = $this->provisioning->provision(InstanceProvisionIntent::for($reserved));
            } catch (TaskCapacityException $exception) {
                $this->releaseReservation($reserved, $exception->getMessage());
                $this->endClaim($reserved);
                $skipped[] = $reserved->id;

                continue;
            } catch (TaskVmFailedException $exception) {
                $this->releaseFailedTaskVm($reserved, $exception->vm);
                $this->endClaim($reserved);
                $skipped[] = $reserved->id;

                continue;
            } catch (Throwable $exception) {
                report($exception);
                $instance = InstanceProvisionFailure::fromException($exception);
            }

            if (! $instance instanceof Instance) {
                $this->releaseProvisioningFailure($scheduler, $reserved, $instance);
                $this->endClaim($reserved);
                $skipped[] = $reserved->id;

                continue;
            }

            try {
                $started = DB::transaction(fn (): ?Task => $this->startReserved($scheduler, $reserved, $instance));
            } catch (Throwable $exception) {
                // A failed start must not strand the group in reserved or drop its Instance. The log keeps the detail.
                report($exception);
                $this->releaseFailedStart($reserved, $instance);
                $this->endClaim($reserved);
                $skipped[] = $reserved->id;

                continue;
            }

            break;
        }

        if (! $started instanceof Task) {
            $this->endClaim($reserved);

            return null;
        }

        $this->runner->startFirstTask($scheduler, $started);
        // A task VM workspace started its baseline check before its Route. The Route finishes now, while the check
        // runs. A failure waits for the next try, before the implementer or the final review starts.
        $routeFailure = $this->runner->finishWorkspaceRoute($started);
        if ($routeFailure !== null) {
            Log::warning('The task workspace Route failed after the baseline check started; Orbit tries again before the implementer starts.', [
                'group_id' => $started->id,
                'failure' => $routeFailure,
            ]);
        }

        return $started->fresh(['tasks', 'project', 'taskable']) ?? $started;
    }

    /** @param list<int> $skipped */
    private function resumeWaitingSandbox(TaskScheduler $scheduler, array &$skipped): ?Task
    {
        $groups = Task::topLevel()
            ->where('status', TaskGroupStatus::WaitingForReview)
            ->whereNull('watched_pr_completion')
            ->whereHas('tasks', fn ($tasks) => $tasks->where('status', TaskStatus::Todo))
            ->when($skipped !== [], fn ($query) => $query->whereNotIn('id', $skipped))
            ->with(['project', 'tasks', 'taskable'])->orderBy('id')->get();
        foreach ($groups as $group) {
            if (TaskScheduler::resumeBlocked($group)) {
                continue;
            }
            $scheduler->resumeWaitingSubtask($group);
            $fresh = $group->fresh(['project', 'tasks', 'taskable']);
            if ($fresh instanceof Task && $fresh->status === TaskGroupStatus::Running) {
                return $fresh;
            }
            $skipped[] = $group->id;
        }

        return null;
    }

    /**
     * Attaches the provisioned Instance and moves the group from reserved to running. The group keeps the Instance
     * whenever it cannot start, so a later claim reuses it and cancellation removes it.
     *
     * A group that is no longer the reservation this claim made, because the tick returned it to todo or cancellation
     * ended it, keeps its status. It gains the Instance only when it holds none.
     *
     * A started group's claim is over, so its `reserved_at` is cleared in the same write.
     */
    private function startReserved(TaskScheduler $scheduler, Task $reserved, Instance $instance): ?Task
    {
        $group = Task::topLevel()
            ->with(['tasks', 'project', 'taskable'])
            ->lockForUpdate()
            ->findOrFail($reserved->id);

        if (! $this->holdsReservation($group, $reserved)) {
            if ($group->taskable_id === null && ! self::hasEnded($group)) {
                $group->taskable()->associate($instance);
                $group->save();
            }

            return null;
        }

        $group->taskable()->associate($instance);
        $group->load('taskable');

        $group->status = TaskGroupStatus::Running;
        $group->started_at ??= now();
        $group->reserved_at = null;
        if ($group->assistance_kind !== AssistanceKind::Direction && TaskScheduler::isClaimFailureReason($group->assistance_reason)) {
            $group->fill(TaskAssistance::cleared());
        }
        $group->save();

        $previousKey = $this->provisioningFailureKey($group);
        Activity::query()->create([
            'log_name' => 'tasks', 'description' => self::WorkspaceStartedActivity,
            'subject_type' => $group::class, 'subject_id' => $group->id,
            'properties' => ['instance_id' => $instance->id],
            'request_id' => (string) Str::uuid(), 'command' => 'tasks:tick', 'status' => 'completed',
        ]);
        DB::afterCommit(fn (): bool => $scheduler->rememberBackoff($previousKey, null, 'workspace provisioning'));

        return $group->fresh(['tasks', 'project', 'taskable']) ?? $group;
    }

    /**
     * Returns a group whose start failed to todo with a fixed reason and keeps its Instance. When this write fails
     * too, the group stays reserved until the tick returns it to todo.
     */
    private function releaseFailedStart(Task $reserved, Instance $instance): void
    {
        try {
            DB::transaction(function () use ($reserved, $instance): void {
                $group = Task::topLevel()->lockForUpdate()->find($reserved->id);
                if (! $group instanceof Task) {
                    return;
                }
                if ($group->taskable_id === null && ! self::hasEnded($group)) {
                    $group->taskable()->associate($instance);
                }
                if ($this->holdsReservation($group, $reserved)) {
                    $group->status = TaskGroupStatus::Todo;
                    $group->assistance_reason = TaskScheduler::StartFailedReason;
                }
                $group->save();
            });
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    private function provisioningFailureKey(Task $group): string
    {
        // The committed activity is a durable reset witness. Cache cleanup is only garbage collection.
        $generation = Activity::query()->where('log_name', 'tasks')
            ->where('subject_type', $group::class)->where('subject_id', $group->id)
            ->where('description', self::WorkspaceStartedActivity)->max('id');

        return 'tasks:provisioning-failures:'.$group->id.(is_numeric($generation) ? ':'.$generation : '');
    }

    /** Counts a failed claim and requests assistance only while its reservation is still current. */
    private function releaseProvisioningFailure(TaskScheduler $scheduler, Task $reserved, ?InstanceProvisionFailure $failure): void
    {
        DB::transaction(function () use ($scheduler, $reserved, $failure): void {
            $group = Task::topLevel()->lockForUpdate()->find($reserved->id);
            if (! $group instanceof Task || ! $this->holdsReservation($group, $reserved)) {
                return;
            }

            $key = $this->provisioningFailureKey($group);
            $failures = ($scheduler->readBackoff($key, 'workspace provisioning')['failures'] ?? 0) + 1;
            if (! $scheduler->rememberBackoff($key, ['failures' => $failures, 'due' => 0], 'workspace provisioning')) {
                $failures = 1;
            }
            $reason = TaskScheduler::ProvisioningFailedReason.($failure === null ? '' : ' '.$failure->cause);
            $group->status = TaskGroupStatus::Todo;
            if (! $group->assistance_requested) {
                $group->assistance_reason = $reason;
            }
            $group->save();
            if ($failures >= Config::integer('orbit.tasks.provisioning_failure_threshold', 3)) {
                $wasAsking = $group->assistance_requested;
                $applied = TaskAssistance::apply($group, AssistanceKind::Failure, null, $reason, replaceFailure: true);
                if (! $wasAsking && $applied) {
                    DB::afterCommit(fn () => $this->coder->assistance($group, $reason));
                }
            }
        });
    }

    /**
     * Returns a group whose task VM failed to todo and makes it ask for assistance at once: a failed VM never
     * recovers, so this is no wait and counts no retry. Later claims skip the failed VM's group until a cancel
     * destroys the VM. The failure action owns assistance and its notification.
     */
    private function releaseFailedTaskVm(Task $reserved, TaskVm $vm): void
    {
        DB::transaction(function () use ($reserved, $vm): void {
            $group = Task::topLevel()->lockForUpdate()->find($reserved->id);
            if (! $group instanceof Task || ! $this->holdsReservation($group, $reserved)) {
                return;
            }

            $group->capacity_wait_reason = null;
            $group->status = TaskGroupStatus::Todo;
            $group->save();
            $this->failedVms->requestAssistance($vm, new TaskVmFailedException($vm)->getMessage());
        });
    }

    /**
     * Returns a group to todo only while this claim still holds its reservation, so a claim never overwrites a
     * group that the tick released, a cancel ended, or a newer claim reserved.
     */
    private function releaseReservation(Task $reserved, string $reason): void
    {
        DB::transaction(function () use ($reserved, $reason): void {
            $group = Task::topLevel()->lockForUpdate()->find($reserved->id);
            if (! $group instanceof Task || ! $this->holdsReservation($group, $reserved)) {
                return;
            }

            $group->capacity_wait_reason = $reason;
            $group->status = TaskGroupStatus::Todo;
            if ($group->assistance_kind !== AssistanceKind::Direction && TaskScheduler::isClaimFailureReason($group->assistance_reason)) {
                $group->fill(TaskAssistance::cleared());
            }
            $group->save();
        });
    }

    /**
     * Ends a claim that did not start its group. It removes the workspace of a group that ended meanwhile, then
     * clears the group's `reserved_at`, so sweeps stop waiting for this claim. A newer claim's reservation stays.
     */
    private function endClaim(Task $reserved): void
    {
        $this->removeEndedWorkspace($reserved);

        Task::topLevel()->whereKey($reserved->id)
            ->where('status', '!=', TaskGroupStatus::Reserved)
            ->where('reserved_at', $reserved->reserved_at)
            ->update(['reserved_at' => null]);
    }

    /**
     * A group cancelled or completed while its claim ran holds no workspace, because the cancel left the
     * workspace to the claim. The claim removes the workspace the group owns, which provisioning created whether or
     * not it finished.
     */
    private function removeEndedWorkspace(Task $reserved): void
    {
        $group = Task::topLevel()->find($reserved->id);
        if (! $group instanceof Task || ! self::hasEnded($group) || $group->taskable_id !== null) {
            return;
        }

        try {
            $this->workspaces->execute($group);
        } catch (Throwable $exception) {
            // The workspace keeps its owner, so a repeated cancel removes it.
            report($exception);
            $this->workspaces->recordFailure($group, $exception);
        }
    }

    private static function hasEnded(Task $group): bool
    {
        return in_array($group->status, [TaskGroupStatus::Cancelled, TaskGroupStatus::Completed], true);
    }

    private function holdsReservation(Task $group, Task $reserved): bool
    {
        return $group->status === TaskGroupStatus::Reserved
            && $group->reserved_at instanceof Carbon
            && $reserved->reserved_at instanceof Carbon
            && $group->reserved_at->equalTo($reserved->reserved_at);
    }

    /**
     * Returns groups that stayed reserved longer than `orbit.tasks.reserved_timeout_seconds` to todo, for example after
     * the process that claimed them stopped. Each update applies only while the group is still reserved and past the
     * bound, so it never takes a group that a newer claim reserved.
     */
    public function releaseStaleReservations(): int
    {
        $cutoff = RemoveTaskWorkspaceAction::reservationCutoff();
        $stale = static fn ($query) => $query
            ->where('status', TaskGroupStatus::Reserved)
            ->where(static fn ($query) => $query->whereNull('reserved_at')->orWhere('reserved_at', '<=', $cutoff));
        $released = 0;

        foreach ($stale(Task::topLevel())->orderBy('id')->pluck('id') as $id) {
            $updated = $stale(Task::topLevel()->whereKey($id))->update([
                'status' => TaskGroupStatus::Todo,
                'assistance_reason' => TaskScheduler::ReservationExpiredReason,
            ]);
            if ($updated === 0) {
                continue;
            }

            Log::warning('Task group stayed reserved past the bound and returned to todo.', ['task_group_id' => $id]);
            $this->broadcasts->groupChanged((int) $id);
            $released++;
        }

        return $released;
    }

    /** Claims todo groups until none fits. A failing group is tried once. */
    public function claimAvailable(TaskScheduler $scheduler): int
    {
        $skipped = [];
        $started = 0;

        while ($this->claimNext($scheduler, $skipped) instanceof Task) {
            $started++;
        }

        return $started;
    }
}
