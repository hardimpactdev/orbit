<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Routes\RouteExecutionProof;
use App\Domain\Shared\LifecycleStatus;
use App\Models\Node;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;

/** Terminal database projection only; no live input endpoint or host verification. */
final readonly class NodeRemovalProjection implements DirectOperationProjection
{
    public function input(Operation $operation): array
    {
        return ['intent' => $operation->input['intent'], 'execution_secrets' => []];
    }

    public function apply(Operation $operation, array $data): Operation
    {
        return DB::transaction(function () use ($operation, $data): Operation {
            $operation->setRawAttributes(Operation::query()->whereKey($operation->id)->lockForUpdate()->firstOrFail()->getAttributes(), true);
            $terminal = $operation->status->terminal();
            $result = ValidatedData::object($data['result'] ?? []);
            $projected = app(DirectOperation::class)->project($operation, $data, $result);
            if ($terminal) {
                return $projected;
            }
            $snapshot = ValidatedData::object($operation->input['_node_removal']);
            if ($data['status'] === 'succeeded' && ($result['removed'] ?? false) === true) {
                app(NodeRemovalRecords::class)->remove($snapshot, $result);
            } elseif (in_array($data['status'], ['failed', 'cancelled', 'interrupted'], true)) {
                $node = Node::query()->find($operation->target_id);
                $node?->update(['status' => LifecycleStatus::Failed, 'failed_step' => 'remove:operation', 'error_code' => $data['error_code'] ?? 'node.remove_failed']);
                $node?->roles()->whereIn('id', array_column(RouteExecutionProof::objects($snapshot['roles']), 'id'))->update([
                    'status' => LifecycleStatus::Failed, 'failed_step' => 'remove:operation', 'error_code' => $data['error_code'] ?? 'node.remove_failed']);
            }

            return $projected;
        });
    }
}
