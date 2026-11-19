<?php

declare(strict_types=1);

namespace App\Domain\Instances\Power;

use App\Actions\Operations\StartOperationAction;
use App\Domain\Executor\ExecutorOrderPublisher;
use App\Domain\Executor\ExecutorOrderSigner;
use App\Domain\Instances\InstanceRuntimePowerState;
use App\Domain\Operations\OperationStatus;
use App\Domain\Operations\OperationStore;
use App\Infrastructure\Executor\ExecutorJournalLookup;
use App\Infrastructure\Executor\ExecutorReply;
use App\Infrastructure\Executor\ExecutorResults;
use App\Infrastructure\Executor\ExecutorResultWait;
use App\Infrastructure\Executor\ExecutorWaitStatus;
use App\Infrastructure\Executor\ExecutorWakeLock;
use App\Infrastructure\Swamp\SwampDispatch;
use App\Jobs\Operations\CompleteExecutorWake;
use App\Jobs\Operations\DispatchExecutorWake;
use App\Jobs\Operations\ProjectExecutorWake;
use App\Models\Instance;
use App\Models\InstanceRuntime;
use App\Models\Operation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/** The bounded visitor path and durable native delivery of admitted executor power Operations. */
final readonly class ExecutorWake
{
    public const string State = 'wake_executor';

    public const string FollowUpState = 'wake_executor_follow_up';

    public const string CompletionState = 'wake_executor_completion';

    public const string PublishedAt = 'wake_executor_published_at';

    public const int CompletionSeconds = 6;

    public const string Recovery = 'executor_swamp_recovery';

    private const string PublishFailed = 'executor_publish_failed';

    private const string JournalReadAt = 'executor_journal_read_at';

    private const int JournalRereadSeconds = 10;

    private const string NativeStart = 'executor_native_swamp_start';

    private const string RecoveryQueued = 'executor_recovery_queued_at';

    public const int HandoffLeaseSeconds = 60;

    private const int BatchSize = 50;

    public function __construct(private ExecutorOrderPublisher $publisher, private ExecutorResultWait $wait, private OperationStore $operations, private ExecutorWakeLock $lock,
        private ExecutorJournalLookup $journal, private ExecutorResults $results) {}

    public static function enabled(): bool
    {
        return Config::boolean('orbit.executor.wake_enabled', false);
    }

    /**
     * Called only inside the Instance admission transaction. The publisher claims the committed Operation before signing.
     *
     * @param  array<string, mixed>  $input
     */
    public function admit(Instance $instance, array $input, ?string $caller, ?string $requestId, string $kind = InstanceWake::Kind): Operation
    {
        $operation = app(StartOperationAction::class)->execute($kind, 'instance', $instance->id, $instance->node_id, $input,
            $caller, $requestId, lockKey: OperationStore::lockKey('instance', $instance->id), start: false);
        if ($operation->wasRecentlyCreated) {
            $state = [...($operation->state ?? []), self::State => true, self::CompletionState => 'pending'];
            $state[self::NativeStart] = $state['swamp_start'] ?? null;
            unset($state['swamp_start']);
            $operation->forceFill(['state' => $state])->save();
        }

        return $operation;
    }

    /** Conn calls this from its launch worker; the committed handoff also survives a lost enqueue. */
    public function dispatchDeferred(int $operationId): void
    {
        DB::afterCommit(static function () use ($operationId): void {
            try {
                Bus::dispatch(new DispatchExecutorWake($operationId));
            } catch (Throwable $exception) {
                Log::warning('The deferred executor wake could not be queued; reconciliation retries.',
                    ['operation_id' => $operationId, 'exception_type' => $exception::class]);
            }
        });
    }

    /** True only when this request observes and commits a successful wake within its budget. */
    public function request(Operation $operation, ?ExecutorWakeBudget $budget = null): bool
    {
        if (! self::owns($operation)) {
            return false;
        }
        $budget ??= new ExecutorWakeBudget;

        try {
            $this->publishOnce($operation->id, $budget);
            $remaining = $budget->left();
            if ($remaining < 0.001) {
                return false;
            }
            $durable = function () use ($operation, $budget): ?array {
                $operation->refresh();
                $receipt = $operation->executor_result_received_us;
                $line = $receipt !== null && $receipt <= $budget->receiptDeadline ? $operation->executor_result : null;
                // A failed receipt or an ended Operation ends the wait at once: a held visit gets its error without waiting out the budget.
                if (in_array($operation->status, [OperationStatus::Failed, OperationStatus::Cancelled, OperationStatus::Interrupted], true)) {
                    return ['status' => 'failed'];
                }

                return $line;
            };
            $reply = $this->wait->wait($operation->id, $remaining, $durable);
            if (! in_array($reply->status, [ExecutorWaitStatus::Done, ExecutorWaitStatus::Partial], true) || $budget->left() <= 0) {
                return false;
            }
            if ($operation->status !== OperationStatus::Succeeded) {
                $budget->write(fn (): bool => $this->complete($operation));
            }

            return $budget->left() > 0 && $operation->status === OperationStatus::Succeeded;
        } catch (Throwable $exception) {
            Log::warning('The executor wake request could not obtain its result.', ['operation_id' => $operation->id, 'exception_type' => $exception::class]);

            return false;
        }
    }

    private function publishOnce(int $operationId, ExecutorWakeBudget $budget): bool
    {
        return $this->lock->run('publish', $operationId, function () use ($operationId, $budget): bool {
            $operation = Operation::query()->find($operationId);
            if ($operation === null || ! self::owns($operation) || $operation->status->terminal() || isset($operation->state[self::Recovery])) {
                return false;
            }
            if (self::completionDeadline($operation) !== null || $operation->executor_result !== null) {
                return true;
            }
            if ($operation->status === OperationStatus::Queued) {
                $claimed = $budget->write(fn (): bool => DB::transaction(function () use ($operation): bool {
                    $operation->refresh();
                    if ($operation->status !== OperationStatus::Queued || $operation->attempts !== 0 || $operation->cancel_requested_at !== null) {
                        return false;
                    }
                    $this->operations->change($operation, ['status' => OperationStatus::Running, 'started_at' => now(), 'attempts' => 1]);

                    return true;
                }));
                if ($claimed !== true) {
                    return false;
                }
            }
            if ($operation->status !== OperationStatus::Running || $operation->cancel_requested_at !== null || $budget->left() < 0.001) {
                return false;
            }
            $managed = ExecutorPowerIntent::managed($operation);
            try {
                $order = $managed ? ExecutorPowerIntent::sign($operation) : ExecutorOrderSigner::fromConfig()->sign($operation, 'wake', (string) $operation->target_id);
            } catch (Throwable $exception) {
                Log::warning('The Gateway could not sign the executor order.', ['operation_id' => $operationId, 'exception_type' => $exception::class]);
                $settle = fn () => $this->operations->change($operation, ['status' => OperationStatus::Failed,
                    'finished_at' => now(), 'error_code' => 'executor.sign_failed', 'lock_key' => null], onlyWhileOpen: true);
                if ($budget->write(static function () use ($settle): bool {
                    $settle();

                    return true;
                }) === null) {
                    \Illuminate\Support\defer($settle, always: true);
                }

                return false;
            }
            $publishedAt = now()->getTimestampMs();
            if ($managed && $budget->write(static fn (): bool => DB::transaction(static function () use ($operation, $publishedAt): bool {
                $operation->refresh();
                if ($operation->status !== OperationStatus::Running || $operation->cancel_requested_at !== null) {
                    return false;
                }
                $operation->forceFill(['state' => [...ExecutorFence::issue($operation->state), self::PublishedAt => $publishedAt]])->save();

                return true;
            })) !== true) {
                return false;
            }
            try {
                $this->publisher->publish($operation, $order, $budget->left());
            } catch (Throwable $exception) {
                if (! $managed) {
                    throw $exception;
                }
                // A publish can deliver and still throw, so the order may act: the completion reads the Node's journal after expiry.
                Log::warning('The executor order publish failed; its completion reads the Node journal.', ['operation_id' => $operation->id, 'exception_type' => $exception::class]);
                $this->markUnpublished($operation, $budget);
            }
            if ($managed) {
                return true;
            }
            $publishedAt = now()->getTimestampMs();

            return $budget->write(static fn (): bool => DB::transaction(static function () use ($operation, $publishedAt): bool {
                $operation->refresh();
                if (! $operation->status->terminal()) {
                    $operation->forceFill(['state' => [...($operation->state ?? []), self::PublishedAt => $publishedAt]])->save();
                }

                return true;
            })) === true;
        }) === true;
    }

    public static function completionDeadline(Operation $operation): ?Carbon
    {
        $publishedAt = $operation->state[self::PublishedAt] ?? null;

        return is_int($publishedAt) ? Carbon::createFromTimestampMs($publishedAt)->addSeconds(self::CompletionSeconds) : null;
    }

    public static function owns(Operation $operation): bool
    {
        return $operation->target_type === 'instance' && in_array($operation->kind, [InstanceWake::Kind, InstanceSleep::Kind], true)
            && ($operation->state[self::State] ?? false) === true;
    }

    private function markUnpublished(Operation $operation, ExecutorWakeBudget $budget): void
    {
        $mark = static fn () => DB::transaction(static function () use ($operation): void {
            $operation->refresh();
            if (! $operation->status->terminal()) {
                $operation->forceFill(['state' => [...($operation->state ?? []), self::PublishFailed => true]])->save();
            }
        });
        if ($budget->write(static function () use ($mark): bool {
            $mark();

            return true;
        }) === null) {
            \Illuminate\Support\defer($mark, always: true);
        }
    }

    /** The request only closes a success. The background completion also closes a refusal, failure or expired wake. */
    public function complete(Operation $operation, bool $expire = false): bool
    {
        return DB::transaction(function () use ($operation, $expire): bool {
            $operation->setRawAttributes(Operation::query()->whereKey($operation->id)->lockForUpdate()->firstOrFail()->getAttributes(), true);
            if (! self::owns($operation) || $operation->status !== OperationStatus::Running || isset($operation->state[self::Recovery])) {
                return $operation->status === OperationStatus::Succeeded;
            }
            $line = $operation->executor_result;
            $success = ExecutorResults::succeeded($line);
            $managed = ExecutorPowerIntent::managed($operation);
            $sleep = $operation->kind === InstanceSleep::Kind;
            if ($expire && $managed && ! $sleep && ExecutorFence::handsToBoot($line)) {
                $this->recoverInSwamp($operation);

                return false;
            }
            if (! $success && ! $expire) {
                return false;
            }
            $code = match ($line['status'] ?? null) {
                'refused' => 'executor.wake_refused',
                'failed' => 'executor.wake_failed',
                default => 'executor.wake_timeout',
            };
            $power = $sleep ? app(InstanceSleep::class) : app(InstanceWake::class);
            $incompleteFreeze = $sleep && InstanceSleep::isIncompleteFreeze($line);
            if ($managed) {
                $code = $line === null ? self::unacceptedCode($operation) : ExecutorResults::failureCode($line);
                if ($sleep && $success && ! InstanceSleep::hasSleepOutcome($line)) {
                    $success = false;
                    $code = 'executor.sleep_receipt_missing';
                }
            }
            $state = $operation->state ?? [];
            $this->operations->change($operation, ['status' => $success ? OperationStatus::Succeeded : OperationStatus::Failed,
                'finished_at' => now(), 'lock_key' => null, 'error_code' => $success ? null : $code,
                'state' => [...$state, ...($success && ! $sleep ? [self::FollowUpState => 'pending'] : [])],
                'result' => $success ? $power->signedResult($operation, $line ?? []) : ['executor' => $line,
                    ...($incompleteFreeze ? ['sleep' => 'incomplete', 'power_state' => InstanceRuntimePowerState::Stopped->value] : [])]]);
            if ($success || $incompleteFreeze) {
                InstanceRuntime::query()->where('instance_id', $operation->target_id)->update([
                    'power_state' => $sleep ? InstanceRuntimePowerState::Stopped : InstanceRuntimePowerState::Running,
                ]);
            }

            return $success;
        });
    }

    public function completeHandoff(Operation $operation): void
    {
        if (! $this->reconcile($operation)) {
            return;
        }
        $this->complete($operation, expire: true);
        if ($operation->status->terminal() && $operation->kind === InstanceWake::Kind && ExecutorPowerIntent::managed($operation)) {
            app(ExecutorWakeNotices::class)->relay($operation->id);
        }
    }

    /**
     * R1/R2 for a signed order with no result by its deadline: the Node's journal decides (ADR 0266, "Reconcile by the journal"). A done
     * record is the result. No record after the order expired on the Node proves it never acted, so the fence lifts. While the order is
     * still open there, the Operation keeps running and the next completion pass reads again. A Node that cannot be read ends the
     * Operation interrupted under its fence (`executor.result_unknown`); the reconcile pass reads again until the journal answers.
     * False keeps the Operation as it is.
     */
    private function reconcile(Operation $operation): bool
    {
        $operation->refresh();
        $published = $operation->state[self::PublishedAt] ?? null;
        if (! ExecutorPowerIntent::managed($operation) || $operation->executor_result !== null || isset($operation->state[self::Recovery])
            || ! is_int($published) || ! ($operation->status === OperationStatus::Running || self::unknown($operation))) {
            return true;
        }
        try {
            $found = $this->journal->find($operation, ExecutorPowerIntent::action($operation) ?? 'wake', $published);
        } catch (Throwable $exception) {
            Log::warning('The Node executor journal could not be read; the Operation stays fenced and the reconcile pass reads it again.',
                ['operation_id' => $operation->id, 'exception_type' => $exception::class]);
            $this->operations->change($operation, ['status' => OperationStatus::Failed, 'finished_at' => now(),
                'state' => [...($operation->state ?? []), self::JournalReadAt => now()->getTimestamp()]], onlyWhileOpen: true);

            return false;
        }
        if ($found === ExecutorJournalLookup::Open) {
            return false;
        }
        if ($found === ExecutorJournalLookup::Absent) {
            DB::transaction(function () use ($operation): void {
                $operation->refresh();
                if ($operation->executor_result !== null) {
                    return;
                }
                $operation->forceFill(['state' => [...($operation->state ?? []), ExecutorFence::Absent => true]])->save();
                if (self::unknown($operation)) {
                    $this->operations->change($operation, ['status' => OperationStatus::Failed, 'finished_at' => now(),
                        'error_code' => self::unacceptedCode($operation), 'lock_key' => null]);
                }
            });

            return true;
        }
        $reply = ExecutorReply::fromEvent(['operation' => (string) $operation->id, 'line' => json_encode($found, JSON_THROW_ON_ERROR)]);
        if ($reply !== null && $operation->node_id !== null) {
            $this->results->receive($operation->node_id, $reply, 'journal');
        }
        $operation->refresh();

        return $operation->executor_result !== null;
    }

    /** An Operation that ended under its fence because no result was known (ExecutorFence::settle). */
    private static function unknown(Operation $operation): bool
    {
        return $operation->status === OperationStatus::Interrupted && $operation->lock_key !== null && $operation->error_code === 'executor.result_unknown';
    }

    private static function unacceptedCode(Operation $operation): string
    {
        return ($operation->state[self::PublishFailed] ?? false) === true ? 'executor.publish_failed' : 'executor.not_accepted';
    }

    /** @return Builder<Operation> */
    private function unknowns(): Builder
    {
        return Operation::query()->whereIn('kind', [InstanceWake::Kind, InstanceSleep::Kind])->where('target_type', 'instance')
            ->where('state->'.self::State, true)->where('status', OperationStatus::Interrupted)->where('error_code', 'executor.result_unknown')
            ->whereNotNull('lock_key')->whereNull('executor_result')->whereNotNull('state->'.self::PublishedAt)
            ->where(fn ($query) => $query->whereNull('state->'.self::JournalReadAt)
                ->orWhere('state->'.self::JournalReadAt, '<=', now()->getTimestamp() - self::JournalRereadSeconds));
    }

    private function rereadJournal(int $operationId): bool
    {
        $operation = $this->unknowns()->whereKey($operationId)->first();
        if ($operation === null) {
            return false;
        }
        $operation->forceFill(['state' => [...($operation->state ?? []), self::JournalReadAt => now()->getTimestamp()]])->save();

        return $this->reconcile($operation);
    }

    private function recoverInSwamp(Operation $operation): void
    {
        $state = $operation->state ?? [];
        $this->operations->change($operation, ['status' => OperationStatus::Queued,
            'state' => [...$state, self::Recovery => true, self::CompletionState => 'delivered', self::RecoveryQueued => now()->timestamp,
                'executor_swamp_recovery_at_ms' => now()->getTimestampMs(),
                'swamp_start' => $state[self::NativeStart] ?? null]]);
        app(SwampDispatch::class)->retry($operation);
    }

    public function hasHandoffs(): bool
    {
        return $this->completions()->exists() || $this->hooks()->exists() || $this->recoveryStarts()->exists() || $this->unknowns()->exists();
    }

    /** Recovers abandoned publication as well as lost completion jobs. */
    public function recoverPendingCompletions(): int
    {
        $completions = $this->completions()
            ->orderByRaw('executor_result is null')
            ->orderByRaw('coalesce(started_at, created_at)');

        return $this->recover($completions, $this->continueHandoff(...), 'completion')
            + $this->recover($this->recoveryStarts(), $this->retryRecoveryStart(...), 'Swamp start')
            + $this->recover($this->unknowns(), $this->rereadJournal(...), 'journal');
    }

    /** @return Builder<Operation> */
    private function recoveryStarts(): Builder
    {
        return Operation::query()->where('state->'.self::Recovery, true)->where('status', OperationStatus::Queued)
            ->whereNull('state->start_deferrals')->where('state->'.self::RecoveryQueued, '<=', now()->getTimestamp() - self::HandoffLeaseSeconds);
    }

    private function retryRecoveryStart(int $operationId): bool
    {
        return DB::transaction(function () use ($operationId): bool {
            $operation = $this->recoveryStarts()->whereKey($operationId)->lockForUpdate()->first();
            if ($operation === null) {
                return false;
            }
            $operation->forceFill(['state' => [...($operation->state ?? []), self::RecoveryQueued => now()->timestamp]])->save();
            app(SwampDispatch::class)->retry($operation);

            return true;
        });
    }

    public function continueHandoff(int $operationId): bool
    {
        $operation = Operation::query()->find($operationId);
        if ($operation === null || ! self::owns($operation) || $operation->status->terminal() || isset($operation->state[self::Recovery])) {
            return false;
        }
        if (self::completionDeadline($operation) === null && $operation->executor_result === null && ! $this->publishOnce($operationId, new ExecutorWakeBudget)) {
            return false;
        }

        $queued = $this->enqueue($operationId, self::CompletionState, OperationStatus::Running);
        $operation->refresh();
        if (ExecutorPowerIntent::managed($operation) && $operation->executor_result !== null) {
            $this->completeHandoff($operation);
        }

        return $queued;
    }

    public function recoverPendingHooks(): int
    {
        return $this->recover($this->hooks()->orderBy('finished_at'), fn (int $id): bool => $this->enqueue($id, self::FollowUpState, OperationStatus::Succeeded), 'hook');
    }

    /** @return Builder<Operation> */
    private function completions(): Builder
    {
        return $this->eligible(self::CompletionState)->whereIn('status', [OperationStatus::Queued, OperationStatus::Running])
            ->where(fn ($query) => $query->whereNotNull('state->'.self::PublishedAt)->orWhereNotNull('executor_result')
                ->orWhereRaw('coalesce(started_at, created_at) <= ?', [now()->subSeconds(self::CompletionSeconds)->toDateTimeString()]));
    }

    /** @return Builder<Operation> */
    private function hooks(): Builder
    {
        return $this->eligible(self::FollowUpState)->where('kind', InstanceWake::Kind)->where('status', OperationStatus::Succeeded)->whereNotNull('finished_at');
    }

    /** @return Builder<Operation> */
    private function eligible(string $state): Builder
    {
        return Operation::query()->whereIn('kind', [InstanceWake::Kind, InstanceSleep::Kind])->where('target_type', 'instance')->where('state->'.self::State, true)
            ->where(fn ($query) => $query->where('state->'.$state, 'pending')
                ->orWhere(fn ($query) => $query->where('state->'.$state, 'queued')->where('state->'.$state.'_until', '<=', now()->timestamp)));
    }

    /** @param Builder<Operation> $query
     * @param  callable(int):bool  $handoff
     */
    private function recover(Builder $query, callable $handoff, string $kind): int
    {
        $recovered = [];
        foreach ($query->orderBy('id')->limit(self::BatchSize)->get(['id']) as $operation) {
            try {
                if ($handoff($operation->id)) {
                    $recovered[] = $operation->id;
                }
            } catch (Throwable $exception) {
                Log::warning('An executor wake '.$kind.' handoff could not be recovered; the next pass tries again.',
                    ['operation_id' => $operation->id, 'exception_type' => $exception::class]);
                break;
            }
        }
        if ($recovered !== []) {
            Log::info('Executor wake '.$kind.' handoffs recovered.', ['recovered' => count($recovered), 'operation_ids' => $recovered]);
        }

        return count($recovered);
    }

    private function enqueue(int $operationId, string $state, OperationStatus $status): bool
    {
        $leaseKey = $state.'_until';
        $claim = DB::transaction(function () use ($operationId, $state, $status, $leaseKey): ?array {
            $operation = $this->eligible($state)->whereKey($operationId)->where('status', $status)->first();
            if ($operation === null) {
                return null;
            }
            $due = $state === self::CompletionState ? self::completionDeadline($operation) : null;
            $lease = max(now()->getTimestamp(), $due?->getTimestamp() ?? 0) + self::HandoffLeaseSeconds;
            $operation->forceFill(['state' => [...($operation->state ?? []), $state => 'queued', $leaseKey => $lease]])->save();

            return [$due, $lease];
        });
        if ($claim === null) {
            return false;
        }
        [$due, $lease] = $claim;
        try {
            Bus::dispatch($state === self::CompletionState ? (new CompleteExecutorWake($operationId))->delay($due) : new ProjectExecutorWake($operationId));
        } catch (Throwable $exception) {
            DB::transaction(static function () use ($operationId, $state, $leaseKey, $lease, $status): void {
                $operation = Operation::query()->whereKey($operationId)->where('status', $status)->where('state->'.$state, 'queued')
                    ->where('state->'.$leaseKey, $lease)->first();
                if ($operation !== null) {
                    $operation->forceFill(['state' => [...($operation->state ?? []), $state => 'pending', $leaseKey => null]])->save();
                }
            });
            throw $exception;
        }

        return true;
    }
}
