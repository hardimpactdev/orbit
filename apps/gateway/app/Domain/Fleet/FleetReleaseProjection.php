<?php

declare(strict_types=1);

namespace App\Domain\Fleet;

use App\Domain\Nodes\NodeUpdateBroadcaster;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Operations\OperationStatus;
use App\Models\FleetRollout;
use App\Models\FleetRolloutNode;
use App\Models\Node;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;

/** Records native per-Node release results. Swamp owns effects, order and retries. */
final readonly class FleetReleaseProjection implements DirectOperationProjection
{
    public function input(Operation $operation): array
    {
        return array_intersect_key($operation->input, array_flip(['nodes', 'commit', 'rollout_id', 'remaining']));
    }

    public function apply(Operation $operation, array $data): Operation
    {
        $result = array_intersect_key(ValidatedData::object($data['result'] ?? []), array_flip(['nodes', 'error_code', 'rollout_status', 'halted_node_id']));

        return DB::transaction(function () use ($operation, $data, $result): Operation {
            $operation->setRawAttributes(Operation::query()->whereKey($operation->id)->lockForUpdate()->firstOrFail()->getAttributes(), true);
            $terminal = $operation->status->terminal();
            $projected = app(DirectOperation::class)->project($operation, $data, $result);
            if ($terminal || ! $projected->status->terminal()) {
                return $projected;
            }
            foreach ($result['nodes'] ?? [] as $entry) {
                $entry = ValidatedData::object($entry);
                $node = Node::query()->find(ValidatedData::integer($entry['node_id']));
                if ($node === null) {
                    continue;
                }
                if (($entry['error_code'] ?? null) === 'cli.foreign_binary') {
                    app(NodeCliState::class)->markForeign($node);
                } elseif (in_array($entry['outcome'] ?? null, ['converged', 'unchanged'], true)) {
                    app(NodeCliState::class)->clear($node);
                }
                if (is_int($operation->input['rollout_id'] ?? null)) {
                    FleetRolloutNode::query()->where('fleet_rollout_id', $operation->input['rollout_id'])->where('node_id', $node->id)->update([
                        'outcome' => $entry['outcome'], 'error_code' => $entry['error_code'] ?? null,
                        'cli_version' => $entry['cli_version'] ?? null, 'agent_version' => $entry['agent_version'] ?? null,
                        'step' => $entry['step'] ?? null, 'finished_at' => now(),
                        'evidence' => json_encode(['operation_id' => $operation->id, 'run_id' => $data['run_id']], JSON_THROW_ON_ERROR),
                    ]);
                }
                DB::afterCommit(fn () => app(NodeUpdateBroadcaster::class)->node($node->id));
            }
            if (is_int($operation->input['rollout_id'] ?? null)) {
                FleetRollout::query()->whereKey($operation->input['rollout_id'])->update([
                    'status' => $result['rollout_status'] ?? ($projected->status === OperationStatus::Failed ? FleetRolloutStatus::Halted->value : FleetRolloutStatus::Completed->value),
                    'halted_node_id' => $result['halted_node_id'] ?? null,
                    'error_code' => $projected->error_code, 'finished_at' => ($result['rollout_status'] ?? null) === 'running' ? null : now(),
                ]);
            }

            return $projected;
        });
    }
}
