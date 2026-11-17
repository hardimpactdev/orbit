<?php

declare(strict_types=1);

namespace App\Domain\Instances\Power;

use App\Actions\Operations\StartOperationAction;
use App\Domain\Executor\ExecutorOrderPublisher;
use App\Domain\Executor\ExecutorOrderSigner;
use App\Domain\Instances\InstanceLifecycleContributions;
use App\Domain\Instances\InstanceRuntimePowerState;
use App\Domain\Operations\OperationStatus;
use App\Domain\Operations\OperationStore;
use App\Infrastructure\Executor\ExecutorResultWait;
use App\Infrastructure\Executor\ExecutorWaitStatus;
use App\Jobs\Operations\CompleteExecutorWake;
use App\Models\Instance;
use App\Models\InstanceRuntime;
use App\Models\Operation;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/** The bounded request and durable completion of ADR 0266 slice 6; no relay or Swamp fallback until slice 7. */
final readonly class ExecutorWake
{
    public const string State = 'wake_executor';

    public const int CompletionSeconds = 6;

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
            $state = [...($operation->state ?? []), self::State => true];
            unset($state['swamp_start']);
            $this->operations->change($operation, ['status' => OperationStatus::Running, 'started_at' => now(), 'attempts' => 1, 'state' => $state]);
            DB::afterCommit(static fn () => Bus::dispatch((new CompleteExecutorWake($operation->id))->delay(now()->addSeconds(self::CompletionSeconds))));
        }

        return $operation;
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
            if ($operation->wasRecentlyCreated) {
                $order = ExecutorOrderSigner::fromConfig()->sign($operation, 'wake', (string) $operation->target_id);
                $this->publisher->publish($operation, $order, max(0, $deadline - hrtime(true) / 1e9));
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
            $this->complete($operation);

            return $operation->refresh()->status === OperationStatus::Succeeded;
        } catch (Throwable $exception) {
            // Neither exception text nor the signed order may enter logs: either can carry a credential.
            Log::warning('The executor wake request could not obtain its result.', ['operation_id' => $operation->id, 'exception_type' => $exception::class]);

            return false;
        }
    }

    public static function owns(Operation $operation): bool
    {
        return $operation->kind === InstanceWake::Kind && ($operation->state[self::State] ?? false) === true;
    }

    /**
     * The request calls this only on success. The queued completion calls it at 6 s with expire=true, closing every other
     * result as a failure until slice 7 supplies the relay and Swamp fallback. It never blocks, signs or publishes.
     */
    public function complete(Operation $operation, bool $expire = false): void
    {
        $succeeded = DB::transaction(function () use ($operation, $expire): bool {
            $operation->setRawAttributes(Operation::query()->whereKey($operation->id)->lockForUpdate()->firstOrFail()->getAttributes(), true);
            if (! self::owns($operation) || $operation->status->terminal()) {
                return false;
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
                'result' => [...($success ? ['power_state' => InstanceRuntimePowerState::Running->value] : []), 'executor' => $line]]);
            if ($success) {
                InstanceRuntime::query()->where('instance_id', $operation->target_id)->update(['power_state' => InstanceRuntimePowerState::Running]);
            }

            return $success;
        });
        if ($succeeded && ($instance = Instance::query()->find($operation->target_id)) !== null) {
            app(InstanceLifecycleContributions::class)->projectWake($instance);
        }
    }
}
