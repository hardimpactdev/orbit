<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use App\Actions\Nodes\AssignRoleAction;
use App\Domain\Operations\OperationChildren;
use App\Domain\Operations\OperationGatewayCommand;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
use App\Models\NodeRole;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;

/** Transfers stored intent only, after the destination's preparation and under both Node holds. */
final readonly class TransferNodeRoleCommand implements OperationGatewayCommand
{
    public function __construct(private AssignRoleAction $assign, private NodeRoleSettings $settings) {}

    public function schema(): array
    {
        return ['type' => 'object', 'additionalProperties' => false];
    }

    public function run(Operation $operation, #[\SensitiveParameter] array $input): array
    {
        if ($operation->kind !== 'node.role_relocate_target' || ($operation->state['role_relocation_target'] ?? false) !== true) {
            throw new ResourceOperationException('operation.execution_forbidden', 'Only the admitted destination may transfer this role.', 422);
        }
        $retained = ValidatedData::object($operation->state['role_intent'] ?? null);
        $sourceId = ValidatedData::integer($retained['source_node_id'] ?? null);
        $targetId = ValidatedData::integer($retained['target_node_id'] ?? null);
        $parent = $operation->parent_operation_id === null ? null : Operation::query()->find($operation->parent_operation_id);
        if ($parent === null || $parent->kind !== 'node.role_relocate_source' || $parent->node_id !== $sourceId
            || $parent->status->terminal() || $operation->node_id !== $targetId || ($parent->state['role_intent'] ?? null) !== $retained) {
            throw new ResourceOperationException('operation.execution_forbidden', 'The transfer requires its original source hold.', 422);
        }

        app(OperationChildren::class)->verify($parent, $operation);

        return DB::transaction(function () use ($retained, $sourceId, $targetId): array {
            $assignment = NodeRole::query()->lockForUpdate()->findOrFail(ValidatedData::integer($retained['assignment_id']));
            $role = RoleName::from(ValidatedData::string($retained['role']));
            $target = Node::query()->lockForUpdate()->findOrFail($targetId);
            if ($target->status !== LifecycleStatus::Active || $assignment->role !== $role
                || ! in_array($assignment->node_id, [$sourceId, $targetId], true) || $assignment->status !== LifecycleStatus::Active) {
                throw new ResourceOperationException('node_role.assignment_changed', 'The role assignment changed during relocation.', 409);
            }
            $this->assign->preflightTransfer($target, $role);
            $this->settings->copyForTransfer($sourceId, $targetId, $role);
            if ($assignment->node_id !== $targetId) {
                $assignment->update(['node_id' => $targetId, 'status' => LifecycleStatus::Active, 'failed_step' => null, 'error_code' => null]);
            }

            return ['transferred' => true, 'assignment_id' => $assignment->id];
        });
    }
}
