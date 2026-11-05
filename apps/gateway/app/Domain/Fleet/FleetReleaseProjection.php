<?php

declare(strict_types=1);

namespace App\Domain\Fleet;

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/** Records native per-Node release results on the Operation. Swamp owns effects, order and retries. */
final readonly class FleetReleaseProjection implements DirectOperationProjection
{
    public function input(Operation $operation): array
    {
        return array_intersect_key($operation->input, array_flip(['nodes', 'commit']));
    }

    public function apply(Operation $operation, array $data): Operation
    {
        $result = array_intersect_key(ValidatedData::object($data['result'] ?? []), array_flip(['nodes', 'error_code']));
        $nodes = $result['nodes'] ?? [];
        if (! is_array($nodes)) {
            throw new ResourceOperationException('operation.result_invalid', 'The fleet result requires a Node list.', 422);
        }
        $nodes = array_map(ValidatedData::object(...), $nodes);

        // A run that ended before it visited any Node, such as a failed prepare, reports no Node list. It still settles.
        $unvisited = in_array($data['status'], ['failed', 'cancelled'], true) && $nodes === [];
        if (OperationStatus::from(ValidatedData::string($data['status']))->terminal() && ! $unvisited) {
            $valid = Validator::make($result, [
                'nodes' => ['required', 'array', 'max:1000'],
                'nodes.*.node_id' => ['required', 'integer', 'distinct'],
                'nodes.*.outcome' => ['required', 'in:converged,unchanged,deferred,skipped,failed,cancelled,pending'],
                'nodes.*.error_code' => ['present', 'nullable', 'string', 'max:100'],
                'nodes.*.cli_version' => ['present', 'nullable', 'string', 'max:128'],
                'nodes.*.agent_version' => ['present', 'nullable', 'string', 'max:128'],
                'nodes.*.step' => ['required', 'in:admission,cli,agent,verify'],
                'nodes.*.ok' => ['required', 'boolean'],
            ]);
            $admitted = $operation->input['nodes'] ?? [];
            if (! is_array($admitted) || $valid->fails() || array_column($nodes, 'node_id') !== array_column($admitted, 'node_id')
                || ($data['status'] === 'succeeded' && array_any($nodes, static fn (array $node): bool => ! $node['ok'] || in_array($node['outcome'], ['failed', 'cancelled', 'pending'], true)))) {
                throw new ResourceOperationException('operation.result_invalid', 'The result must name each admitted Node once in rollout order.', 422);
            }
            $result['nodes'] = array_map(static fn (array $node): array => array_intersect_key($node, array_flip(['node_id', 'outcome', 'error_code', 'cli_version', 'agent_version', 'step', 'ok'])), $nodes);
        }

        return DB::transaction(function () use ($operation, $data, $result, $nodes): Operation {
            $operation->setRawAttributes(Operation::query()->whereKey($operation->id)->lockForUpdate()->firstOrFail()->getAttributes(), true);
            $terminal = $operation->status->terminal();
            $projected = app(DirectOperation::class)->project($operation, $data, $result);
            if ($terminal || ! $projected->status->terminal()) {
                return $projected;
            }
            // The first failed Node stopped the rollout run; later Nodes stay `pending` until a pass converges it.
            $failed = $operation->kind === 'fleet.rollout' && $projected->status === OperationStatus::Failed
                ? array_find($nodes, static fn (array $node): bool => $node['outcome'] === 'failed') : null;
            if ($failed !== null) {
                $node = ['node_id' => ValidatedData::integer($failed['node_id']), 'step' => ValidatedData::string($failed['step']),
                    'error_code' => is_string($failed['error_code'] ?? null) ? $failed['error_code'] : null];
                DB::afterCommit(static fn () => app(FleetRolloutAlerts::class)->failed($projected, $node));
            }

            return $projected;
        });
    }
}
