<?php

declare(strict_types=1);

namespace App\Domain\Instances\Power;

use App\Actions\Operations\StartOperationAction;
use App\Domain\Hibernation\RuntimeHibernation;
use App\Domain\Instances\InstanceContainer;
use App\Domain\Instances\InstanceRuntimePowerState;
use App\Domain\Instances\InstanceState;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Operations\OperationIdempotency;
use App\Domain\Operations\OperationStatus;
use App\Domain\Operations\OperationStore;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\TaskGroupStatus;
use App\Infrastructure\Executor\ExecutorResults;
use App\Infrastructure\Swamp\SwampSshExecutionInput;
use App\Models\Instance;
use App\Models\InstanceRuntime;
use App\Models\Operation;
use App\Models\Task;
use Closure;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Sleep and wake of a development container (docs/reference/development-containers.md#sleep-and-wake). The sleep freezes the whole
 * container behind the asleep marker, with its memory in swap; the wake resumes it (or starts a stopped one) and removes the marker.
 * Each terminal success records `power_state`, and so does a wake that ends after the container runs and its site serves.
 */
abstract readonly class InstancePower implements DirectOperationProjection
{
    abstract protected function kind(): string;

    abstract protected function powerState(): InstanceRuntimePowerState;

    /** Refusals that only this kind makes, inside the Instance lock. */
    abstract protected function refuse(Instance $instance, InstanceRuntime $runtime): void;

    /** @return array<string, string> */
    protected function result(Operation $operation): array
    {
        return ['power_state' => $this->powerState()->value];
    }

    /** @return list<string> The keys of the run's result that the Operation's result keeps, on success and on failure. */
    protected function phaseKeys(): array
    {
        return [];
    }

    /**
     * The run's result keys that the Operation's result keeps, on success and on failure.
     *
     * @param  array<array-key, mixed>  $phase
     * @return array<array-key, mixed>
     */
    protected function kept(array $phase): array
    {
        return array_intersect_key($phase, array_flip($this->phaseKeys()));
    }

    /** Work after a terminal Operation's transaction committed. */
    protected function ended(Operation $operation): void {}

    /** @return array<string, mixed> The kind's own intent beside the container and the marker. */
    protected function intent(Instance $instance): array
    {
        return [];
    }

    /** Whether the ended Operation leaves the container in this kind's power state: a success always does. */
    protected function reached(Operation $operation): bool
    {
        return $operation->status === OperationStatus::Succeeded;
    }

    /** The asleep marker on the Instance's Node; Caddy's site of a container Instance reads it. */
    public static function markerPath(Instance $instance): string
    {
        return RuntimeHibernation::asleepPath(RuntimeHibernation::key($instance->id));
    }

    public function start(Instance $instance, ?string $caller, ?string $requestId): Operation
    {
        return $this->startWhen($instance, $caller, $requestId) ?? throw new LogicException('Power admission requires a receipt.');
    }

    /** @param  (Closure(Instance): bool)|null  $guard  A condition checked under the Instance lock after the preflight. */
    protected function startWhen(Instance $instance, ?string $caller, ?string $requestId, ?Closure $guard = null): ?Operation
    {
        if (($replay = app(OperationIdempotency::class)->replay($this->kind())) !== null) {
            return $replay;
        }
        // The refusals run once without the lock, so the work before the admission runs only for an Instance that may be admitted.
        if ($this->admissible(Instance::query()->with(['node', 'project'])->findOrFail($instance->id))['open'] === null) {
            $this->beforeAdmission($instance);
        }

        return DB::transaction(function () use ($instance, $caller, $requestId, $guard): ?Operation {
            $instance = Instance::query()->with(['node', 'project'])->lockForUpdate()->findOrFail($instance->id);
            if ($guard !== null && ! $guard($instance)) {
                return null;
            }
            ['runtime' => $runtime, 'open' => $open] = $this->admissible($instance, join: true);
            if ($open !== null) {
                return $open;
            }

            return $this->admit($instance, $runtime,
                ['container' => app(InstanceContainer::class)->intent($instance, $runtime), 'marker' => self::markerPath($instance), ...$this->intent($instance)],
                $caller, $requestId);
        });
    }

    /** Work of the kind after the refusals and before the Instance lock, such as a last read of the Instance. It must not fail. */
    protected function beforeAdmission(Instance $instance): void {}

    /**
     * Every refusal of the kind, in order: the Instance's runtime, or an open Operation of this kind that a second caller joins.
     *
     * @return array{runtime: InstanceRuntime, open: ?Operation}
     */
    private function admissible(Instance $instance, bool $join = false): array
    {
        $runtime = $instance->runtime()->first();
        if ($runtime === null) {
            throw new ResourceOperationException('operation.kind_unavailable', 'Sleep and wake require a container Instance.', 409);
        }
        // A default and a nested-fleet Instance sleep and wake as every development container does (docs/reference/development-containers.md#sleep-and-wake).
        if ($instance->placementEnvironment() !== 'development') {
            throw new ResourceOperationException('instance.sleep_unsupported', 'Only a development container sleeps and wakes.', 409);
        }
        if (($open = $this->open($instance, $join)) !== null) {
            return ['runtime' => $runtime, 'open' => $open];
        }
        self::assertIdle($instance);
        $this->refuse($instance, $runtime);

        return ['runtime' => $runtime, 'open' => null];
    }

    /**
     * Records the admitted Operation inside the Instance lock and starts its run after the commit.
     *
     * @param  array<string, mixed>  $input
     */
    protected function admit(Instance $instance, InstanceRuntime $runtime, array $input, ?string $caller, ?string $requestId): Operation
    {
        return app(StartOperationAction::class)->execute($this->kind(), 'instance', $instance->id, $instance->node_id, $input,
            $caller, $requestId, lockKey: OperationStore::lockKey('instance', $instance->id));
    }

    /** @param array<string, mixed> $receipt
     * @return array<string, mixed>
     */
    public function signedResult(Operation $operation, array $receipt): array
    {
        return [...$this->result($operation), ...$this->kept($receipt), 'executor' => $receipt];
    }

    /**
     * The power Operations' shared refusal of other lifecycle work: an Instance that is not active, or a Process of it with an open
     * Operation. A sleep, a wake and the start of a nested-fleet clone (InstanceStart) all call it inside the Instance lock.
     */
    public static function assertIdle(Instance $instance, string $inactive = 'The Instance has unfinished lifecycle work.'): void
    {
        if ($instance->status !== InstanceState::Active) {
            throw new ResourceOperationException('instance.lifecycle_busy', $inactive, 409);
        }
        if (Operation::query()->where('target_type', 'process')->whereIn('target_id', $instance->processes()->select('id'))
            ->whereNotIn('status', OperationStatus::terminalValues())->exists()) {
            throw new ResourceOperationException('instance.lifecycle_busy', 'The Instance has unfinished lifecycle work.', 409);
        }
    }

    /** An open Operation of this kind that a second caller joins; only a wake is joined. */
    protected function open(Instance $instance, bool $join): ?Operation
    {
        return null;
    }

    public static function hasActiveTask(Instance $instance): bool
    {
        return Task::topLevel()->whereMorphedTo('taskable', $instance)->whereIn('status', TaskGroupStatus::active())->exists()
            || ($instance->task_workspace_owner_id !== null
                && Task::topLevel()->whereKey($instance->task_workspace_owner_id)->whereIn('status', TaskGroupStatus::active())->exists());
    }

    public function input(Operation $operation): array
    {
        $instance = Instance::query()->with('node')->findOrFail($operation->target_id);

        return ['intent' => [...$operation->input,
            'ssh_execution' => app(SwampSshExecutionInput::class)->forNode($instance->node)]];
    }

    public function apply(Operation $operation, array $data): Operation
    {
        [$operation, $endedBefore] = DB::transaction(function () use ($operation, $data): array {
            $operation = Operation::query()->lockForUpdate()->findOrFail($operation->id);
            $endedBefore = $operation->status->terminal();
            $phase = is_array($data['result'] ?? null) ? $data['result'] : [];
            $succeeded = ($data['status'] ?? null) === OperationStatus::Succeeded->value;
            $kept = $this->kept($phase);
            $action = ExecutorPowerIntent::action($operation);
            $receipt = $action === null ? null : ExecutorResults::matchingReceipt($operation, $action, $operation->executor_result ?? $phase['executor'] ?? null);
            if ($action !== null && $succeeded && ! $this->provesSignedSuccess($receipt, $phase)) {
                $succeeded = false;
                $data['status'] = OperationStatus::Failed->value;
                $phase['error_code'] = $receipt === null || ExecutorResults::succeeded($receipt)
                    ? 'executor.result_unproven' : ExecutorResults::failureCode($receipt);
            }
            if ($receipt !== null && ! $succeeded && ! ExecutorResults::succeeded($receipt)) {
                $code = ExecutorResults::failureCode($receipt);
                if ($code !== 'instance.container_stopped' || ($phase['error_code'] ?? null) !== InstanceWake::NestedStopped) {
                    $phase['error_code'] = $code;
                }
            }
            $result = $succeeded ? $this->result($operation) : array_intersect_key($phase, ['error_code' => true]);
            if ($receipt !== null) {
                if ($succeeded) {
                    $result = $this->signedResult($operation, $receipt);
                } else {
                    $result['executor'] = $receipt;
                }
                if (! $endedBefore && $operation->executor_result === null) {
                    $operation->forceFill(['executor_result' => $receipt, 'executor_result_received_us' => (int) now()->format('Uu')])->save();
                }
            }
            if ($action === 'sleep') {
                $kept = [];
                if (! $succeeded && InstanceSleep::isIncompleteFreeze($receipt)) {
                    $result += ['sleep' => 'incomplete', 'power_state' => InstanceRuntimePowerState::Stopped->value];
                }
            }
            $operation = app(DirectOperation::class)->project($operation,
                [...$data, 'error_code' => $succeeded ? null : ($phase['error_code'] ?? $data['error_code'] ?? null)],
                $action === null ? [...$result, ...$kept] : [...$kept, ...$result]);
            if (! $endedBefore && ($this->reached($operation) || ($action === 'sleep' && InstanceSleep::isIncompleteFreeze($receipt)))) {
                InstanceRuntime::query()->where('instance_id', $operation->target_id)->update(['power_state' => $this->powerState()]);
            }

            return [$operation, $endedBefore];
        });
        if (! $endedBefore && in_array($operation->status->value, OperationStatus::terminalValues(), true)) {
            $this->ended($operation);
        }

        return $operation;
    }

    /** @param array<string, mixed>|null $receipt
     * @param  array<string, mixed>  $phase
     */
    protected function provesSignedSuccess(?array $receipt, array $phase): bool
    {
        return ExecutorResults::succeeded($receipt);
    }
}
