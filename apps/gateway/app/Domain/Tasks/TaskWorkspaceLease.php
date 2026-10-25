<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Actions\Tasks\RemoveTaskWorkspaceAction;
use App\Domain\TaskVms\TaskVmState;
use App\Models\AgentThread;
use App\Models\Instance;
use App\Models\Task;
use App\Models\TaskCheck;
use App\Models\TaskVm;
use App\Models\TaskWorkspaceLeaseRecord;
use App\Models\TaskWorkspaceOperation;
use Closure;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Support\Facades\DB;
use Throwable;

/** Durable Instance ownership. All admission/reconciliation runs under the group's execution lock. */
#[Singleton]
final class TaskWorkspaceLease
{
    /** @var array<int, TaskWorkspaceLeaseRecord> */
    private array $scopes = [];

    private bool $ticking = false;

    /** @var array<string, TaskCheckReading> */
    private array $readings = [];

    /** @var array<int, true> */
    private array $reconciled = [];

    public function beginTick(): void
    {
        $this->ticking = true;
        $this->readings = [];
        $this->reconciled = [];
    }

    public function endTick(): void
    {
        $this->ticking = false;
        $this->readings = [];
        $this->reconciled = [];
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $operation
     * @return T
     */
    private function synchronized(Task $group, Closure $operation): mixed
    {
        // The Instance lock is used only for queue admission/release, never over remote IO.
        return app(TaskExecutionLock::class)->synchronized($group->id, $operation, wait: false);
    }

    public function cancelQueued(Task $group, string $owner): void
    {
        $this->synchronized($group, static function () use ($group, $owner): void {
            $instance = $group->taskable;
            $instanceId = $instance instanceof Instance ? $instance->id : $group->taskable_id;
            TaskWorkspaceLeaseRecord::query()->where('group_id', $group->id)->where('instance_id', $instanceId)
                ->where('owner', $owner)->where('status', 'queued')->update(['status' => 'cancelled', 'released_at' => now()]);
        });
    }

    private function activeScope(int $instanceId): ?TaskWorkspaceLeaseRecord
    {
        $scope = $this->scopes[$instanceId] ?? null;
        if (! $scope instanceof TaskWorkspaceLeaseRecord) {
            return null;
        }
        $fresh = $scope->fresh();
        if (! $fresh instanceof TaskWorkspaceLeaseRecord || $fresh->status !== 'held' || $fresh->getAttribute('recovery_sealed') || $fresh->getAttribute('active_instance_id') !== $instanceId) {
            throw new TaskWorkspaceWaiting('The workspace scope has ended or is sealed for recovery.');
        }

        $scope->refresh();

        return $scope;
    }

    public function currentScope(): ?TaskWorkspaceLeaseRecord
    {
        foreach (array_reverse(array_keys($this->scopes)) as $instanceId) {
            if (($scope = $this->activeScope($instanceId)) instanceof TaskWorkspaceLeaseRecord) {
                return $scope;
            }
        }

        return null;
    }

    /** @template T
     * @param  Closure(): T  $operation
     * @return T
     */
    public function within(TaskWorkspaceLeaseRecord $lease, Closure $operation): mixed
    {
        $fresh = $lease->fresh();
        if (! $fresh instanceof TaskWorkspaceLeaseRecord || $fresh->status !== 'held' || $fresh->getAttribute('recovery_sealed') || $fresh->getAttribute('active_instance_id') !== $fresh->instance_id) {
            throw new TaskWorkspaceWaiting('The workspace admission no longer owns this Instance.');
        }
        $previous = $this->scopes[$lease->instance_id] ?? null;
        if ($previous instanceof TaskWorkspaceLeaseRecord && $previous->id !== $lease->id) {
            throw new TaskWorkspaceWaiting('A different workspace actor owns this scope.');
        }
        $this->scopes[$lease->instance_id] = $lease;
        try {
            return $operation();
        } finally {
            if (($this->scopes[$lease->instance_id] ?? null)?->id === $lease->id) {
                if ($previous instanceof TaskWorkspaceLeaseRecord) {
                    $this->scopes[$lease->instance_id] = $previous;
                } else {
                    unset($this->scopes[$lease->instance_id]);
                }
            }
        }
    }

    /** Persist uncertainty before an SSH mutation, including mutations whose caller swallows failure. */
    public function remoteStarted(Instance $instance, bool $changesCheckout = false): TaskWorkspaceLeaseRecord
    {
        return app(TaskExecutionLock::class)->synchronized(-$instance->id, function () use ($instance, $changesCheckout): TaskWorkspaceLeaseRecord {
            $scope = $this->scopes[$instance->id] ?? null;
            $lease = $scope instanceof TaskWorkspaceLeaseRecord ? TaskWorkspaceLeaseRecord::query()->whereKey($scope->id)->where('status', 'held')->where('active_instance_id', $instance->id)->first() : null;
            if (! $lease instanceof TaskWorkspaceLeaseRecord) {
                throw new TaskWorkspaceWaiting('A checkout mutation requires its exact admitted workspace scope.');
            }
            if ($lease->getAttribute('recovery_sealed') || $lease->getAttribute('remote_uncertain') || $lease->getAttribute('completion_pending')) {
                throw new TaskWorkspaceWaiting('The prior remote mutation has no authoritative completion.');
            }
            $lease->update(['remote_uncertain' => true, 'orbit_mutation' => $lease->getAttribute('orbit_mutation') || $changesCheckout]);

            return $lease;
        });
    }

    public function remoteFinished(?TaskWorkspaceLeaseRecord $lease): void
    {
        if ($lease instanceof TaskWorkspaceLeaseRecord) {
            app(TaskExecutionLock::class)->synchronized(-$lease->instance_id, static function () use ($lease): void {
                if (! TaskWorkspaceOperation::query()->where('lease_id', $lease->id)->where('status', 'pending')->exists()) {
                    TaskWorkspaceLeaseRecord::query()->whereKey($lease->id)->where('status', 'held')->where('recovery_sealed', false)->update(['remote_uncertain' => false]);
                }
            });
        }
    }

    public function acquire(Task $group, string $kind, string $owner, ?int $threadId = null, ?int $taskId = null, bool $queue = true): TaskWorkspaceLeaseRecord
    {
        $instance = $group->taskable;
        if (! $instance instanceof Instance) {
            throw new TaskCheckException('The task workspace is unavailable.');
        }

        return app(TaskExecutionLock::class)->synchronized(-$instance->id, function () use ($group, $instance, $kind, $owner, $threadId, $taskId, $queue): TaskWorkspaceLeaseRecord {
            if (($scope = $this->activeScope($instance->id)) instanceof TaskWorkspaceLeaseRecord) {
                return $scope;
            }
            $this->pruneQueued($instance->id);
            $task = $taskId === null ? null : Task::query()->where('parent_id', $group->id)->find($taskId);
            if ($taskId !== null && (! $task instanceof Task || $task->status === TaskStatus::Cancelled)) {
                throw new TaskWorkspaceWaiting('The queued workspace actor belongs to a cancelled subtask.');
            }
            $request = DB::transaction(function () use ($group, $instance, $kind, $owner, $threadId, $taskId, $task, $queue): TaskWorkspaceLeaseRecord {
                $request = TaskWorkspaceLeaseRecord::query()->where('instance_id', $instance->id)->where('group_id', $group->id)
                    ->where(function ($query) use ($kind, $owner, $threadId): void {
                        $query->where(fn ($original) => $original->where('kind', $kind)->where('owner', $owner));
                        if ($threadId !== null) {
                            $query->orWhere(fn ($actor) => $actor->where('status', 'held')->where('thread_id', $threadId)
                                ->where('actor_kind', $kind)->where('actor_owner', $owner));
                        }
                    })->whereIn('status', ['queued', 'held'])->first();
                if (! $request instanceof TaskWorkspaceLeaseRecord) {
                    $request = TaskWorkspaceLeaseRecord::query()->create([
                        'instance_id' => $instance->id, 'group_id' => $group->id,
                        'kind' => $kind, 'owner' => $owner, 'thread_id' => $threadId, 'task_id' => $taskId, 'task_context' => $this->taskContext($group, $task), 'status' => 'queued',
                    ]);
                }
                if ($request->status === 'held') {
                    return $request;
                }
                $holder = TaskWorkspaceLeaseRecord::query()->where('active_instance_id', $instance->id)->exists();
                $oldest = TaskWorkspaceLeaseRecord::query()->where('instance_id', $instance->id)->where('status', 'queued')->orderBy('id')->value('id');
                if (! $holder && $oldest === $request->id) {
                    $request->update([
                        'active_instance_id' => $instance->id, 'status' => 'held', 'acquired_at' => now(), 'acquisition_sequence' => $this->nextSequence($instance->id),
                        'gateway_pid' => getmypid(), 'gateway_started' => self::processIdentity(getmypid() ?: 0),
                        'gateway_host' => gethostname(), 'gateway_boot' => self::bootIdentity(), 'journaled' => true,
                    ]);
                } elseif (! $queue) {
                    // Admission and withdrawal commit together; no caller owns a later retry.
                    $request->update(['status' => 'cancelled', 'released_at' => now()]);
                }

                return $request;
            });
            if ($request->status === 'held' && ($request->getAttribute('remote_uncertain') || $request->getAttribute('recovery_sealed'))) {
                throw new TaskWorkspaceWaiting('The prior remote mutation has no authoritative completion.');
            }
            if ($request->status !== 'held') {
                throw new TaskWorkspaceWaiting('Workspace lease '.($queue ? 'queued' : 'unavailable').' for '.$kind.' '.$owner.'.');
            }

            return $request;
        });
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $operation
     * @return T
     *
     * Callers without a durable workflow that will retry must disable queuing.
     */
    public function run(Task $group, string $kind, string $owner, Closure $operation, ?int $taskId = null, bool $queue = true): mixed
    {
        $instance = $group->taskable;
        if (! $instance instanceof Instance) {
            return $operation();
        }
        if ($kind === 'teardown') {
            RemoveTaskWorkspaceAction::awaitVmPreservation($group, $instance);
        }
        $instanceId = $instance->id;
        if ($this->activeScope($instanceId) instanceof TaskWorkspaceLeaseRecord) {
            return $operation();
        }

        // Instance admission is short; the durable row owns the operation after this lock ends.
        $lease = $this->synchronized($group, function () use ($group, $kind, $owner, $taskId, $queue): TaskWorkspaceLeaseRecord {
            $this->reconcile($group);
            $lease = $this->acquire($group, $kind, $owner, taskId: $taskId, queue: $queue);
            if ($lease->getAttribute('gateway_pid') !== getmypid()
                || $lease->getAttribute('gateway_started') !== self::processIdentity(getmypid() ?: 0)
                || $lease->thread_id !== null || $lease->pid !== null || $lease->getAttribute('completion_pending')) {
                throw new TaskWorkspaceWaiting('The prior workspace actor has not released ownership.');
            }

            return $lease;
        });

        return $this->within($lease, function () use ($group, $lease, $operation): mixed {
            try {
                $this->snapshotBefore($group, $lease);

                return $operation();
            } finally {
                $lease->refresh();
                if ($lease->thread_id === null && $lease->pid === null) {
                    $after = $lease->getAttribute('remote_uncertain') ? null : $this->snapshot($group);
                    $lease->update(['completion_pending' => true, 'causality_uncertain' => $lease->getAttribute('causality_uncertain') || ($after === null && $lease->getAttribute('orbit_mutation'))]);
                    $this->release($lease, $after);
                }
            }
        });
    }

    /**
     * A nonessential checkout read never leaves a waiter when the caller can skip it.
     *
     * @param  Closure(): bool  $read
     */
    public function inspect(Task $group, Closure $read): bool
    {
        return $this->synchronized($group, function () use ($group, $read): bool {
            $this->reconcile($group);
            if (TaskWorkspaceLeaseRecord::query()->where('instance_id', $group->taskable_id)->whereIn('status', ['held', 'queued'])->exists()) {
                return false;
            }

            return $this->run($group, 'verdict', 'observe-commits', $read);
        });
    }

    /**
     * A paused actor may publish its own receipt while retaining its turn fence.
     *
     * @template T
     *
     * @param  Closure(): T  $operation
     * @return T
     */
    public function withReceipt(Task $group, int $taskId, ?int $threadId, Closure $operation): mixed
    {
        return $this->synchronized($group, function () use ($group, $taskId, $threadId, $operation): mixed {
            $instance = $group->taskable;
            if (! $instance instanceof Instance) {
                return $operation();
            }
            $instanceId = $instance->id;
            if (isset($this->scopes[$instanceId])) {
                return $operation();
            }
            $this->reconcile($group);
            $holder = TaskWorkspaceLeaseRecord::query()->where('active_instance_id', $instanceId)->first();
            if ($holder instanceof TaskWorkspaceLeaseRecord && $holder->group_id === $group->id && $holder->thread_id !== null && $holder->thread_id === $threadId) {
                $thread = AgentThread::query()->find($threadId);
                $observation = $thread instanceof AgentThread ? app(AgentThreadObserver::class)->observe($thread) : null;
                $state = $observation?->state;
                if ($holder->getAttribute('remote_uncertain') || (! $holder->getAttribute('adopted')
                    && (! is_string($observation?->turnId) || $observation->turnId === '' || $observation->turnId === $holder->getAttribute('previous_turn_id')))
                    || ! in_array($state, [AgentThreadState::Idle, AgentThreadState::Done, AgentThreadState::Failed, AgentThreadState::AskingForInput], true)) {
                    throw new TaskWorkspaceWaiting('The receipt actor has not authoritatively paused or stopped.');
                }
                $this->scopes[$instanceId] = $holder;
                try {
                    return $operation();
                } finally {
                    unset($this->scopes[$instanceId]);
                }
            }

            return $this->run($group, 'verdict', 'receipt:'.$taskId.':'.($threadId ?? 'unbound'), $operation, taskId: $taskId);
        });
    }

    public function turn(Task $group, TaskThreadRole $role, string $owner, ?int $threadId): TaskWorkspaceLeaseRecord
    {
        return $this->synchronized($group, function () use ($group, $role, $owner, $threadId): TaskWorkspaceLeaseRecord {
            $this->reconcile($group);
            $threadTaskId = $threadId === null ? null : AgentThread::query()->whereKey($threadId)->value('task_id');
            $taskId = is_int($threadTaskId) ? $threadTaskId : Task::query()->where('parent_id', $group->id)
                ->whereIn('status', [TaskStatus::Running, TaskStatus::Reviewing])
                ->where(fn ($query) => $query->where('implementer_agent_thread_id', $threadId)->orWhere('reviewer_agent_thread_id', $threadId))->value('id');
            $lease = $this->acquire($group, $role->value, $owner, $threadId, is_int($taskId) ? $taskId : null);
            if ($lease->getAttribute('dispatch_started_at') !== null) {
                throw new TaskWorkspaceWaiting('The admitted '.$role->value.' turn still owns the workspace.');
            }
            $this->snapshotBefore($group, $lease);
            $lease->update(['actor_kind' => $role->value, 'actor_owner' => $owner, 'thread_id' => $threadId]);

            return $lease;
        });
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $operation
     * @return T
     */
    public function withThreadLock(AgentThread $thread, Closure $operation): mixed
    {
        $group = Task::topLevel()->findOrFail($thread->task_group_id);

        return app(TaskExecutionLock::class)->synchronized($group->id, function () use ($group, $thread, $operation): mixed {
            $lease = $this->synchronized($group, function () use ($group, $thread): TaskWorkspaceLeaseRecord {
                $this->reconcile($group);

                return $this->acquire($group, $thread->role, 'thread:'.$thread->id, $thread->id, $thread->task_id);
            });

            return $this->within($lease, $operation);
        }, wait: false);
    }

    public function admittedSendKey(AgentThread $thread, ?string $key, string $message, bool $continuation = false): string
    {
        $lease = TaskWorkspaceLeaseRecord::query()->where('group_id', $thread->task_group_id)
            ->where('thread_id', $thread->id)->where('status', 'held')->firstOrFail();

        return $key ?? ($continuation ? hash('sha256', 'workspace-input:'.$lease->id.':'.$message) : $lease->dispatch_key ?? '');
    }

    /** A paused thread continues under its existing lease without replacing turn metadata. */
    public function inputContinuation(AgentThread $thread): bool
    {
        $held = TaskWorkspaceLeaseRecord::query()->where('group_id', $thread->task_group_id)
            ->where('thread_id', $thread->id)->where('status', 'held')->first();
        $fresh = $held instanceof TaskWorkspaceLeaseRecord ? AgentThread::query()->find($thread->id) : null;
        $observation = $fresh instanceof AgentThread ? app(AgentThreadObserver::class)->observe($fresh) : null;

        return $held instanceof TaskWorkspaceLeaseRecord && $observation?->state === AgentThreadState::AskingForInput
            && ($held->getAttribute('adopted') || (is_string($observation->turnId) && $observation->turnId !== '' && $observation->turnId !== $held->getAttribute('previous_turn_id')));
    }

    /** A confirmed completed keyed command is an idempotent acknowledgement, never a new turn. */
    public function completedSend(AgentThread $thread, ?string $key, ?string $message = null): bool
    {
        if ($key === null || $key === '') {
            return false;
        }
        $lease = TaskWorkspaceLeaseRecord::query()->where('group_id', $thread->task_group_id)->where('thread_id', $thread->id)
            ->where('status', 'released')->where('dispatch_completed', true)->where('dispatch_key', $key)->latest('id')->first();
        if (! $lease instanceof TaskWorkspaceLeaseRecord) {
            return false;
        }
        if ($message !== null && $lease->getAttribute('dispatch_hash') !== hash('sha256', $message)) {
            throw new TaskWorkspaceWaiting('The completed send key belongs to a different message.');
        }

        return true;
    }

    /** A retry of the same idempotent send neither prepares metadata nor captures another baseline. */
    public function retrying(AgentThread $thread, ?string $key, ?string $message = null): bool
    {
        $this->reconcile(Task::topLevel()->findOrFail($thread->task_group_id));
        if ($this->completedSend($thread, $key, $message)) {
            return true;
        }
        $lease = TaskWorkspaceLeaseRecord::query()->where('group_id', $thread->task_group_id)
            ->where('thread_id', $thread->id)->where('status', 'held')
            ->when($key === null || $key === '', static fn ($query) => $query->where('dispatch_generated', true),
                static fn ($query) => $query->where('dispatch_key', $key))
            ->whereNotNull('dispatch_started_at')->first();
        if (! $lease instanceof TaskWorkspaceLeaseRecord) {
            return false;
        }
        if ($message !== null && $lease->getAttribute('dispatch_hash') !== hash('sha256', $message)) {
            throw new TaskWorkspaceWaiting('The send key belongs to a different admitted message.');
        }

        return true;
    }

    /** A resolution starts a fresh formal review even before its attempt counter is committed. */
    public function requireReviewBaseline(AgentThread $thread): void
    {
        $this->withThreadLock($thread, static function () use ($thread): void {
            TaskWorkspaceLeaseRecord::query()->where('group_id', $thread->task_group_id)->where('thread_id', $thread->id)
                ->where('status', 'held')->whereNull('dispatch_started_at')->update(['review_baseline_required' => true]);
        });
    }

    public function dispatch(AgentThread $thread, ?AgentObservation $previous = null, ?string $key = null, ?string $message = null): string
    {
        $group = Task::topLevel()->findOrFail($thread->task_group_id);

        return $this->synchronized($group, function () use ($group, $thread, $previous, $key, $message): string {
            $role = TaskThreadRole::from($thread->role);
            $lease = $this->acquire($group, $role->value, 'thread:'.$thread->id, $thread->id);
            if ($role === TaskThreadRole::Reviewer && $thread->task_id !== null) {
                $task = Task::query()->find($thread->task_id);
                if ($task instanceof Task && $task->status === TaskStatus::Reviewing
                    && ($lease->getAttribute('review_baseline_required') || $task->review_notified_attempt !== $task->review_attempt || $task->review_workspace_head === null)) {
                    $instance = $group->taskable;
                    if (! $instance instanceof Instance) {
                        throw new TaskCheckException('The task workspace is unavailable.');
                    }
                    $snapshot = app(TaskCheckRunner::class)->snapshot($instance);
                    $task->update(['review_workspace_head' => $snapshot->head, 'review_workspace_tree' => $snapshot->tree, 'review_workspace_lease_id' => $lease->id]);
                }
            }
            $lease->update(['review_baseline_required' => false, 'previous_turn_id' => $previous?->turnId, 'saw_working' => false, 'dispatch_started_at' => now(),
                'dispatch_key' => $key ?? hash('sha256', 'workspace-send:'.$lease->id),
                'dispatch_generated' => $key === null, 'dispatch_message' => $message, 'dispatch_hash' => $message === null ? null : hash('sha256', $message)]);

            return $lease->dispatch_key ?? '';
        });
    }

    /** @return array{key: string, message: string}|null */
    public function pendingSend(AgentThread $thread, string $key, bool $includeCompleted = false): ?array
    {
        $lease = TaskWorkspaceLeaseRecord::query()->where('group_id', $thread->task_group_id)->where('thread_id', $thread->id)
            ->where(fn ($query) => $query->where('status', 'held')->when($includeCompleted, fn ($query) => $query->orWhere(fn ($completed) => $completed->where('status', 'released')->where('dispatch_completed', true))))
            ->where('dispatch_key', $key)->latest('id')->first();
        $message = $lease?->getAttribute('dispatch_message');

        return is_string($message) ? ['key' => $key, 'message' => $message] : null;
    }

    /** Never discard an uncertain remote owner merely because the local startup failed. */
    public function abandonPreparation(AgentThread $thread): bool
    {
        return app(TaskExecutionLock::class)->synchronized($thread->task_group_id, function () use ($thread): bool {
            $lease = TaskWorkspaceLeaseRecord::query()->where('group_id', $thread->task_group_id)
                ->where('thread_id', $thread->id)->where('status', 'held')->first();
            if (! $lease instanceof TaskWorkspaceLeaseRecord) {
                return true;
            }
            if ($lease->getAttribute('dispatch_started_at') !== null || $lease->getAttribute('remote_uncertain')) {
                return false;
            }
            $this->release($lease);

            return $lease->fresh()?->status === 'released';
        }, wait: false);
    }

    /** Only a protocol-level refusal proves that this dispatch did not start a turn. */
    public function rejectedDispatch(AgentThread $thread): void
    {
        $group = Task::topLevel()->findOrFail($thread->task_group_id);
        $this->synchronized($group, function () use ($group, $thread): void {
            $lease = TaskWorkspaceLeaseRecord::query()->where('active_instance_id', $group->taskable_id)
                ->where('thread_id', $thread->id)->first();
            if ($lease instanceof TaskWorkspaceLeaseRecord) {
                $this->release($lease, $this->snapshot($group));
                if ($lease->fresh()?->status === 'released' && ($this->scopes[$lease->instance_id] ?? null)?->id === $lease->id) {
                    // A proven refusal ends this scope; nested unwind must not restore it.
                    unset($this->scopes[$lease->instance_id]);
                }
            }
        });
    }

    /**
     * @param  list<array{name: string, command: string, timeout_seconds: int}>  $setup
     * @param  array{start: string|null, commands: list<array{id: string, command: string, directory: string, fails_on_base?: bool, paths?: list<string>}>, topology?: bool}|null  $deliverables
     *
     * @throws TaskCheckException
     */
    public function startCheck(Task $group, Task $task, ?string $command, array $setup = [], ?array $deliverables = null, ?int $commentId = null): TaskCheckProcess
    {
        $lease = $this->synchronized($group, function () use ($group): TaskWorkspaceLeaseRecord {
            $instance = $group->taskable;
            if (! $instance instanceof Instance) {
                throw new TaskCheckException('The task workspace is unavailable.');
            }
            $lease = $this->activeScope($instance->id);
            if (! $lease instanceof TaskWorkspaceLeaseRecord) {
                throw new TaskWorkspaceWaiting('A workspace check requires an admitted workspace scope.');
            }
            if ($lease->pid !== null) {
                throw new TaskWorkspaceWaiting('The admitted workspace check has not stopped.');
            }

            return $lease;
        });

        return $this->within($lease, function () use ($group, $task, $command, $setup, $deliverables, $commentId, $lease): TaskCheckProcess {
            $this->snapshotBefore($group, $lease);
            $pending = $task->checks()->where('status', TaskCheckStatus::Running)->where('pid', 0)->latest('id')->first();
            if (! $pending instanceof TaskCheck) {
                $pending = $task->checks()->create([
                    'kind' => TaskCheckKind::Handoff,
                    'task_comment_id' => $commentId, 'status' => TaskCheckStatus::Running,
                    'pid' => 0, 'process_started' => '', 'head_before' => $lease->head_before, 'tree_before' => $lease->tree_before, 'started_at' => now(),
                ]);
            }
            $runId = bin2hex(random_bytes(16));
            $lease->update(['pid' => 0, 'check_id' => $pending->id, 'check_run_id' => $runId, 'orbit_mutation' => true]);
            $instance = $group->taskable;
            if (! $instance instanceof Instance) {
                throw new TaskCheckException('The task workspace is unavailable.');
            }
            try {
                $process = app(TaskCheckRunner::class)->start($instance, $command, $setup, $deliverables, $runId);
            } catch (TaskCheckException $exception) {
                if ($exception->startNotDispatched) {
                    $lease->update(['pid' => null]);
                    $this->release($lease, $this->snapshot($group));
                }
                throw $exception;
            }
            $this->adoptStartedCheck($lease, $pending, $process);

            return $process;
        });
    }

    private function adoptStartedCheck(TaskWorkspaceLeaseRecord $lease, TaskCheck $check, TaskCheckProcess $process): void
    {
        $lease->update(['actor_kind' => 'check', 'actor_owner' => 'check:'.$check->task_id, 'pid' => $process->pid, 'process_started' => $process->started,
            'head_before' => $lease->head_before ?? $process->head, 'tree_before' => $lease->tree_before ?? $process->tree,
            'process_head' => $process->head, 'process_tree' => $process->tree, 'thread_id' => null]);
        TaskCheck::query()->whereKey($check->id)->where('status', TaskCheckStatus::Running)->where('pid', 0)
            ->update(['pid' => $process->pid, 'process_started' => $process->started, 'head_before' => $process->head, 'tree_before' => $process->tree]);
    }

    public function invalidateCheck(Instance $instance, TaskCheckProcess $process): void
    {
        unset($this->readings[$instance->id.':'.$process->pid.':'.$process->started.':'.$process->runId]);
        $this->reconciled = [];
    }

    public function readCheck(Instance $instance, TaskCheckProcess $process): TaskCheckReading
    {
        $key = $instance->id.':'.$process->pid.':'.$process->started.':'.$process->runId;
        if ($this->ticking && isset($this->readings[$key])) {
            return $this->readings[$key];
        }
        $reading = app(TaskCheckRunner::class)->read($instance, $process);
        if ($this->ticking) {
            $this->readings[$key] = $reading;
        }

        return $reading;
    }

    public function release(TaskWorkspaceLeaseRecord $lease, ?TaskWorkspaceSnapshot $after = null): void
    {
        app(TaskExecutionLock::class)->synchronized($lease->group_id, fn () => app(TaskExecutionLock::class)->synchronized(-$lease->instance_id, function () use ($lease, $after): void {
            $fresh = TaskWorkspaceLeaseRecord::query()->whereKey($lease->id)->where('status', 'held')->first();
            if (! $fresh instanceof TaskWorkspaceLeaseRecord || $fresh->getAttribute('remote_uncertain') || $fresh->getAttribute('recovery_sealed')) {
                return;
            }
            if ($fresh->getAttribute('orbit_mutation') && $after === null && Instance::query()->whereKey($fresh->instance_id)->exists()) {
                $fresh->update(['completion_pending' => true, 'causality_uncertain' => true]);

                return;
            }
            $fresh->update([
                'active_instance_id' => null, 'status' => 'released', 'released_at' => now(), 'completion_pending' => false,
                'head_after' => $after?->head, 'tree_after' => $after?->tree,
            ]);
        }));
    }

    public function finishedCheck(TaskCheck $check, TaskCheckReading $reading): void
    {
        if ($reading->state !== 'finished') {
            return;
        }
        $task = $check->task;
        $group = $task instanceof Task ? Task::topLevel()->find($task->parent_id) : null;
        if (! $group instanceof Task) {
            return;
        }
        $this->synchronized($group, function () use ($check, $reading, $group): void {
            $lease = TaskWorkspaceLeaseRecord::query()->where('status', 'held')->where('active_instance_id', $group->taskable_id)
                ->where('pid', $check->pid)->where('process_started', $check->process_started)->where('group_id', $group->id)->where('check_id', $check->id)->first();
            if ($lease instanceof TaskWorkspaceLeaseRecord) {
                $lease->update(['check_id' => $check->id, 'causality_uncertain' => $lease->getAttribute('causality_uncertain') || $reading->causalityUncertain]);
                $this->release($lease, $reading->headAfter !== null && $reading->treeAfter !== null
                    ? new TaskWorkspaceSnapshot($reading->headAfter, $reading->treeAfter) : null);
            }
        });
    }

    /** Reconcile before assistance gating. Unknown runtime state retains ownership, without a TTL. */
    public function reconcile(Task $group): void
    {
        if ($this->ticking && isset($this->reconciled[$group->id])) {
            return;
        }
        if ($this->ticking) {
            $this->reconciled[$group->id] = true;
        }
        $this->synchronized($group, function () use ($group): void {
            $instance = $group->taskable;
            if (! $instance instanceof Instance) {
                $removed = TaskWorkspaceLeaseRecord::query()->where('group_id', $group->id)->where('status', 'held')->where('kind', 'teardown')->first();
                if ($removed instanceof TaskWorkspaceLeaseRecord
                    && ! Instance::query()->whereKey($removed->instance_id)->exists()
                    && TaskVm::query()->where('group_id', $group->id)->where('state', TaskVmState::Destroyed)->exists()) {
                    $removed->update(['remote_uncertain' => false]);
                    $this->release($removed);
                }

                return;
            }
            if ($this->activeScope($instance->id) instanceof TaskWorkspaceLeaseRecord) {
                return;
            }
            $holder = TaskWorkspaceLeaseRecord::query()->where('active_instance_id', $instance->id)->first();
            if ($holder instanceof TaskWorkspaceLeaseRecord && $holder->group_id !== $group->id) {
                return;
            }
            if (! $holder instanceof TaskWorkspaceLeaseRecord) {
                $this->adoptExistingOwner($group);

                return;
            }
            if ($holder->getAttribute('recovery_sealed')) {
                return;
            }
            app(TaskExecutionLock::class)->synchronized(-$instance->id, function () use ($holder): void {
                $holder->refresh();
                if ($holder->getAttribute('journaled') && $this->localOwnerDead($holder)
                    && ! TaskWorkspaceOperation::query()->where('lease_id', $holder->id)->exists()
                    && $holder->kind !== 'teardown') {
                    // Native SSH journals before dispatch. Provider deletion has a separate Destroying fence.
                    $holder->update(['remote_uncertain' => false]);
                }
            });
            if ($holder->getAttribute('remote_uncertain')
                && ! app(TaskWorkspaceOperationRecovery::class)->settled($holder)) {
                return;
            }
            $holder->refresh();
            if ($holder->getAttribute('completion_pending')) {
                if ($holder->getAttribute('orbit_mutation')) {
                    $holder->update(['causality_uncertain' => true]);
                }
                $this->release($holder, $this->snapshot($group));

                return;
            }
            if (in_array($holder->kind, ['analysis', 'script'], true)) {
                // The operation adopts its reserved run and complete artifact; no TaskCheck row is involved.
                return;
            }
            if ($holder->pid !== null) {
                $check = $holder->check_id !== null ? TaskCheck::query()->find($holder->check_id)
                    : TaskCheck::query()->whereIn('task_id', $group->tasks()->select('id'))
                        ->where('pid', $holder->pid)->where('process_started', $holder->process_started)->latest('id')->first();
                if (! $check instanceof TaskCheck) {
                    return;
                }
                $checkTask = $check->task;
                if (! $checkTask instanceof Task) {
                    return;
                }
                $holder->update(['check_id' => $check->id]);
                if ($holder->pid === 0) {
                    $runId = $holder->getAttribute('check_run_id');
                    if (! is_string($runId) || $runId === '') {
                        return;
                    }
                    try {
                        $process = app(TaskCheckRunner::class)->recoverStart($instance, $runId);
                    } catch (TaskCheckException) {
                        return;
                    }
                    if ($process === null) {
                        // The remote start ID is now tombstoned; the late original cannot run.
                        TaskCheck::query()->whereKey($check->id)->where('status', TaskCheckStatus::Running)->where('pid', 0)->delete();
                        $holder->update(['pid' => null]);
                        $this->release($holder, $this->snapshot($group));

                        return;
                    }
                    $this->adoptStartedCheck($holder, $check, $process);
                    $holder->refresh();
                    $check->refresh();
                }
                if ($holder->pid === null || $holder->pid < 1) {
                    return;
                }
                $process = new TaskCheckProcess($holder->pid, $holder->process_started ?? '', $holder->process_head ?? $holder->head_before ?? '', $holder->process_tree ?? $holder->tree_before ?? '', is_string($holder->getAttribute('check_run_id')) ? $holder->getAttribute('check_run_id') : null);
                try {
                    $reading = $this->readCheck($instance, $process);
                } catch (TaskCheckException $exception) {
                    // Assistance would skip the normal handler, so account for this failed read here.
                    if ($group->groupOrchestration() === TaskOrchestration::Tick && ($group->assistance_requested || $checkTask->assistance_requested)) {
                        app(TaskScheduler::class)->recordCommunicationFailure($checkTask, $group, $exception->getMessage());
                    }

                    return;
                }
                if ($reading->state === 'lost' && $check->status === TaskCheckStatus::Running) {
                    app(TaskSubtaskRunner::class)->recordReading($check, $reading);
                }
                if ($reading->state !== 'finished') {
                    // A missing wrapper PID does not prove that its shell children stopped.
                    return;
                }
                if ($check->status === TaskCheckStatus::Running) {
                    if ($check->pid === 0) {
                        $check->update(['pid' => $holder->pid, 'process_started' => $holder->process_started, 'head_before' => $process->head, 'tree_before' => $process->tree]);
                    }
                    app(TaskSubtaskRunner::class)->recordReading($check, $reading);
                }
                $check->refresh();
                if ($reading->causalityUncertain) {
                    $holder->update(['causality_uncertain' => true]);
                }
                $this->release($holder, $reading->headAfter !== null && $reading->treeAfter !== null
                    ? new TaskWorkspaceSnapshot($reading->headAfter, $reading->treeAfter) : null);
                unset($this->reconciled[$group->id]);
                $this->reconcile($group);

                return;
            }
            if ($holder->thread_id !== null) {
                $thread = AgentThread::query()->find($holder->thread_id);
                if ($holder->getAttribute('dispatch_started_at') === null) {
                    if (! $thread instanceof AgentThread || in_array($group->status, TaskGroupStatus::endedStatuses(), true)
                        || ($holder->getAttribute('journaled') && $this->localOwnerDead($holder))) {
                        $this->release($holder, $this->snapshot($group));
                    }

                    return;
                }
                if (! $thread instanceof AgentThread || $thread->isPending()) {
                    return;
                }
                $observation = app(AgentThreadObserver::class)->observe($thread);
                if ($observation?->state === AgentThreadState::Working) {
                    $holder->update(['saw_working' => true]);

                    return;
                }
                $adopted = $holder->getAttribute('adopted');
                if (! in_array($observation?->state, [AgentThreadState::Idle, AgentThreadState::Done, AgentThreadState::Failed], true)
                    || (! $adopted && $observation->state === AgentThreadState::Idle)) {
                    return;
                }
                $turn = $observation->turnId;
                if (! is_string($turn) || $turn === '' || (! $adopted && $turn !== $holder->dispatch_key)) {
                    return;
                }
                $holder->update(['dispatch_completed' => true]);
                $this->release($holder, $this->snapshot($group));

                return;
            }
            if ($holder->getAttribute('journaled') && $this->localOwnerDead($holder)
                && app(TaskWorkspaceOperationRecovery::class)->settled($holder)) {
                if ($holder->getAttribute('orbit_mutation')) {
                    // Death before finalization leaves the same observation gap as a delayed after-read.
                    $holder->update(['causality_uncertain' => true]);
                }
                $this->release($holder, $this->snapshot($group));
            }
        });
    }

    private function adoptExistingOwner(Task $group): void
    {
        $instance = $group->taskable;
        if (! $instance instanceof Instance) {
            return;
        }
        $groups = Task::topLevel()->where('taskable_type', $instance->getMorphClass())->where('taskable_id', $instance->id)->get()->keyBy('id');
        $groups->put($group->id, $group);
        $check = TaskCheck::query()->whereIn('task_id', Task::query()->whereIn('parent_id', $groups->keys())->select('id'))->whereIn('status', [TaskCheckStatus::Running, TaskCheckStatus::Lost])
            ->whereNotIn('id', TaskWorkspaceLeaseRecord::query()->where('instance_id', $instance->id)->where('status', 'released')->whereNotNull('check_id')->select('check_id'))
            ->orderBy('id')->first();
        if ($check instanceof TaskCheck) {
            $checkTask = $check->task;
            $ownerGroup = $checkTask instanceof Task ? $groups->get($checkTask->parent_id) : null;
            if (! $ownerGroup instanceof Task) {
                return;
            }
            $lease = $this->adopt($ownerGroup, 'check', 'check:'.$check->task_id);
            $lease->update(['task_id' => $check->task_id, 'adopted' => true, 'pid' => $check->pid, 'process_started' => $check->process_started, 'check_id' => $check->id,
                'head_before' => $check->head_before, 'tree_before' => $check->tree_before]);
            if ($ownerGroup->id === $group->id) {
                unset($this->reconciled[$group->id]);
                $this->reconcile($group);
            }

            return;
        }
        foreach (AgentThread::query()->whereIn('task_group_id', $groups->keys())->orderBy('id')->get() as $thread) {
            $ownerGroup = $groups->get($thread->task_group_id);
            if (! $ownerGroup instanceof Task) {
                continue;
            }
            if ($thread->isPending()) {
                continue;
            }
            $observation = app(AgentThreadObserver::class)->observe($thread);
            $state = $observation?->state;
            $completed = TaskWorkspaceLeaseRecord::query()->where('thread_id', $thread->id)->where('status', 'released')->exists();
            if (! $completed && in_array($state, [AgentThreadState::Idle, AgentThreadState::Done, AgentThreadState::Failed], true)) {
                // Persist authoritative pre-lease completion so a later unreadable runtime
                // cannot resurrect this historical actor as an unknown workspace owner.
                TaskWorkspaceLeaseRecord::query()->create([
                    'instance_id' => $instance->id, 'group_id' => $ownerGroup->id,
                    'kind' => $thread->role, 'owner' => 'thread:'.$thread->id, 'thread_id' => $thread->id,
                    'status' => 'released', 'adopted' => true, 'previous_turn_id' => $observation->turnId,
                    'released_at' => now(),
                ]);
            }
            if (($state === null && ! $completed) || in_array($state, [AgentThreadState::Working, AgentThreadState::AskingForInput], true)) {
                $lease = $this->adopt($ownerGroup, $thread->role, 'thread:'.$thread->id, $thread->id);
                $lease->update(['adopted' => true, 'dispatch_started_at' => now(), 'previous_turn_id' => $observation?->turnId]);

                return;
            }
        }
    }

    public function ownedChange(Task $group, string $head, string $tree, TaskWorkspaceSnapshot $current, ?int $baselineLeaseId = null): bool
    {
        $baseline = $baselineLeaseId === null ? null : TaskWorkspaceLeaseRecord::query()->where('instance_id', $group->taskable_id)->find($baselineLeaseId);
        if (! $baseline instanceof TaskWorkspaceLeaseRecord || $baseline->getAttribute('acquisition_sequence') === null) {
            return false;
        }
        $changed = false;
        foreach (TaskWorkspaceLeaseRecord::query()->where('instance_id', $group->taskable_id)->where('status', 'released')->where('acquisition_sequence', '>', $baseline->getAttribute('acquisition_sequence'))->orderBy('acquisition_sequence')->get() as $record) {
            if ($record->getAttribute('causality_uncertain') || ! $record->getAttribute('orbit_mutation') || $record->getAttribute('adopted') || $record->head_before !== $head || $record->tree_before !== $tree || in_array($record->kind, ['reviewer', 'implementer'], true)
                || $record->head_after === null || $record->tree_after === null) {
                continue;
            }
            $changed = $changed || $head !== $record->head_after || $tree !== $record->tree_after;
            $head = $record->head_after;
            $tree = $record->tree_after;
            if ($changed && $head === $current->head && $tree === $current->tree) {
                return true;
            }
        }

        return false;
    }

    private function pruneQueued(int $instanceId): void
    {
        foreach (TaskWorkspaceLeaseRecord::query()->where('instance_id', $instanceId)->where('status', 'queued')->get() as $waiter) {
            $group = Task::topLevel()->find($waiter->group_id);
            $thread = $waiter->thread_id !== null ? AgentThread::query()->find($waiter->thread_id) : null;
            $task = $waiter->task_id !== null ? Task::query()->find($waiter->task_id) : null;
            $obsolete = ! $group instanceof Task
                || ($waiter->getAttribute('task_id') !== null && (! $task instanceof Task || $task->status === TaskStatus::Cancelled))
                || ($waiter->getAttribute('task_context') !== null && $waiter->getAttribute('task_context') !== $this->taskContext($group, $task))
                || ($waiter->thread_id !== null && ! $thread instanceof AgentThread)
                || (in_array($group->status, TaskGroupStatus::endedStatuses(), true)
                    && ! in_array($waiter->kind, ['publication', 'teardown'], true));
            if ($obsolete) {
                $waiter->update(['status' => 'cancelled', 'released_at' => now()]);
            }
        }
    }

    private function nextSequence(int $instanceId): int
    {
        $last = TaskWorkspaceLeaseRecord::query()->where('instance_id', $instanceId)->whereNotNull('acquisition_sequence')->orderByDesc('acquisition_sequence')->first();

        return ($last->acquisition_sequence ?? 0) + 1;
    }

    /** Existing remote ownership precedes queued requests, even when cancellation ended its task. */
    private function adopt(Task $group, string $kind, string $owner, ?int $threadId = null): TaskWorkspaceLeaseRecord
    {
        $instance = $group->taskable;
        if (! $instance instanceof Instance) {
            throw new TaskCheckException('The task workspace is unavailable.');
        }

        return TaskWorkspaceLeaseRecord::query()->create([
            'instance_id' => $instance->id, 'active_instance_id' => $instance->id, 'group_id' => $group->id,
            'kind' => $kind, 'owner' => $owner, 'thread_id' => $threadId, 'status' => 'held', 'adopted' => true,
            'acquired_at' => now(), 'acquisition_sequence' => $this->nextSequence($instance->id),
        ]);
    }

    /** A deliberate queued retry has not made a remote start attempt. */
    public function recordPendingCheck(Task $group, TaskCheck $check): void
    {
        TaskWorkspaceLeaseRecord::query()->firstOrCreate([
            'instance_id' => $group->taskable_id, 'group_id' => $group->id, 'task_id' => $check->task_id,
            'kind' => 'check', 'owner' => 'pending-check:'.$check->id, 'check_id' => $check->id,
        ], ['status' => 'released', 'released_at' => now()]);
    }

    private function taskContext(Task $group, ?Task $task): string
    {
        $freshGroup = Task::topLevel()->find($group->id) ?? $group;

        return hash('sha256', json_encode([
            'task' => $task?->only(['status', 'review_attempt', 'completion_attempt', 'assistance_requested', 'assistance_kind', 'assistance_reason', 'direction_relay_comment_id', 'direction_relay_sent_comment_id', 'implementer_agent_thread_id', 'reviewer_agent_thread_id', 'pending_resolution_comment_id', 'review_handled_comment_id']),
            'group' => $freshGroup->only(['status', 'assistance_requested', 'assistance_kind', 'assistance_reason', 'watched_pr_completion']),
        ], JSON_THROW_ON_ERROR));
    }

    private function snapshotBefore(Task $group, TaskWorkspaceLeaseRecord $lease): void
    {
        if ($lease->head_before !== null) {
            return;
        }
        $snapshot = $group->taskable instanceof Instance ? app(TaskCheckRunner::class)->snapshot($group->taskable) : null;
        if (! $snapshot instanceof TaskWorkspaceSnapshot) {
            throw new TaskCheckException('The workspace baseline could not be read before admission.');
        }
        $lease->update(['head_before' => $snapshot->head, 'tree_before' => $snapshot->tree]);
    }

    private function snapshot(Task $group): ?TaskWorkspaceSnapshot
    {
        try {
            return $group->taskable instanceof Instance ? app(TaskCheckRunner::class)->snapshot($group->taskable) : null;
        } catch (Throwable) {
            return null;
        }
    }

    private static function bootIdentity(): ?string
    {
        $boot = @file_get_contents('/proc/sys/kernel/random/boot_id');

        return is_string($boot) && trim($boot) !== '' ? trim($boot) : null;
    }

    public function localOwnerDead(TaskWorkspaceLeaseRecord $holder): bool
    {
        if ($holder->getAttribute('gateway_host') !== gethostname() || self::bootIdentity() === null
            || ! is_string($holder->getAttribute('gateway_boot')) || ! is_int($holder->getAttribute('gateway_pid'))
            || ! is_string($holder->getAttribute('gateway_started'))) {
            return false;
        }
        if ($holder->getAttribute('gateway_boot') !== self::bootIdentity()) {
            return true;
        }
        $pid = $holder->getAttribute('gateway_pid');
        $identity = self::processIdentity($pid);
        if ($identity === null && file_exists('/proc/'.$pid)) {
            return false;
        }

        return $identity !== $holder->getAttribute('gateway_started');
    }

    private static function processIdentity(int $pid): ?string
    {
        $stat = @file_get_contents('/proc/'.$pid.'/stat');
        if (! is_string($stat)) {
            return null;
        }
        $fields = explode(' ', substr($stat, strrpos($stat, ')') + 2));

        return $fields[19] ?? null;
    }

    /** @return list<array{
     * instance_id: int, group_id: int,
     * holder: array{id: int, kind: string, owner: string, acquired_at: ?string, thread_id: ?int, check_id: ?int, remote_uncertain: bool}|null,
     * waiters: list<array{id: int, kind: string, owner: string, queued_at: string, thread_id: ?int, check_id: ?int, remote_uncertain: bool}>
     * }> */
    public function status(?Task $group = null): array
    {
        $groupId = $group?->id;
        $rows = TaskWorkspaceLeaseRecord::query()->whereIn('status', ['held', 'queued'])
            ->when($groupId !== null, static fn ($query) => $query->where('group_id', $groupId))->orderBy('id')->get();
        $result = [];
        foreach ($rows as $row) {
            $result[$row->instance_id] ??= ['instance_id' => $row->instance_id, 'group_id' => $row->group_id, 'holder' => null, 'waiters' => []];
            $actor = ['id' => $row->id, 'kind' => $row->kind, 'owner' => $row->owner,
                'thread_id' => $row->thread_id, 'check_id' => $row->check_id, 'remote_uncertain' => (bool) $row->getAttribute('remote_uncertain')];
            if ($row->status === 'held') {
                $result[$row->instance_id]['holder'] = [...$actor, 'acquired_at' => $row->acquired_at?->toIso8601String()];
            } else {
                $result[$row->instance_id]['waiters'][] = [...$actor, 'queued_at' => $row->created_at?->toIso8601String() ?? ''];
            }
        }

        return array_values($result);
    }
}
