<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Operations\DirectOperationPublicView;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/** Minimal native outcome projection. Swamp owns the cleanup and restore decision. */
final readonly class NodeRemovalProjection implements DirectOperationProjection, DirectOperationPublicView
{
    public function assertPublicParticipant(Operation $operation, int $callerNodeId): void
    {
        $snapshot = ValidatedData::object($operation->input['_node_removal'] ?? []);
        $participants = $snapshot['participants'] ?? [];
        $ids = array_map(static fn (mixed $row): mixed => is_array($row) ? ($row['node_id'] ?? null) : null, is_array($participants) ? $participants : []);
        $admitted = $operation->input['participant_node_ids'] ?? [];
        $reachableGuest = ($snapshot['node_id'] ?? null) === $callerNodeId
            && ($snapshot['machine_deleted'] ?? true) === false;
        $execution = ValidatedData::object($operation->input['_removal'] ?? []);
        $metricsPlan = $execution['metrics_fleet'] ?? null;
        $fleet = is_array($metricsPlan) && is_array($metricsPlan['fleet'] ?? null) ? $metricsPlan['fleet'] : [];
        $metricsMember = is_array($metricsPlan) && in_array($callerNodeId, array_column($fleet, 'node_id'), true);
        $signer = $callerNodeId === 209 && ($execution['restore_certificates'] ?? []) !== [];
        if ($operation->kind !== 'node.remove'
            || (! in_array($callerNodeId, $ids, true) && ! $reachableGuest && ! $signer && ! $metricsMember)
            || ! is_array($admitted) || ! in_array($callerNodeId, $admitted, true)) {
            throw new ResourceOperationException('operation.execution_forbidden', 'The Node is outside the admitted removal inventory.', 403);
        }
    }

    public function publicInput(Operation $operation, int $callerNodeId): array
    {
        $this->assertPublicParticipant($operation, $callerNodeId);
        $root = $this->metricsInput($operation);
        $snapshot = ValidatedData::object($operation->input['_node_removal'] ?? []);
        $members = is_array($snapshot['participants'] ?? null) ? $snapshot['participants'] : [];
        if (! in_array($callerNodeId, array_column($members, 'node_id'), true)
            && $callerNodeId !== ($snapshot['node_id'] ?? null)) {
            return $root;
        }
        $execution = ValidatedData::object($operation->input['_removal'] ?? []);

        return [...$root, ...app(NodeRemovalPublicInput::class)->participant(
            [...ValidatedData::object($operation->input['_node_removal'] ?? []),
                'route_removal' => $execution['routes'] ?? null], Node::query()->findOrFail($callerNodeId),
            ($operation->result['restoring'] ?? false) === true, $operation)];
    }

    public function input(Operation $operation): array
    {
        $input = ValidatedData::object($operation->input['_removal'] ?? []);
        $snapshot = ValidatedData::object($operation->input['_node_removal'] ?? []);
        $participants = $snapshot['participants'] ?? [];
        $rootIsParticipant = ! $operation->status->terminal() && is_array($participants) && array_any($participants,
            static fn (mixed $row): bool => is_array($row) && ($row['node_id'] ?? null) === $operation->node_id);

        // The shared endpoint returns the root projection for its own peer. Capture
        // its fresh participant view here, under the same native publication lock.
        $input = [...$input, ...$this->metricsInput($operation)];

        return [...$input, 'root_participant' => $rootIsParticipant
            ? $this->publicInput($operation, ValidatedData::integer($operation->node_id)) : null];
    }

    /** @return array<string,mixed> */
    private function metricsInput(Operation $operation): array
    {
        $input = ValidatedData::object($operation->input['_removal'] ?? []);
        $snapshot = ValidatedData::object($operation->input['_node_removal'] ?? []);
        $metrics = is_array($snapshot['metrics'] ?? null) ? $snapshot['metrics'] : [];

        return ['proxy_node_ids' => $input['proxy_node_ids'] ?? [], 'metrics_fleet' => ($operation->result['restoring'] ?? false) === true ? ($input['restore_metrics_fleet'] ?? null) : ($input['metrics_fleet'] ?? null),
            'metrics_excluded_node_ids' => ($snapshot['offline'] ?? false) ? [$snapshot['node_id']] : [],
            'metrics_runtime_required' => ($metrics['node_id'] ?? null) !== ($snapshot['node_id'] ?? null)];
    }

    public function apply(Operation $operation, array $data): Operation
    {
        return DB::transaction(function () use ($operation, $data): Operation {
            $operation->setRawAttributes(Operation::query()->whereKey($operation->id)->lockForUpdate()->firstOrFail()->getAttributes(), true);

            return $this->projectLocked($operation, $data);
        });
    }

    /** @param array<string,mixed> $data */
    private function projectLocked(Operation $operation, array $data): Operation
    {
        $snapshot = ValidatedData::object($operation->input['_node_removal'] ?? []);
        $execution = ValidatedData::object($operation->input['_removal'] ?? []);
        $result = ValidatedData::object($data['result'] ?? []);
        $status = $data['status'];
        $result = Arr::only($result, ['schema_version', 'node_id', 'removed', 'restored', 'error_code', 'removing', 'restoring', 'name', 'offline', 'roles_shed', 'retained_on_node', 'follow_up']);
        $priorResult = $operation->result ?? [];
        $wasTerminal = $operation->status->terminal();
        $projected = app(DirectOperation::class)->project($operation, $data, $result);
        if ($wasTerminal) {
            return $projected;
        }
        if ($status === 'running' && $result === ['removing' => true]) {
            app(NodeRemovalRecords::class)->begin($snapshot);
        } elseif ($status === 'running' && $result === ['restoring' => true]) {
            app(NodeRemovalRecords::class)->restore($snapshot, $operation->input['route_prior_states'] ?? []);
            if (($execution['hub'] ?? null) !== null) {
                NodeWireGuardInput::beginRestoration($operation);
            }
        } elseif ($status === 'succeeded') {
            app(NodeRemovalRecords::class)->remove($snapshot, $result,
                ValidatedData::object($execution['routes'] ?? []));
        } elseif (in_array($status, ['failed', 'cancelled'], true) && $result !== []) {
            if (($result['restored'] ?? false) === true) {
                app(NodeRemovalRecords::class)->restore($snapshot, $operation->input['route_prior_states'] ?? []);
            }
        } elseif (in_array($status, ['failed', 'cancelled'], true) && ! ($priorResult['removing'] ?? false) && ! ($priorResult['restoring'] ?? false)) {
            app(NodeRemovalRecords::class)->restore($snapshot, $operation->input['route_prior_states'] ?? []);
        }

        return $projected;
    }
}
