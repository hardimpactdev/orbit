<?php

declare(strict_types=1);

namespace App\Domain\Fleet;

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Operations\OperationStatus;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;

/** Records native per-Node release results on the Operation. Swamp owns effects, order and retries. */
final readonly class FleetReleaseProjection implements DirectOperationProjection
{
    public function input(Operation $operation): array
    {
        return ['intent' => ['nodes' => $operation->input['nodes']], 'execution_secrets' => []];
    }

    public function apply(Operation $operation, array $data): Operation
    {
        $result = array_intersect_key(ValidatedData::object($data['result'] ?? []), array_flip(['nodes', 'error_code']));
        $nodes = array_map(ValidatedData::object(...), (array) ($result['nodes'] ?? []));

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
