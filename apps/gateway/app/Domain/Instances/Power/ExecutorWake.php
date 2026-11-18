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
use App\Jobs\Operations\CompleteExecutorWake;
use App\Jobs\Operations\DispatchExecutorWake;
use App\Jobs\Operations\ProjectExecutorWake;
use App\Models\Instance;
use App\Models\InstanceRuntime;
use App\Models\Operation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
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

    private const int PublicationLockSeconds = 30;

    private const int FollowUpGraceSeconds = 30;

    private const int FollowUpBatchSize = 50;

    private const int CompletionBatchSize = 50;

    public function __construct(private ExecutorOrderPublisher $publisher, private ExecutorResultWait $wait, private OperationStore $operations) {}

    public static function enabled(): bool
    {
        return Config::boolean('orbit.executor.wake_enabled', false);
    }

    /**
     * Called only by InstanceWake's admission under the Instance lock. The Operation commits before request() signs anything.
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
            $this->operations->change($operation, ['status' => OperationStatus::Running, 'started_at' => now(), 'attempts' => 1, 'state' => $state]);
        }

        return $operation;
    }

    public function dispatchDeferred(int $operationId): void
    {
        DB::afterCommit(static function () use ($operationId): void {
            try {
                Bus::dispatch(new DispatchExecutorWake($operationId));
            } catch (Throwable $exception) {
                Log::warning('The deferred executor wake could not be queued; the minute sweep retries.',
                    ['operation_id' => $operationId, 'exception_type' => $exception::class]);
            }
        });
    }

    /** True only when this request sees a successful wake within its publish-and-wait budget. */
    public function request(Operation $operation): bool
    {
        if (! self::owns($operation)) {
            return false;
        }
        $bound = ExecutorResultWait::boundSeconds();
        $deadline = hrtime(true) / 1e9 + $bound;
        $completedBy = now()->addMilliseconds($bound * 1000);

        try {
            if ($this->publishOnce($operation->id, $deadline)) {
                try {
                    $this->enqueueCompletion($operation->id);
                } catch (Throwable $exception) {
                    Log::warning('The executor wake completion could not be queued; the minute sweep retries.',
                        ['operation_id' => $operation->id, 'exception_type' => $exception::class]);
                }
            }
            $operation->refresh();
            $line = $operation->executor_result;
            if ($line === null && ! $operation->status->terminal()) {
                $left = $deadline - hrtime(true) / 1e9;
                if ($left < 0.001) {
                    return false;
                }
                $reply = $this->wait->wait($operation->id, $left);
                $operation->refresh();
                // BLPOP has one consumer. A sibling may have taken the line and completed the same wake during our wait.
                if ($reply->status === ExecutorWaitStatus::Timeout && $operation->status === OperationStatus::Succeeded
                    && $operation->finished_at !== null && $operation->finished_at->lte($completedBy)) {
                    return true;
                }
                if (! in_array($reply->status, [ExecutorWaitStatus::Done, ExecutorWaitStatus::Partial], true)) {
                    return false;
                }
                $line = $operation->executor_result;
            }
            if (hrtime(true) / 1e9 > $deadline || ! in_array($line['status'] ?? null, ['done', 'partial'], true)) {
                return false;
            }
            try {
                $this->complete($operation);
            } catch (Throwable $exception) {
                if ($operation->refresh()->status !== OperationStatus::Succeeded) {
                    throw $exception;
                }
                Log::warning('The executor wake succeeded but its follow-up could not be queued; the minute sweep retries.',
                    ['operation_id' => $operation->id, 'exception_type' => $exception::class]);
            }

            return $operation->refresh()->status === OperationStatus::Succeeded;
        } catch (Throwable $exception) {
            // Neither exception text nor the signed order may enter logs: either can carry a credential.
            Log::warning('The executor wake request could not obtain its result.', ['operation_id' => $operation->id, 'exception_type' => $exception::class]);

            return false;
        }
    }

    /** Publishing holds only the cache lock: a sibling visit can still read and wait without a SQLite writer. */
    private function publishOnce(int $operationId, float $deadline): bool
    {
        return Cache::lock('orbit:executor-wake-publish:'.$operationId, self::PublicationLockSeconds)->get(function () use ($operationId, $deadline): bool {
            $operation = Operation::query()->find($operationId);
            if ($operation === null || ! self::owns($operation) || $operation->status->terminal()) {
                return false;
            }
            if (self::completionDeadline($operation) !== null) {
                return true;
            }
            $left = $deadline - hrtime(true) / 1e9;
            if ($left < 0.001) {
                return false;
            }
            $order = ExecutorOrderSigner::fromConfig()->sign($operation, 'wake', (string) $operation->target_id);
            $this->publisher->publish($operation, $order, $left);
            $publishedAt = now()->getTimestampMs();
            DB::transaction(static function () use ($operationId, $publishedAt): void {
                $current = Operation::query()->whereKey($operationId)->lockForUpdate()->firstOrFail();
                if (self::completionDeadline($current) === null) {
                    $current->forceFill(['state' => [...($current->state ?? []), self::PublishedAt => $publishedAt]])->save();
                }
            });

            return true;
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

    /**
     * The request calls this only on success. The queued completion calls it 6 s after publication with expire=true, closing every other
     * result as a failure until slice 7 supplies the relay and Swamp fallback. It never blocks, signs or publishes.
     */
    public function complete(Operation $operation, bool $expire = false): void
    {
        DB::transaction(function () use ($operation, $expire): void {
            $operation->setRawAttributes(Operation::query()->whereKey($operation->id)->lockForUpdate()->firstOrFail()->getAttributes(), true);
            if (! self::owns($operation)) {
                return;
            }
            if ($operation->status->terminal()) {
                if ($operation->status === OperationStatus::Succeeded && ($operation->state[self::FollowUpState] ?? null) === 'pending') {
                    DB::afterCommit(fn () => $this->enqueueHooks($operation->id));
                }

                return;
            }
            $line = $operation->executor_result;
            $success = in_array($line['status'] ?? null, ['done', 'partial'], true);
            if (! $success && ! $expire) {
                return;
            }
            $code = match ($line['status'] ?? null) {
                'refused' => 'executor.wake_refused',
                'failed' => 'executor.wake_failed',
                default => 'executor.wake_timeout',
            };
            if ($success) {
                // Register before change() registers the terminal notices; the pending handoff commits with success.
                DB::afterCommit(fn () => $this->enqueueHooks($operation->id));
            }
            $this->operations->change($operation, ['status' => $success ? OperationStatus::Succeeded : OperationStatus::Failed,
                'finished_at' => now(), 'lock_key' => null, 'error_code' => $success ? null : $code,
                ...($success ? ['state' => [...($operation->state ?? []), self::FollowUpState => 'pending']] : []),
                'result' => [...($success ? ['power_state' => InstanceRuntimePowerState::Running->value] : []), 'executor' => $line]]);
            if ($success) {
                InstanceRuntime::query()->where('instance_id', $operation->target_id)->update(['power_state' => InstanceRuntimePowerState::Running]);
            }
        });
    }

    /** Recovers the initial handoff without another visit, including a request that stopped before publishing its order. */
    public function recoverPendingCompletions(): int
    {
        $pending = Operation::query()->where('kind', InstanceWake::Kind)->where('target_type', 'instance')
            ->where('status', OperationStatus::Running)->where('state->'.self::State, true)
            ->where('state->'.self::CompletionState, 'pending')
            ->where('started_at', '<=', now()->subSeconds(self::CompletionSeconds))
            ->orderBy('started_at')->orderBy('id')->limit(self::CompletionBatchSize)->get(['id']);
        $recovered = [];
        foreach ($pending as $operation) {
            try {
                if ($this->continueHandoff($operation->id)) {
                    $recovered[] = $operation->id;
                }
            } catch (Throwable $exception) {
                Log::warning('An executor wake completion handoff could not be recovered; the next pass tries again.',
                    ['operation_id' => $operation->id, 'exception_type' => $exception::class]);
                break;
            }
        }
        if ($recovered !== []) {
            Log::info('Executor wake completion handoffs recovered.', ['recovered' => count($recovered), 'operation_ids' => $recovered]);
        }

        return count($recovered);
    }

    public function continueHandoff(int $operationId): bool
    {
        return $this->publishOnce($operationId, hrtime(true) / 1e9 + min(0.5, ExecutorResultWait::boundSeconds()))
            && $this->enqueueCompletion($operationId);
    }

    private function enqueueCompletion(int $operationId): bool
    {
        return DB::transaction(static function () use ($operationId): bool {
            $operation = Operation::query()->whereKey($operationId)->lockForUpdate()->first();
            if ($operation === null || ! self::owns($operation) || $operation->status !== OperationStatus::Running
                || ($operation->state[self::CompletionState] ?? null) !== 'pending') {
                return false;
            }
            $deadline = self::completionDeadline($operation);
            if ($deadline === null) {
                return false;
            }
            Bus::dispatch((new CompleteExecutorWake($operation->id))->beforeCommit()->delay($deadline));
            $operation->forceFill(['state' => [...($operation->state ?? []), self::CompletionState => 'queued']])->save();

            return true;
        });
    }

    /** Recovers admitted work even if executor wakes are now disabled or completion exhausted its retries. */
    public function recoverPendingHooks(): int
    {
        $pending = Operation::query()->where('kind', InstanceWake::Kind)->where('target_type', 'instance')
            ->where('status', OperationStatus::Succeeded)->where('state->'.self::State, true)
            ->where('state->'.self::FollowUpState, 'pending')
            ->where('finished_at', '<=', now()->subSeconds(self::FollowUpGraceSeconds))
            ->orderBy('finished_at')->orderBy('id')->limit(self::FollowUpBatchSize)->get(['id']);
        $recovered = [];
        foreach ($pending as $operation) {
            try {
                if ($this->enqueueHooks($operation->id)) {
                    $recovered[] = $operation->id;
                }
            } catch (Throwable $exception) {
                Log::warning('An executor wake hook handoff could not be recovered; the next pass tries again.',
                    ['operation_id' => $operation->id, 'exception_type' => $exception::class]);
                break;
            }
        }
        if ($recovered !== []) {
            Log::info('Executor wake hook handoffs recovered.', ['recovered' => count($recovered), 'operation_ids' => $recovered]);
        }

        return count($recovered);
    }

    /** Success is already committed. A failed enqueue leaves the handoff for completion or the minute sweep. */
    private function enqueueHooks(int $operationId): bool
    {
        return DB::transaction(static function () use ($operationId): bool {
            $operation = Operation::query()->whereKey($operationId)->lockForUpdate()->first();
            if ($operation === null || ! self::owns($operation) || $operation->status !== OperationStatus::Succeeded
                || ($operation->state[self::FollowUpState] ?? null) !== 'pending') {
                return false;
            }
            // The Gateway queue defaults to after_commit: enqueue now so a failure rolls back the queued marker.
            Bus::dispatch((new ProjectExecutorWake($operation->id))->beforeCommit());
            $operation->forceFill(['state' => [...($operation->state ?? []), self::FollowUpState => 'queued']])->save();

            return true;
        });
    }
}
