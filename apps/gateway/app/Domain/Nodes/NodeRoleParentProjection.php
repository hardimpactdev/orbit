<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use App\Data\Nodes\NodeData;
use App\Domain\Broadcasting\RecordEventBroadcaster;
use App\Domain\Broadcasting\RecordEventType;
use App\Domain\Fleet\NodeCliState;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Operations\DirectOperationPublicView;
use App\Domain\Operations\DirectOperationWorkflowInput;
use App\Domain\Operations\NativeOperationProjection;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\NodeRole;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;

/** Projects terminal native facts, including a ready target followed by failed source cleanup. */
final readonly class NodeRoleParentProjection implements DirectOperationProjection, DirectOperationPublicView, DirectOperationWorkflowInput, NativeOperationProjection
{
    public function native(Operation $operation): bool
    {
        return isset($operation->input['intent']);
    }

    public function workflowInput(Operation $operation): array
    {
        return $this->native($operation) ? $this->input($operation) : [];
    }

    public function assertPublicParticipant(Operation $operation, int $callerNodeId): void
    {
        app(NodeRoleDirectProjection::class)->assertPublicParticipant($operation, $callerNodeId);
    }

    public function publicInput(Operation $operation, int $callerNodeId): array
    {
        return app(NodeRoleDirectProjection::class)->publicInput($operation, $callerNodeId);
    }

    public function input(Operation $operation): array
    {
        if (! $this->native($operation)) {
            return app(NodeRoleDirectProjection::class)->input($operation);
        }

        return ['intent' => $operation->input['intent'], 'execution_secrets' => $operation->input['execution_secrets']];
    }

    public function apply(Operation $operation, array $data): Operation
    {
        if (! $this->native($operation)) {
            return app(NodeRoleDirectProjection::class)->apply($operation, $data);
        }
        $status = OperationStatus::from(ValidatedData::string($data['status']));
        if (! $status->terminal()) {
            throw new ResourceOperationException('operation.result_invalid', 'Role parents project native terminal results only.', 422);
        }

        return DB::transaction(function () use ($operation, $data, $status): Operation {
            $operation->setRawAttributes(Operation::query()->whereKey($operation->id)->lockForUpdate()->firstOrFail()->getAttributes(), true);
            $terminal = $operation->status->terminal();
            $result = ValidatedData::object($data['result'] ?? []);
            $target = ValidatedData::object($result['target'] ?? []);
            $result['target_completed'] ??= ($target['role_proven'] ?? false) === true
                && ($target['runtime_proven'] ?? false) === true && ($target['certificates_proven'] ?? false) === true
                && ($target['publication_proven'] ?? false) === true;
            $result['source_completed'] ??= (ValidatedData::object($result['source_cleanup'] ?? [])['role_removed'] ?? false) === true;
            $result['target_rollback_completed'] ??= (ValidatedData::object($result['target_rollback'] ?? [])['role_removed'] ?? false) === true;
            $projected = app(DirectOperation::class)->project($operation, $data, $result);
            if ($terminal) {
                return $projected;
            }
            $snapshot = ValidatedData::object($operation->input['_role']);
            $assignment = NodeRole::query()->whereKey($snapshot['assignment_id'])->where('node_id', $operation->node_id)->where('role', $snapshot['role'])->firstOrFail();
            $target = ValidatedData::object($result['target'] ?? []);
            if ($result['target_completed'] === true) {
                $assignment->markConvergenceActive();
                $footprint = ValidatedData::object($target['footprint']);
                app(NodeFootprintProjection::class)->record($operation, ValidatedData::object($snapshot['footprint']), $footprint, NodeFootprintProjection::artifacts($footprint));
                $cli = ValidatedData::object($target['cli']);
                if (($cli['error_code'] ?? null) === 'cli.foreign_binary') {
                    app(NodeCliState::class)->markForeign($assignment->node);
                } elseif (($cli['outcome'] ?? null) !== 'skipped' && ! isset($cli['error_code'])) {
                    app(NodeCliState::class)->clear($assignment->node);
                }
            } else {
                $assignment->markConvergenceFailed(ValidatedData::string($result['failed_step'] ?? 'operation'),
                    ValidatedData::string($result['error_code'] ?? ($status === OperationStatus::Cancelled ? 'operation.cancelled' : 'node_role.convergence_failed')));
            }
            if (($target['runtime_proven'] ?? false) === true) {
                app(NodeRoleRuntimeProjection::class)->record($snapshot);
            }
            foreach (['certificates_proven' => 'certificates_published', 'publication_proven' => 'caddy_published'] as $fact => $phase) {
                if (($target[$fact] ?? false) === true) {
                    foreach (NodeRoleCertificateInput::participants(ValidatedData::object($snapshot['certificates'])) as $nodeId) {
                        app(NodeRolePublicationState::class)->record(ValidatedData::object($snapshot['certificates']), $phase, $nodeId);
                    }
                }
            }
            if ($operation->kind === 'node.role_add' && $result['target_rollback_completed'] === true) {
                NodeRoleRemovalProjection::recordCleanup($snapshot);
            }
            if ($operation->kind === 'node.role_relocate' && $result['source_completed'] === true) {
                $transfer = ValidatedData::object($operation->input['_relocate']);
                NodeRoleRemovalProjection::recordCleanup(ValidatedData::object($transfer['source']), true);
                $certificates = ValidatedData::object($transfer['certificates']);
                app(NodeRolePublicationState::class)->record($certificates, 'withdrawal_started', ValidatedData::integer($transfer['source_node_id']));
                app(NodeRolePublicationState::class)->record($certificates, 'withdrawal_completed', ValidatedData::integer($transfer['source_node_id']));
            }
            app(RecordEventBroadcaster::class)->broadcast(RecordEventType::NodeUpdated, $assignment->node_id, NodeData::fromModel($assignment->node->refresh())->toArray());

            return $projected;
        });
    }
}
