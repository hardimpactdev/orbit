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
use App\Domain\Operations\OperationStatus;
use App\Models\Node;
use App\Models\NodeRole;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;

/** Role assignment and footprint state only. The original native root owns every effect. */
final readonly class NodeRoleDirectProjection implements DirectOperationProjection, DirectOperationPublicView
{
    public function input(Operation $operation): array
    {
        if ($operation->kind === 'node.role_converge') {
            return ['intent' => $operation->input['intent'], 'execution_secrets' => $operation->input['execution_secrets']];
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
        app(NodeRolePublicationInput::class)->assertPublicParticipant($operation, $callerNodeId);
    }

    public function publicInput(Operation $operation, int $callerNodeId): array
    {
        return app(NodeRolePublicationInput::class)->publicInput($operation, $callerNodeId);
    }

    public function apply(Operation $operation, array $data): Operation
    {
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
            if ($operation->kind === 'node.role_converge') {
                if (($result['runtime_completed'] ?? false) === true) {
                    app(NodeRoleRuntimeProjection::class)->record($snapshot);
                }
                $phases = $result['publication_phases'] ?? [];
                if (! is_array($phases)) {
                    throw new \InvalidArgumentException('Native publication phases must be a list.');
                }
                foreach ($phases as $raw) {
                    $phase = ValidatedData::object($raw);
                    app(NodeRolePublicationState::class)->record(ValidatedData::object($snapshot['certificates']),
                        ValidatedData::string($phase['phase']), ValidatedData::integer($phase['node_id']));
                }
                if (($result['target_completed'] ?? false) === true) {
                    $assignment->markConvergenceActive();
                    $projected->state = [...($projected->state ?? []), 'role_target_completed' => true];
                    $projected->save();
                }
            }
            if ($status === OperationStatus::Succeeded) {
                $assignment->markConvergenceActive();
                app(NodeFootprintProjection::class)->record($operation, ValidatedData::object($snapshot['footprint']),
                    ValidatedData::object($result['footprint']), $artifacts);
                $cli = ValidatedData::object($result['cli']);
                if (($cli['error_code'] ?? null) === 'cli.foreign_binary') {
                    app(NodeCliState::class)->markForeign($assignment->node);
                } elseif (($cli['outcome'] ?? null) !== 'skipped' && ! isset($cli['error_code'])) {
                    app(NodeCliState::class)->clear($assignment->node);
                }
            } elseif (($projected->state['role_target_completed'] ?? false) !== true) {
                $assignment->markConvergenceFailed(ValidatedData::string($result['failed_step'] ?? 'operation'),
                    ValidatedData::string($result['error_code'] ?? 'operation.cancelled'));
            }
            app(RecordEventBroadcaster::class)->broadcast(RecordEventType::NodeUpdated, $assignment->node_id,
                NodeData::fromModel($assignment->node->refresh())->toArray());

            return $projected;
        });
    }

    /** @return array<string,mixed> */
    private function snapshot(Operation $operation): array
    {
        if ($operation->kind === 'node.role_converge') {
            return ValidatedData::object($operation->input['intent']);
        }
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
