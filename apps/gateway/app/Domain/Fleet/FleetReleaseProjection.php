<?php

declare(strict_types=1);

namespace App\Domain\Fleet;

use App\Domain\Nodes\NodeUpdateBroadcaster;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\FleetRollout;
use App\Models\FleetRolloutNode;
use App\Models\Node;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

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

        if (OperationStatus::from($data['status'])->terminal() && ($data['status'] !== 'cancelled' || $result !== [])) {
            $valid = Validator::make($result, [
                'nodes' => ['required', 'array', 'max:1000'],
                'nodes.*.node_id' => ['required', 'integer', 'distinct'],
                'nodes.*.outcome' => ['required', 'in:converged,unchanged,deferred,skipped,failed,cancelled,pending'],
                'nodes.*.error_code' => ['present', 'nullable', 'string', 'max:100'],
                'nodes.*.cli_version' => ['present', 'nullable', 'string', 'max:128'],
                'nodes.*.agent_version' => ['present', 'nullable', 'string', 'max:128'],
                'nodes.*.step' => ['required', 'in:admission,cli,agent,verify'],
                'nodes.*.ok' => ['required', 'boolean'],
                'rollout_status' => ['sometimes', 'in:running,halted,completed'],
                'halted_node_id' => ['sometimes', 'nullable', 'integer'],
            ]);
            if ($valid->fails() || array_column($result['nodes'] ?? [], 'node_id') !== array_column($operation->input['nodes'], 'node_id')
                || ($data['status'] === 'succeeded' && array_any($result['nodes'], static fn (array $node): bool => ! $node['ok'] || in_array($node['outcome'], ['failed', 'cancelled', 'pending'], true)))) {
                throw new ResourceOperationException('operation.result_invalid', 'The result must name each admitted Node once in rollout order.', 422);
            }
            $result['nodes'] = array_map(static fn (array $node): array => array_intersect_key($node, array_flip(['node_id', 'outcome', 'error_code', 'cli_version', 'agent_version', 'step', 'ok'])), $result['nodes']);
            if ($operation->kind === 'fleet.rollout' && (! isset($result['rollout_status'])
                || (($result['halted_node_id'] ?? null) !== null && ! in_array($result['halted_node_id'], array_column($result['nodes'], 'node_id'), true)))) {
                throw new ResourceOperationException('operation.result_invalid', 'The rollout result needs its admitted status and halt Node.', 422);
            }
        }

        return DB::transaction(function () use ($operation, $data, $result): Operation {
            $operation->setRawAttributes(Operation::query()->whereKey($operation->id)->lockForUpdate()->firstOrFail()->getAttributes(), true);
            $terminal = $operation->status->terminal();
            $projected = app(DirectOperation::class)->project($operation, $data, $result);
            if ($terminal || ! $projected->status->terminal()) {
                return $projected;
            }
            foreach ($result['nodes'] ?? [] as $entry) {
                $entry = ValidatedData::object($entry);
                if ($entry['outcome'] === 'pending') {
                    continue;
                }
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
                        'outcome' => $entry['outcome'] === 'cancelled' ? FleetNodeOutcome::Deferred->value : $entry['outcome'], 'error_code' => $entry['error_code'] ?? null,
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
                if (($result['rollout_status'] ?? null) === 'halted' && is_int($result['halted_node_id'] ?? null)) {
                    $rolloutId = $operation->input['rollout_id'];
                    $haltedNodeId = $result['halted_node_id'];
                    DB::afterCommit(static function () use ($rolloutId, $haltedNodeId): void {
                        $rollout = FleetRollout::query()->findOrFail($rolloutId);
                        $row = $rollout->nodes()->where('node_id', $haltedNodeId)->firstOrFail();
                        app(FleetRolloutAlerts::class)->halted($rollout, $row);
                    });
                }
            }

            return $projected;
        });
    }
}
