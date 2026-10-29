<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use App\Data\Nodes\NodeData;
use App\Domain\Broadcasting\RecordEventBroadcaster;
use App\Domain\Broadcasting\RecordEventType;
use App\Domain\Operations\OperationCompletion;
use App\Domain\Operations\OperationOutcome;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\NodeRole;
use App\Models\Operation;
use App\Support\ValidatedData;

/** Finalizes assignment state only. Integration children keep its credentials and Settings. */
final readonly class NodeRoleOperationCompletion implements OperationCompletion
{
    public function __construct(private NodeRoleOperationExecution $execution, private RecordEventBroadcaster $broadcaster,
        private NodeRoleDependencyInspector $dependencies, private NodeRoleSettings $settings) {}

    public function complete(Operation $operation, OperationOutcome $outcome): void
    {
        $intent = ValidatedData::object($operation->state['role_intent'] ?? []);
        $assignment = NodeRole::query()->findOrFail(ValidatedData::integer($intent['assignment_id'] ?? null));
        if (in_array($operation->kind, ['node.role_relocate', 'node.role_relocate_target'], true) && $outcome->status !== OperationStatus::Succeeded) {
            return;
        }
        if ($assignment->node_id !== $operation->node_id || $assignment->role->value !== ($intent['role'] ?? null)) {
            throw new ResourceOperationException('operation.completion_unproven', 'The admitted role assignment changed.', 409);
        }
        if (($operation->state['role_owned_by_parent'] ?? false) === true) {
            if ($outcome->status === OperationStatus::Succeeded) {
                $this->execution->assertCompletion($operation, $outcome);
            }

            return;
        }
        if ($outcome->status === OperationStatus::Succeeded) {
            $this->execution->assertCompletion($operation, $outcome);
            if ($operation->kind === 'node.role_remove') {
                $captured = ValidatedData::object($operation->state['role_dependencies'] ?? null);
                $current = $this->dependencies->inspect($assignment->node, $assignment->role);
                if ($current->instanceIds !== ($captured['instance_ids'] ?? null)
                    || $current->workspaceIds !== ($captured['workspace_ids'] ?? null)
                    || $current->processIds !== ($captured['process_ids'] ?? null)) {
                    throw new ResourceOperationException('node_role.dependencies_changed', 'Role dependencies changed during removal.', 409);
                }
                if ($current->processIds !== []) {
                    throw new ResourceOperationException('node_role.dependencies_unproven', 'Process runtime removal must be proven before its role can be finalized.', 409);
                }
                $this->settings->remove($assignment->node_id, $assignment->role,
                    ($operation->input['purge_data'] ?? false) === true, ($operation->state['role_offline'] ?? false) === true);
                $assignment->delete();
            } else {
                $assignment->markConvergenceActive();
            }
        } else {
            $step = $outcome->result['failed_step'] ?? 'operation';
            if ($operation->kind === 'node.role_remove') {
                $assignment->update(['status' => LifecycleStatus::Failed, 'failed_step' => 'remove:'.(is_string($step) ? $step : 'operation'),
                    'error_code' => $outcome->errorCode ?? 'operation.cancelled']);
            } else {
                $assignment->markConvergenceFailed(is_string($step) ? $step : 'operation', $outcome->errorCode ?? 'operation.cancelled');
            }
        }
        $node = $assignment->node->refresh();
        $this->broadcaster->broadcast(RecordEventType::NodeUpdated, $node->id, NodeData::fromModel($node)->toArray());
    }
}
