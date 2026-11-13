<?php

declare(strict_types=1);

namespace App\Domain\Instances\Deployment;

use App\Domain\Instances\ContainerProcesses;
use App\Domain\Instances\InstanceRuntimePowerState;
use App\Domain\Instances\NestedFleetClubKey;
use App\Domain\Instances\VpEnvironment\InstanceVpEnvironmentMaterial;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Operations\DirectOperationStartRefusal;
use App\Domain\Operations\DirectOperationTimeout;
use App\Domain\Operations\DirectOperationWorkflowInput;
use App\Domain\Operations\NativeOperationProjection;
use App\Domain\Tools\VpEnvironment;
use App\Models\Instance;
use App\Models\InstanceDeployment;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;

/** Records bounded native facts. All sequencing, runtime checks and effects belong to Swamp. */
final readonly class InstanceDeployProjection implements DirectOperationProjection, DirectOperationStartRefusal, DirectOperationTimeout, DirectOperationWorkflowInput, NativeOperationProjection
{
    public function __construct(
        private DirectOperation $direct,
        private InstanceDeploymentDirectInput $intent,
        private InstanceDeploymentRecorder $history,
        private InstanceRuntimeDefinitions $members,
        private InstanceVpEnvironmentMaterial $vp,
    ) {}

    /** Both variants run natively; no step calls the Gateway back. */
    public function native(Operation $operation): bool
    {
        return true;
    }

    public function workflowInput(Operation $operation): array
    {
        if ($this->development($operation)) {
            $input = app(DevelopmentDeployInput::class)->workflowInput($operation);

            return ['intent' => [...ProductionDeployInput::placeholder(), ...$input['intent']], 'execution_secrets' => $input['execution_secrets']];
        }

        $input = app(ProductionDeployInput::class)->workflowInput($operation);

        // The container steps read `intent.container` even when they are skipped.
        return [...$input, 'intent' => ['container' => null, ...$input['intent']]];
    }

    public function input(Operation $operation): array
    {
        return $this->intent->admitted($operation);
    }

    public function apply(Operation $operation, array $data): Operation
    {
        return DB::transaction(function () use ($operation, $data): Operation {
            $locked = Operation::query()->lockForUpdate()->findOrFail($operation->id);
            $instance = Instance::query()->with(['node', 'project'])->findOrFail($locked->target_id);
            $terminal = $locked->status->terminal();
            $status = ValidatedData::string($data['status']);
            $payload = ValidatedData::object($data['result'] ?? []);
            if ($status !== 'running') {
                $variant = ValidatedData::string(ValidatedData::object($locked->input['deployment'])['variant']);
                [$data, $payload] = match (true) {
                    $variant === 'container' => ContainerDeployOutcome::from($data),
                    $this->development($locked) => DevelopmentDeployOutcome::from($data),
                    default => ProductionDeployOutcome::from($data, $locked),
                };
            }
            $historyId = $locked->state['deployment_id'] ?? null;
            if (! is_int($historyId)) {
                $row = $this->history->start($instance, $locked->triggered_by ?? 'operation', callerNodeId: $locked->callerNodeId());
                $historyId = $row->id;
                $locked->update(['state' => [...($locked->state ?? []), 'deployment_id' => $historyId]]);
            }
            $text = static fn (mixed $value, int $limit): ?string => $value === null ? null : mb_strcut(ValidatedData::string($value), 0, $limit, 'UTF-8');
            $facts = ['deployment_id' => $historyId, 'release' => $text(ValidatedData::object($payload['release'] ?? [])['name'] ?? null, 128),
                'selected_release' => $text(ValidatedData::object($payload['selected_release'] ?? [])['name'] ?? null, 128),
                'commit' => $text($payload['commit'] ?? null, 64), 'failed_step' => $text($payload['failed_step'] ?? null, 255)];
            if (isset($payload['warnings'])) {
                $facts['warnings'] = ValidatedData::integer($payload['warnings']);
            }
            if (isset($payload['fleet_drift'])) {
                $facts['fleet_drift'] = $payload['fleet_drift'];
            }
            $club = ValidatedData::object($locked->input['deployment'])['container'] ?? null;
            if ($status !== 'running' && is_array($club) && isset($club['club_key'])) {
                // What this nested-fleet start did with its Club key, from the run's own word.
                $facts['nested_fleet_club_key'] = NestedFleetClubKey::outcome($payload['club_key'] ?? null, $data['error_code'] ?? null);
            }
            $projected = $this->direct->project($locked, $data, $status === 'running' ? [] : $facts);
            if ($terminal || $status === 'running') {
                return $projected;
            }
            $admitted = ValidatedData::object($locked->input['deployment']);
            $variant = ValidatedData::string($admitted['variant']);
            if (is_array($payload['vp_environment'] ?? null)) {
                $runtime = $payload['vp_environment'];
                $path = ValidatedData::string($admitted['home']);
                if (! InstanceDeploymentIntent::development($variant) && $facts['release'] !== null) {
                    $path .= '/releases/'.$facts['release'];
                }
                $this->vp->recordReference($instance, $path, VpEnvironment::fromArray($runtime), ValidatedData::string($runtime['status']));
            }
            if ($variant === 'container') {
                // A deployment starts a stopped container; clones start at the snapshot a passed deployment took.
                $runtime = $instance->runtime()->firstOrFail();
                $runtime->update([...($payload['started'] ?? false) === true ? ['power_state' => InstanceRuntimePowerState::Running] : [],
                    ...$status === 'succeeded' || ($payload['seed_kept'] ?? false) === true ? ['seed_commit' => $facts['commit']] : []]);
                app(ContainerProcesses::class)->complete($instance, ValidatedData::object($admitted['container'])['processes'] ?? [],
                    $status === 'succeeded', ValidatedData::nullableString($data['error_code'] ?? null));
            } elseif ($variant === 'development' && $status === 'succeeded') {
                $instance->update(['seed_path' => $admitted['home'], 'seed_repository' => $admitted['home'], 'seed_commit' => $facts['commit']]);
            } elseif (! InstanceDeploymentIntent::development($variant) && $facts['selected_release'] !== null && ($payload['selection_confirmed'] ?? false) === true) {
                $instance->update(['checkout_path' => ValidatedData::string($admitted['home']).'/releases/'.$facts['selected_release']]);
            }
            if (is_array($payload['php_identity'] ?? null)) {
                $instance->forceFill(ValidatedData::object($payload['php_identity']))->saveOrFail();
            }
            if (is_array($payload['environment'] ?? null)) {
                $keys = ValidatedData::stringList($payload['environment']['keys'] ?? []);
                sort($keys, SORT_STRING);
                $instance->forceFill(['environment_owned_keys' => array_values(array_unique($keys)),
                    'environment_runtime_pending_at' => null])->saveOrFail();
            }
            if (is_array($payload['runtime_definitions'] ?? null)) {
                $this->members->project($locked, ValidatedData::object($payload['runtime_definitions']));
            }
            $row = InstanceDeployment::query()->where('instance_id', $instance->id)->findOrFail($historyId);
            $this->history->recordNative($row, $status, ValidatedData::nullableString($data['error_code'] ?? null), $facts);

            return $projected;
        });
    }

    /** A definite refusal has no native effects and must not leave an admission history row running. */
    public function refused(Operation $operation): void
    {
        $id = $operation->state['deployment_id'] ?? null;
        if (! is_int($id)) {
            return;
        }
        $this->history->recordNative(InstanceDeployment::query()->where('instance_id', $operation->target_id)->findOrFail($id), 'failed', $operation->error_code,
            ['release' => null, 'selected_release' => null, 'commit' => null, 'failed_step' => 'admission']);
    }

    /** A production deployment recorded its running row at admission; the expired run leaves it failed. */
    public function timedOut(Operation $operation): void
    {
        $id = $operation->state['deployment_id'] ?? null;
        if (! is_int($id)) {
            return;
        }
        $this->history->recordNative(InstanceDeployment::query()->where('instance_id', $operation->target_id)->findOrFail($id), 'failed', 'operation.timed_out',
            ['release' => $this->development($operation) ? null : InstanceDeploymentIntent::releaseName($operation), 'selected_release' => null,
                'commit' => InstanceDeploymentIntent::commit($operation), 'failed_step' => null]);
    }

    private function development(Operation $operation): bool
    {
        return InstanceDeploymentIntent::development(ValidatedData::string(ValidatedData::object($operation->input['deployment'])['variant']));
    }
}
