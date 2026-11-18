<?php

declare(strict_types=1);

namespace App\Domain\Instances\Power;

use App\Actions\Operations\StartOperationAction;
use App\Domain\Executor\ExecutorOrderPublisher;
use App\Domain\Executor\ExecutorOrderSigner;
use App\Domain\Instances\InstanceRuntimePowerState;
use App\Domain\Operations\OperationStatus;
use App\Domain\Operations\OperationStore;
use App\Infrastructure\Executor\ExecutorResultWait;
use App\Infrastructure\Executor\ExecutorWaitStatus;
use App\Infrastructure\Executor\ExecutorWakeLock;
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

/** The bounded request and durable completion of ADR 0266 slice 6; no relay or Swamp fallback until slice 7. */
final readonly class ExecutorWake
{
    public const string State = 'wake_executor';

    public const string FollowUpState = 'wake_executor_follow_up';

    public const string CompletionState = 'wake_executor_completion';

    public const string PublishedAt = 'wake_executor_published_at';

    public const int CompletionSeconds = 6;

    public const int HandoffLeaseSeconds = 60;

    private const int BatchSize = 50;

    public function __construct(private ExecutorOrderPublisher $publisher, private ExecutorResultWait $wait, private OperationStore $operations, private ExecutorWakeLock $lock) {}

    public static function enabled(): bool
    {
        return Config::boolean('orbit.executor.wake_enabled', false);
    }

    /**
     * Called only inside the Instance admission transaction. The publisher claims the committed Operation before signing.
     *
     * @param  array<string, mixed>  $input
     */
    public function admit(Instance $instance, array $input, ?string $caller, ?string $requestId): Operation
    {
        $operation = app(StartOperationAction::class)->execute(InstanceWake::Kind, 'instance', $instance->id, $instance->node_id, $input,
            $caller, $requestId, lockKey: OperationStore::lockKey('instance', $instance->id), start: false);
        if ($operation->wasRecentlyCreated) {
            $state = [...($operation->state ?? []), self::State => true, self::CompletionState => 'pending'];
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
            $reply = $this->wait->wait($operation->id, $remaining, function () use ($operation, $budget): ?array {
                $operation->refresh();
                $receipt = $operation->executor_result_received_us;

                return $receipt !== null && $receipt <= $budget->receiptDeadline && ! in_array($operation->status, [OperationStatus::Failed, OperationStatus::Cancelled, OperationStatus::Interrupted], true)
                    ? $operation->executor_result : null;
            });
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
            if ($operation === null || ! self::owns($operation) || $operation->status->terminal()) {
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
            if ($operation->status !== OperationStatus::Running || $budget->left() < 0.001) {
                return false;
            }
            $order = ExecutorOrderSigner::fromConfig()->sign($operation, 'wake', (string) $operation->target_id);
            $this->publisher->publish($operation, $order, $budget->left());
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
        return $operation->kind === InstanceWake::Kind && ($operation->state[self::State] ?? false) === true;
    }

    /** The request only closes a success. The background completion also closes a refusal, failure or expired wake. */
    public function complete(Operation $operation, bool $expire = false): bool
    {
        return DB::transaction(function () use ($operation, $expire): bool {
            $operation->refresh();
            if (! self::owns($operation) || $operation->status !== OperationStatus::Running) {
                return $operation->status === OperationStatus::Succeeded;
            }
            $line = $operation->executor_result;
            $success = in_array($line['status'] ?? null, ['done', 'partial'], true);
            if (! $success && ! $expire) {
                return false;
            }
            $code = match ($line['status'] ?? null) {
                'refused' => 'executor.wake_refused',
                'failed' => 'executor.wake_failed',
                default => 'executor.wake_timeout',
            };
            $this->operations->change($operation, ['status' => $success ? OperationStatus::Succeeded : OperationStatus::Failed,
                'finished_at' => now(), 'lock_key' => null, 'error_code' => $success ? null : $code,
                ...($success ? ['state' => [...($operation->state ?? []), self::FollowUpState => 'pending']] : []),
                'result' => [...($success ? ['power_state' => InstanceRuntimePowerState::Running->value] : []), 'executor' => $line]]);
            if ($success) {
                InstanceRuntime::query()->where('instance_id', $operation->target_id)->update(['power_state' => InstanceRuntimePowerState::Running]);
            }

            return $success;
        });
    }

    public function hasHandoffs(): bool
    {
        return $this->completions()->exists() || $this->hooks()->exists();
    }

    /** Recovers abandoned publication as well as lost completion jobs. */
    public function recoverPendingCompletions(): int
    {
        $completions = $this->completions()
            ->orderByRaw('executor_result is null')
            ->orderByRaw('coalesce(started_at, created_at)');

        return $this->recover($completions, $this->continueHandoff(...), 'completion');
    }

    public function continueHandoff(int $operationId): bool
    {
        $operation = Operation::query()->find($operationId);
        if ($operation === null || ! self::owns($operation) || $operation->status->terminal()) {
            return false;
        }
        if (self::completionDeadline($operation) === null && $operation->executor_result === null && ! $this->publishOnce($operationId, new ExecutorWakeBudget)) {
            return false;
        }

        return $this->enqueue($operationId, self::CompletionState, OperationStatus::Running);
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
        return $this->eligible(self::FollowUpState)->where('status', OperationStatus::Succeeded)->whereNotNull('finished_at');
    }

    /** @return Builder<Operation> */
    private function eligible(string $state): Builder
    {
        return Operation::query()->where('kind', InstanceWake::Kind)->where('target_type', 'instance')->where('state->'.self::State, true)
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
