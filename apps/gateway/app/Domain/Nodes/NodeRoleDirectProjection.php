<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use App\Data\Nodes\NodeData;
use App\Domain\Broadcasting\RecordEventBroadcaster;
use App\Domain\Broadcasting\RecordEventType;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Operations\DirectOperationPublicView;
use App\Domain\Operations\NativeOperationProjection;
use App\Domain\Operations\OperationStatus;
use App\Domain\Routes\RouteExecutionProof;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Swamp\NativeRunResult;
use App\Models\Node;
use App\Models\NodeRole;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;

/** Role assignment and footprint state only. The original native root owns every effect. */
final readonly class NodeRoleDirectProjection implements DirectOperationProjection, DirectOperationPublicView, NativeOperationProjection
{
    public function native(Operation $operation): bool
    {
        return isset($operation->input['native_role']);
    }

    public function input(Operation $operation): array
    {
        if (isset($operation->input['native_role'])) {
            return ['intent' => $operation->input['native_role'], 'execution_secrets' => $operation->input['execution_secrets'] ?? []];
        }
        $snapshot = $this->snapshot($operation);
        if (isset($operation->state['role_footprint'])) {
            $snapshot['footprint'] = $operation->state['role_footprint'];
        }
        if (isset($snapshot['publication']) && in_array($operation->node_id,
            NodeRolePublicationInput::participants(ValidatedData::object($snapshot['publication'])), true)) {
            return [...$snapshot, ...$this->publicInput($operation, $operation->node_id)];
        }

        return $snapshot;
    }

    public function assertPublicParticipant(Operation $operation, int $callerNodeId): void
    {
        if (isset($operation->input['native_role'])) {
            throw new ResourceOperationException('operation.execution_forbidden', 'Native role convergence has no execution callback.', 403);
        }
        app(NodeRolePublicationInput::class)->assertPublicParticipant($operation, $callerNodeId);
    }

    public function publicInput(Operation $operation, int $callerNodeId): array
    {
        $this->assertPublicParticipant($operation, $callerNodeId);

        return app(NodeRolePublicationInput::class)->publicInput($operation, $callerNodeId);
    }

    public function apply(Operation $operation, array $data): Operation
    {
        if (in_array($operation->kind, ['node.role_add', 'node.role_converge'], true) && isset($operation->input['native_role'])) {
            if (! in_array($data['status'] ?? null, ['succeeded', 'failed', 'cancelled', 'interrupted'], true)) {
                throw new ResourceOperationException('operation.result_invalid', 'Native role addition projects terminal results only.', 422);
            }

            return $this->applyNativeRole($operation, $data);
        }
        if (isset($operation->input['native_role']) && ! in_array($data['status'] ?? null, ['succeeded', 'failed', 'cancelled', 'interrupted'], true)) {
            throw new ResourceOperationException('operation.result_invalid', 'Native role convergence projects terminal results only.', 422);
        }
        $status = OperationStatus::from(ValidatedData::string($data['status']));
        $result = ValidatedData::object($data['result'] ?? []);
        $snapshot = $this->snapshot($operation);
        $snapshot['footprint'] = $operation->state['role_footprint'] ?? $snapshot['footprint'];
        $artifacts = NodeFootprintProjection::artifacts(ValidatedData::object($result['footprint'] ?? []));

        return DB::transaction(function () use ($operation, $data, $status, $result, $snapshot, $artifacts): Operation {
            $operation->setRawAttributes(Operation::query()->whereKey($operation->id)->lockForUpdate()->firstOrFail()->getAttributes(), true);
            $terminal = $operation->status->terminal();
            $projected = app(DirectOperation::class)->project($operation, $data, $result);
            if ($terminal) {
                return $projected;
            }
            if (! $status->terminal()) {
                if (($result['target_completed'] ?? false) === true) {
                    $assignment = NodeRole::query()->whereKey($snapshot['assignment_id'])->where('node_id', $operation->node_id)->where('role', $snapshot['role'])->firstOrFail();
                    $assignment->markConvergenceActive();
                    $projected->state = [...($projected->state ?? []), 'role_target_completed' => true];
                    $projected->save();
                    app(NodeFootprintProjection::class)->record($operation, ValidatedData::object($snapshot['footprint']), ValidatedData::object($result['footprint']), $artifacts);
                }
                if (($result['source_completed'] ?? false) === true && $operation->kind === 'node.role_relocate') {
                    NodeRoleRemovalProjection::recordCleanup(ValidatedData::object(ValidatedData::object($operation->input['_relocate'])['source']), true);
                }
                if (($result['runtime_completed'] ?? false) === true) {
                    app(NodeRoleRuntimeProjection::class)->record($snapshot);
                }
                if (isset($result['publication_phase'])) {
                    $phase = ValidatedData::object($result['publication_phase']);
                    app(NodeRolePublicationState::class)->record(ValidatedData::object($snapshot['certificates']), ValidatedData::string($phase['phase']), ValidatedData::integer($phase['node_id']));
                }
                if (($result['prepare_footprint'] ?? false) === true && ! isset($operation->state['role_footprint'])) {
                    $footprint = app(NodeConvergeInput::class)->capture(Node::query()->findOrFail($operation->node_id), false, false);
                    unset($footprint['secret_hash']);
                    $projected->state = [...($projected->state ?? []), 'role_footprint' => $footprint];
                    $projected->save();
                }

                return $projected;
            }
            $assignment = NodeRole::query()->whereKey($snapshot['assignment_id'])->where('node_id', $operation->node_id)
                ->where('role', $snapshot['role'])->firstOrFail();
            if ($status === OperationStatus::Succeeded) {
                $assignment->markConvergenceActive();
                app(NodeFootprintProjection::class)->record($operation, ValidatedData::object($snapshot['footprint']),
                    ValidatedData::object($result['footprint']), $artifacts);
            } elseif (($projected->state['role_target_completed'] ?? false) !== true) {
                $assignment->markConvergenceFailed(ValidatedData::string($result['failed_step'] ?? 'operation'),
                    ValidatedData::string($result['error_code'] ?? 'operation.cancelled'));
            }
            app(RecordEventBroadcaster::class)->broadcast(RecordEventType::NodeUpdated, $assignment->node_id,
                NodeData::fromModel($assignment->node->refresh())->toArray());

            return $projected;
        });
    }

    /** @param array<string,mixed> $data */
    private function applyNativeRole(Operation $operation, array $data): Operation
    {
        return DB::transaction(function () use ($operation, $data): Operation {
            $operation->setRawAttributes(Operation::query()->whereKey($operation->id)->lockForUpdate()->firstOrFail()->getAttributes(), true);
            $terminal = $operation->status->terminal();
            $snapshot = $this->snapshot($operation);
            $raw = ValidatedData::object($data['result'] ?? []);
            $proven = ($data['status'] ?? null) === 'succeeded' && NativeRunResult::stepStatus($raw, 'Check the role') === 'succeeded';
            $summary = ValidatedData::object($raw['summary'] ?? []);
            $failed = $raw['failed_step'] ?? (is_array($summary['failed_steps'] ?? null) ? ($summary['failed_steps'][0] ?? 'operation') : 'operation');
            $error = $failed === 'Require supported Ubuntu' ? 'role.os_unsupported' : ($raw['error_code'] ?? $data['error_code'] ?? 'node_role.convergence_failed');
            $result = $proven
                ? ['assignment_id' => $snapshot['assignment_id'], 'role' => $snapshot['role'], 'created' => $snapshot['created'], 'role_proven' => true]
                : ['failed_step' => $failed, 'error_code' => $error];
            if (! $proven && ($data['status'] ?? null) === 'failed') {
                $data['error_code'] = $error;
            }
            $projected = app(DirectOperation::class)->project($operation, $data, $result);
            if ($terminal) {
                return $projected;
            }
            $intent = ValidatedData::object($operation->input['native_role']);
            $certificates = ValidatedData::object($snapshot['certificates']);
            foreach (RouteExecutionProof::objects($certificates['leaves']) as $index => $leaf) {
                if (NativeRunResult::stepStatus($raw, 'Sign role certificates-'.$index) === 'succeeded') {
                    app(NodeRolePublicationState::class)->record($certificates, 'certificates_published', ValidatedData::integer($leaf['node_id']));
                }
            }
            $caddy = array_values(array_filter(RouteExecutionProof::objects($intent['publications']),
                static fn (array $publication): bool => ValidatedData::object($publication['after'])['caddy'] !== null));
            foreach ($caddy as $index => $publication) {
                if (NativeRunResult::stepStatus($raw, 'Publish Caddy sites-'.$index) === 'succeeded') {
                    app(NodeRolePublicationState::class)->record($certificates, 'caddy_published', ValidatedData::integer($publication['node_id']));
                }
            }
            if (NativeRunResult::stepStatus($raw, 'Apply Analytics runtime-0') === 'succeeded') {
                app(NodeRoleRuntimeProjection::class)->record($snapshot);
            }
            $assignment = NodeRole::query()->whereKey($snapshot['assignment_id'])->where('node_id', $operation->node_id)->where('role', $snapshot['role'])->firstOrFail();
            if ($proven) {
                $assignment->markConvergenceActive();
                $digests = ValidatedData::stringMap($intent['artifact_digests']);
                if ($digests !== []) {
                    $entries = [];
                    foreach ($digests as $name => $digest) {
                        $entries[] = ['name' => $name, 'digest' => $digest];
                    }
                    $applied = array_fill_keys(array_keys($digests), ['status' => 'applied']);
                    app(NodeFootprintProjection::class)->record($operation,
                        ['node_id' => $operation->node_id, 'artifacts' => $entries], ['footprint_proven' => false], $applied);
                }
            } else {
                $assignment->markConvergenceFailed(ValidatedData::string($result['failed_step'] ?? 'operation'),
                    ValidatedData::string($result['error_code'] ?? 'node_role.convergence_failed'));
            }
            app(RecordEventBroadcaster::class)->broadcast(RecordEventType::NodeUpdated, $assignment->node_id,
                NodeData::fromModel($assignment->node->refresh())->toArray());

            return $projected;
        });
    }

    /** @return array<string,mixed> */
    private function snapshot(Operation $operation): array
    {
        if ($operation->kind !== 'node.role_relocate') {
            return ValidatedData::object($operation->input['_role'] ?? []);
        }
        $transfer = ValidatedData::object($operation->input['_relocate']);

        return [...ValidatedData::object($transfer['target']),
            'publication' => $transfer['publication'], 'certificates' => $transfer['certificates'],
            'metrics_fleet' => $transfer['metrics_fleet'], 'source_removal' => $transfer['source'],
            'withdrawal_grace_seconds' => $transfer['withdrawal_grace_seconds']];
    }
}
