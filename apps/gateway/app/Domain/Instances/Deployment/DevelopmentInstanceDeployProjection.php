<?php

declare(strict_types=1);

namespace App\Domain\Instances\Deployment;

use App\Domain\Instances\ContainerProcesses;
use App\Domain\Instances\InstanceRuntimePowerState;
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

/** Development-only receipts, admitted SHA accounting and seed settlement. Production keeps its original projector. */
final readonly class DevelopmentInstanceDeployProjection implements DirectOperationProjection, DirectOperationStartRefusal, DirectOperationTimeout, DirectOperationWorkflowInput, NativeOperationProjection
{
    public function __construct(
        private DirectOperation $direct,
        private InstanceDeploymentDirectInput $intent,
        private InstanceDeploymentRecorder $history,
        private InstanceVpEnvironmentMaterial $vp,
    ) {}

    /** Both variants run natively; no step calls the Gateway back. */
    public function native(Operation $operation): bool
    {
        return true;
    }

    public function workflowInput(Operation $operation): array
    {
        $input = app(DevelopmentDeployInput::class)->workflowInput($operation);

        return ['intent' => [...ProductionDeployInput::placeholder(), ...$input['intent']], 'execution_secrets' => $input['execution_secrets']];
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
                [$data, $payload] = $variant === 'container' ? ContainerDeployOutcome::from($data) : DevelopmentDeployOutcome::from($data);
            }
            $historyId = $locked->state['deployment_id'] ?? null;
            if (! is_int($historyId)) {
                $row = $this->history->start($instance, $locked->triggered_by ?? 'operation', callerNodeId: $locked->callerNodeId());
                $historyId = $row->id;
                $locked->update(['state' => [...($locked->state ?? []), 'deployment_id' => $historyId]]);
            }
            $text = static fn (mixed $value, int $limit): ?string => $value === null ? null : mb_strcut(ValidatedData::string($value), 0, $limit, 'UTF-8');
            $facts = ['deployment_id' => $historyId, 'release' => null, 'selected_release' => null,
                'commit' => $text($status !== 'succeeded' ? InstanceDeploymentIntent::commit($locked) : ($payload['commit'] ?? null), 64), 'failed_step' => $text($payload['failed_step'] ?? null, 255)];
            $projected = $this->direct->project($locked, $data, $status === 'running' ? [] : $facts);
            if ($terminal || $status === 'running') {
                return $projected;
            }
            $admitted = ValidatedData::object($locked->input['deployment']);
            $variant = ValidatedData::string($admitted['variant']);
            if (is_array($payload['vp_environment'] ?? null)) {
                $runtime = $payload['vp_environment'];
                $path = ValidatedData::string($admitted['home']);
                $this->vp->recordReference($instance, $path, VpEnvironment::fromArray($runtime), ValidatedData::string($runtime['status']));
            }
            if ($variant === 'container') {
                // A deployment starts a stopped container; clones start at the snapshot a passed deployment took.
                $runtime = $instance->runtime()->firstOrFail();
                $runtime->update([...($payload['started'] ?? false) === true ? ['power_state' => InstanceRuntimePowerState::Running] : [],
                    ...$status === 'succeeded' ? ['seed_commit' => $facts['commit']] : []]);
                app(ContainerProcesses::class)->complete($instance, ValidatedData::object($admitted['container'])['processes'] ?? [],
                    $status === 'succeeded', ValidatedData::nullableString($data['error_code'] ?? null));
            } elseif ($variant === 'development' && $status === 'succeeded') {
                $instance->update(['seed_path' => $admitted['home'], 'seed_repository' => $admitted['home'], 'seed_commit' => $facts['commit']]);
            }
            if ($status === 'succeeded'
                && ($instance->auto_deploy_state['head'] ?? null) === $facts['commit']) {
                $instance->auto_deploy_state = [...($instance->auto_deploy_state ?? []),
                    'result' => AutomaticDeployResult::UpToDate->value, 'commit' => $facts['commit'],
                    'since' => now()->toIso8601String(), 'error_code' => null, 'message' => null];
                $instance->save();
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
        $operation->update(['result' => [...($operation->result ?? []), 'deployment_id' => $id]]);
        $this->history->recordNative(InstanceDeployment::query()->where('instance_id', $operation->target_id)->findOrFail($id), 'failed', $operation->error_code,
            ['release' => null, 'selected_release' => null, 'commit' => InstanceDeploymentIntent::commit($operation), 'failed_step' => 'admission']);
    }

    /** An expired run leaves any existing history row failed and names it in the receipt. */
    public function timedOut(Operation $operation): void
    {
        $id = $operation->state['deployment_id'] ?? null;
        if (! is_int($id)) {
            return;
        }
        $operation->update(['result' => [...($operation->result ?? []), 'deployment_id' => $id]]);
        $this->history->recordNative(InstanceDeployment::query()->where('instance_id', $operation->target_id)->findOrFail($id), 'failed', 'operation.timed_out',
            ['release' => null, 'selected_release' => null,
                'commit' => InstanceDeploymentIntent::commit($operation), 'failed_step' => null]);
    }
}
