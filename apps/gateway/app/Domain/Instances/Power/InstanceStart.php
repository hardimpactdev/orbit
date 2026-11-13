<?php

declare(strict_types=1);

namespace App\Domain\Instances\Power;

use App\Actions\Operations\StartOperationAction;
use App\Domain\Instances\InstanceContainer;
use App\Domain\Instances\InstanceRuntimePowerState;
use App\Domain\Instances\NestedFleetClubKey;
use App\Domain\Instances\NestedFleetIntent;
use App\Domain\Logs\LogRedactor;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Operations\OperationIdempotency;
use App\Domain\Operations\OperationStatus;
use App\Domain\Operations\OperationStore;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Swamp\NativeStepFailure;
use App\Infrastructure\Swamp\SwampSshExecutionInput;
use App\Models\Instance;
use App\Models\InstanceRuntime;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;

/**
 * The start of a nested-fleet clone (docs/reference/development-containers.md#a-stopped-nested-fleet-clone-starts): `instance:wake`
 * admits it for such a clone instead of a wake. The run starts the container through the start guard, delivers the nested Gateway's
 * Club key into its /run, and runs the clone's fleet start (`fleet_check`, then `fleet_resume` with its Doctor wait). A nested-fleet
 * clone never sleeps, so the start knows no asleep marker; a nested-fleet default starts only through its deployment.
 */
final readonly class InstanceStart implements DirectOperationProjection
{
    public const string Kind = 'instance.start';

    public function start(Instance $instance, ?string $caller, ?string $requestId): Operation
    {
        if (($replay = app(OperationIdempotency::class)->replay(self::Kind)) !== null) {
            return $replay;
        }

        return DB::transaction(function () use ($instance, $caller, $requestId): Operation {
            $instance = Instance::query()->with(['node', 'project'])->lockForUpdate()->findOrFail($instance->id);
            $runtime = $instance->runtime()->first();
            if ($runtime === null || $instance->placementEnvironment() !== 'development' || ! NestedFleetIntent::cloneKeepsFleet($instance, $runtime)) {
                throw new ResourceOperationException('instance.start_unsupported', 'Only a clone of a nested-fleet default starts this way.', 409);
            }
            if (($open = Operation::query()->where('kind', self::Kind)->where('target_type', 'instance')->where('target_id', $instance->id)
                ->whereNotIn('status', OperationStatus::terminalValues())->orderBy('id')->first()) !== null) {
                return $open;
            }
            InstancePower::assertIdle($instance, 'Only an active copy starts: a copy whose create or set-up did not finish is not startable this way.');
            $fleet = app(NestedFleetIntent::class)->forClone($instance, $runtime);

            return app(StartOperationAction::class)->execute(self::Kind, 'instance', $instance->id, $instance->node_id,
                ['container' => app(InstanceContainer::class)->intent($instance, $runtime), 'fleet' => $fleet],
                $caller, $requestId, lockKey: OperationStore::lockKey('instance', $instance->id));
        });
    }

    /**
     * The run's one read of the Club key. Without a value the fleet's Swamp cannot start and Doctor cannot pass, so the start fails
     * before any change instead of starting a fleet that is not ready.
     */
    public function input(Operation $operation): array
    {
        $instance = Instance::query()->with('node')->findOrFail($operation->target_id);
        $club = NestedFleetClubKey::forRun(ValidatedData::object($operation->input['container']));
        if ($club['copies'] === []) {
            throw new ResourceOperationException('instance.nested_club_key_missing', 'The Gateway holds no Club key for the nested fleet.', 409);
        }

        return ['intent' => [...$operation->input, 'container' => $club['container'],
            'ssh_execution' => app(SwampSshExecutionInput::class)->forNode($instance->node)],
            'execution_secrets' => $club['copies']];
    }

    /**
     * Records what the start did with the Club key and how long the fleet took. A fleet failure's detail becomes the failure's detail,
     * redacted again, as a clone's create does. Only a success records `power_state` `running`.
     */
    public function apply(Operation $operation, array $data): Operation
    {
        $phase = is_array($data['result'] ?? null) ? $data['result'] : [];
        $succeeded = ($data['status'] ?? null) === OperationStatus::Succeeded->value;
        $errorCode = $succeeded ? null : ($phase['error_code'] ?? $data['error_code'] ?? null);
        if (! $succeeded && is_string($phase['detail'] ?? null) && $phase['detail'] !== '') {
            $native = is_array($data['failure'] ?? null) ? $data['failure'] : [];
            $data['failure'] = ['failed_step' => is_string($native['failed_step'] ?? null) ? $native['failed_step'] : 'Start the inner fleet',
                'detail' => mb_strcut(app(LogRedactor::class)->redact($phase['detail'], []), 0, NativeStepFailure::MaximumBytes, 'UTF-8'),
                'stderr_recorded' => true];
        }
        $result = [...($succeeded ? ['power_state' => InstanceRuntimePowerState::Running->value] : ['error_code' => $errorCode]),
            'nested_fleet_club_key' => NestedFleetClubKey::outcome($phase['club_key'] ?? null, $errorCode),
            ...(is_int($phase['fleet_seconds'] ?? null) || is_float($phase['fleet_seconds'] ?? null) ? ['fleet_seconds' => $phase['fleet_seconds']] : [])];

        return DB::transaction(function () use ($operation, $data, $errorCode, $result, $succeeded): Operation {
            $operation = app(DirectOperation::class)->project($operation, [...$data, 'error_code' => $errorCode], $result);
            if ($succeeded && $operation->status === OperationStatus::Succeeded) {
                InstanceRuntime::query()->where('instance_id', $operation->target_id)->update(['power_state' => InstanceRuntimePowerState::Running]);
            }

            return $operation;
        });
    }
}
