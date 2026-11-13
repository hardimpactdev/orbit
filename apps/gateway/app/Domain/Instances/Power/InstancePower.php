<?php

declare(strict_types=1);

namespace App\Domain\Instances\Power;

use App\Actions\Operations\StartOperationAction;
use App\Domain\Hibernation\RuntimeHibernation;
use App\Domain\Instances\InstanceContainer;
use App\Domain\Instances\InstancePlacement;
use App\Domain\Instances\InstanceRuntimePowerState;
use App\Domain\Instances\InstanceState;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Operations\OperationIdempotency;
use App\Domain\Operations\OperationStatus;
use App\Domain\Operations\OperationStore;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\TaskGroupStatus;
use App\Infrastructure\Swamp\SwampSshExecutionInput;
use App\Models\Instance;
use App\Models\InstanceRuntime;
use App\Models\Operation;
use App\Models\Task;
use Illuminate\Support\Facades\DB;

/**
 * Sleep and wake of a development container (docs/reference/development-containers.md#sleep-and-wake). The sleep stops the whole
 * container behind the asleep marker; the wake starts it and removes the marker. Each terminal success records `power_state`.
 */
abstract readonly class InstancePower implements DirectOperationProjection
{
    abstract protected function kind(): string;

    abstract protected function powerState(): InstanceRuntimePowerState;

    /** Refusals that only this kind makes, inside the Instance lock. */
    abstract protected function refuse(Instance $instance, InstanceRuntime $runtime): void;

    /** @return array<string, string> */
    protected function result(): array
    {
        return ['power_state' => $this->powerState()->value];
    }

    /** The asleep marker on the Instance's Node; Caddy's site of a container Instance reads it. */
    public static function markerPath(Instance $instance): string
    {
        return RuntimeHibernation::asleepPath(RuntimeHibernation::key($instance->id));
    }

    public function start(Instance $instance, ?string $caller, ?string $requestId): Operation
    {
        if (($replay = app(OperationIdempotency::class)->replay($this->kind())) !== null) {
            return $replay;
        }

        return DB::transaction(function () use ($instance, $caller, $requestId): Operation {
            $instance = Instance::query()->with(['node', 'project'])->lockForUpdate()->findOrFail($instance->id);
            $runtime = $instance->runtime()->first();
            if ($runtime === null) {
                throw new ResourceOperationException('operation.kind_unavailable', 'Sleep and wake require a container Instance.', 409);
            }
            if ($instance->placementEnvironment() !== 'development' || $runtime->incus_project === InstancePlacement::NestedProject
                || $instance->project->nested_fleet) {
                throw new ResourceOperationException('instance.sleep_unsupported', 'Only a development container that runs no nested fleet sleeps and wakes.', 409);
            }
            if (($open = $this->open($instance)) !== null) {
                return $open;
            }
            if ($instance->status !== InstanceState::Active
                || Operation::query()->where('target_type', 'process')->whereIn('target_id', $instance->processes()->select('id'))
                    ->whereNotIn('status', OperationStatus::terminalValues())->exists()) {
                throw new ResourceOperationException('instance.lifecycle_busy', 'The Instance has unfinished lifecycle work.', 409);
            }
            $this->refuse($instance, $runtime);

            return app(StartOperationAction::class)->execute($this->kind(), 'instance', $instance->id, $instance->node_id,
                ['container' => app(InstanceContainer::class)->intent($instance, $runtime), 'marker' => self::markerPath($instance)],
                $caller, $requestId, lockKey: OperationStore::lockKey('instance', $instance->id));
        });
    }

    /** An open Operation of this kind that a second caller joins; only a wake is joined. */
    protected function open(Instance $instance): ?Operation
    {
        return null;
    }

    protected static function hasActiveTask(Instance $instance): bool
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
        $phase = is_array($data['result'] ?? null) ? $data['result'] : [];
        $succeeded = ($data['status'] ?? null) === OperationStatus::Succeeded->value;

        return DB::transaction(function () use ($operation, $data, $phase, $succeeded): Operation {
            $operation = app(DirectOperation::class)->project($operation,
                [...$data, 'error_code' => $succeeded ? null : ($phase['error_code'] ?? $data['error_code'] ?? null)],
                $succeeded ? $this->result() : array_intersect_key($phase, ['error_code' => true]));
            if ($operation->status === OperationStatus::Succeeded) {
                InstanceRuntime::query()->where('instance_id', $operation->target_id)->update(['power_state' => $this->powerState()]);
            }

            return $operation;
        });
    }
}
