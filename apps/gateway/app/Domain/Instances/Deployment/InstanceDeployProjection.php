<?php

declare(strict_types=1);

namespace App\Domain\Instances\Deployment;

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Operations\OperationStore;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Instance;
use App\Models\InstanceDeployment;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;

/** Records the native parent outcome and deployment history. Contains no Node action or child execution. */
final readonly class InstanceDeployProjection implements DirectOperationProjection
{
    public function __construct(
        private DirectOperation $direct,
        private InstanceDeploymentDirectInput $intent,
        private InstanceDeploymentRecorder $history,
        private OperationStore $operations,
    ) {}

    public function input(Operation $operation): array
    {
        if ($operation->status->terminal()) {
            $admitted = ValidatedData::object($operation->input['deployment']);

            return ['instance_id' => $operation->target_id,
                'project_id' => ValidatedData::integer($admitted['project_id']),
                'deployment' => $admitted,
                'commit' => InstanceDeploymentIntent::commit($operation),
                'release_name' => InstanceDeploymentIntent::releaseName($operation)];
        }

        return $this->intent->input($operation);
    }

    public function apply(Operation $operation, array $data): Operation
    {
        $status = ValidatedData::string($data['status'] ?? null);
        $payload = ValidatedData::object($data['result'] ?? []);
        $code = ValidatedData::nullableString($data['error_code'] ?? null);
        if ($status === 'running' && ($payload !== [] || $code !== null)) {
            throw new ResourceOperationException('operation.result_invalid', 'A running deployment carries no terminal outcome.', 422);
        }
        $result = $status === 'running' ? null : InstanceNativeDeploymentResult::from($operation, $status, $code, $payload);

        return DB::transaction(function () use ($operation, $data, $status, $result): Operation {
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
}
