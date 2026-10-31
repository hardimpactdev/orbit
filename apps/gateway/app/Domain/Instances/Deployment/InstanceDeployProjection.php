<?php

declare(strict_types=1);

namespace App\Domain\Instances\Deployment;

use App\Domain\Instances\VpEnvironment\InstanceVpEnvironmentMaterial;
use App\Domain\Nodes\Storage\StoragePath;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Operations\OperationStore;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tools\VpEnvironment;
use App\Models\Instance;
use App\Models\InstanceDeployment;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/** Records the native parent outcome and deployment history. Contains no Node action or child execution. */
final readonly class InstanceDeployProjection implements DirectOperationProjection
{
    public function __construct(
        private DirectOperation $direct,
        private InstanceDeploymentDirectInput $intent,
        private InstanceDeploymentRecorder $history,
        private OperationStore $operations,
        private InstanceVpEnvironmentMaterial $vp,
    ) {}

    public function input(Operation $operation): array
    {
        if ($operation->status->terminal()) {
            $admitted = ValidatedData::object($operation->input['deployment']);

            return ['instance_id' => $operation->target_id,
                'project_id' => ValidatedData::integer($admitted['project_id']),
                'deployment' => $admitted,
                'commit' => InstanceDeploymentIntent::commit($operation),
                'release_name' => InstanceDeploymentIntent::releaseName($operation),
                'timeout_seconds' => InstanceDeploymentBudget::seconds($operation)];
        }

        return $this->intent->input($operation);
    }

    public function apply(Operation $operation, array $data): Operation
    {
        $status = ValidatedData::string($data['status'] ?? null);
        $payload = ValidatedData::object($data['result'] ?? []);
        $code = ValidatedData::nullableString($data['error_code'] ?? null);
        $runtime = $status === 'running' && $payload !== [] ? $this->runtimeReference($operation, $payload) : null;
        if ($status === 'running' && $code !== null) {
            throw new ResourceOperationException('operation.result_invalid', 'A running deployment carries no terminal outcome.', 422);
        }
        $result = $status === 'running' ? null : InstanceNativeDeploymentResult::from($operation, $status, $code, $payload);

        return DB::transaction(function () use ($operation, $data, $status, $result, $runtime): Operation {
            $locked = Operation::query()->lockForUpdate()->findOrFail($operation->id);
            $terminal = $locked->status->terminal();
            if (! $terminal && ($status === 'running' || $status === 'succeeded')) {
                $this->intent->input($locked);
            }
            $instance = Instance::query()->with(['node', 'project'])->findOrFail($locked->target_id);
            $historyId = $locked->state['deployment_id'] ?? null;
            if (! $terminal && $status === 'running' && $historyId === null) {
                $row = $this->history->start($instance, $locked->triggered_by ?? 'operation', callerNodeId: $locked->callerNodeId());
                $historyId = $row->id;
                $this->operations->change($locked, ['state' => [...($locked->state ?? []), 'deployment_id' => $historyId]]);
            }
            if ($result !== null && ! is_int($historyId)) {
                throw new ResourceOperationException('operation.result_invalid', 'A native deployment must start its history before projecting its outcome.', 422);
            }
            $public = $result === null ? [] : $result->publicResult($historyId);
            $projected = $this->direct->project($locked, $data, $public, 'deployment');
            if (! $terminal && $runtime !== null) {
                $this->vp->recordReference($instance, InstanceDeploymentIntent::admitted($locked)['home'],
                    $runtime['environment'], $runtime['status']);
            }
            if ($terminal || $result === null) {
                return $projected;
            }
            $row = InstanceDeployment::query()->where('instance_id', $locked->target_id)->findOrFail($historyId);
            $deployment = $result->deployment();
            $admitted = InstanceDeploymentIntent::admitted($locked);
            if ($deployment->succeeded && $admitted['variant'] === 'development') {
                $home = $admitted['home'];
                $instance->update(['seed_path' => $home, 'seed_repository' => $home, 'seed_commit' => $deployment->commit]);
            } elseif ($deployment->selectedRelease !== null) {
                $instance->update(['checkout_path' => $deployment->selectedRelease->path]);
            }
            $this->history->finish($row, $deployment, $row->events ?? []);

            return $projected;
        });
    }

    /** @param array<string,mixed> $payload
     * @return array{environment:VpEnvironment,status:string}
     */
    private function runtimeReference(Operation $operation, array $payload): array
    {
        $admitted = InstanceDeploymentIntent::admitted($operation);
        $raw = $payload['vp_environment'] ?? null;
        $keys = ['store', 'vp_version', 'node_version', 'package_manager', 'package_manager_version', 'package_manager_hash', 'status'];
        if (array_keys($payload) !== ['vp_environment'] || ! is_array($raw) || count($raw) !== count($keys)
            || array_diff($keys, array_keys($raw)) !== [] || $admitted['variant'] !== 'development' || ! $admitted['needs_vp']
            || ! in_array($raw['status'], ['checking', 'active', 'failed'], true)
            || ! is_string($raw['store']) || strlen($raw['store']) > 4096 || StoragePath::tryParse($raw['store']) === null
            || ! in_array($raw['package_manager'], ['npm', 'pnpm'], true)) {
            throw new ResourceOperationException('operation.result_invalid', 'The runtime reference is not a public development deployment result.', 422);
        }
        try {
            $environment = VpEnvironment::fromArray($raw);
        } catch (InvalidArgumentException) {
            throw new ResourceOperationException('operation.result_invalid', 'The runtime reference contains invalid public pins.', 422);
        }

        return ['environment' => $environment, 'status' => $raw['status']];
    }
}
