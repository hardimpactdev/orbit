<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use App\Actions\Nodes\AssignRoleAction;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
use App\Models\NodeRole;
use App\Models\Operation;
use App\Support\ValidatedData;
use InvalidArgumentException;

/** Public singleton-transfer intent. Both Node effects remain steps of the original native root. */
final readonly class NodeRoleRelocateInput
{
    public function __construct(private RoleRegistry $roles, private AssignRoleAction $assign) {}

    /** Admission owns the transaction, assignment transfer and Settings records.
     * @return array{assignment:NodeRole,source:Node}
     */
    public function prepare(Node $target, RoleName $role, bool $force, ?Node $from = null): array
    {
        if (! $this->roles->definition($role)->relocatable) {
            throw new NodeRoleValidationException("Role [{$role->value}] cannot be relocated.", ['field' => 'role', 'role' => $role->value]);
        }
        if (! $force) {
            throw new NodeRoleValidationException('Use --force to relocate this node role.',
                ['field' => 'force', 'reason' => 'destructive_consent_required', 'role' => $role->value, 'dependents' => []]);
        }
        $assignment = NodeRole::query()->with('node')->where('role', $role)->first();
        if (! $assignment instanceof NodeRole) {
            throw new NodeRoleValidationException("Role [{$role->value}] is not assigned.", ['field' => 'role', 'role' => $role->value]);
        }
        $holder = $assignment->node;
        $source = $from ?? $holder;
        if ($source->is($target) || ($holder->is($target) && $from === null)
            || (! $holder->is($target) && ! $holder->is($source))) {
            throw new NodeRoleValidationException('The source does not own this singleton transfer.', ['field' => 'from', 'role' => $role->value]);
        }
        foreach ([$source, $target] as $node) {
            if (! app(ManagedNodeEligibility::class)->allows($node)) {
                throw new ResourceOperationException('operation.kind_unavailable', 'Both relocation Nodes require managed pinned SSH access.', 422);
            }
        }
        $retry = $holder->is($target);
        if ($retry && ! Operation::query()->where('kind', 'node.role_relocate')
            ->where('node_id', $target->id)->where('input->_relocate->assignment_id', $assignment->id)
            ->where('input->_relocate->source_node_id', $source->id)
            ->where('input->_relocate->target_node_id', $target->id)->exists()) {
            throw new NodeRoleValidationException('The source does not match the admitted singleton transfer.', ['field' => 'from', 'role' => $role->value]);
        }
        if ($assignment->status !== LifecycleStatus::Active && ! ($retry && in_array($assignment->status, [LifecycleStatus::Failed, LifecycleStatus::Provisioning], true))) {
            throw new NodeRoleValidationException("Role [{$role->value}] cannot relocate from status [{$assignment->status->value}].");
        }
        $this->assign->preflightTransfer($target, $role);

        return compact('assignment', 'source');
    }

    /** @return array<string,mixed> */
    public static function bound(Operation $operation): array
    {
        $input = ValidatedData::object($operation->input['_relocate'] ?? []);
        if ($operation->kind !== 'node.role_relocate' || $operation->target_type !== 'node'
            || $operation->node_id === null || $operation->node_id !== $operation->target_id
            || ($input['target_node_id'] ?? null) !== $operation->node_id
            || $operation->status->terminal()
            || ! is_int($input['source_node_id'] ?? null) || $input['source_node_id'] === $operation->node_id
            || (ValidatedData::object($input['target'] ?? [])['node_id'] ?? null) !== $operation->node_id
            || (ValidatedData::object($input['source'] ?? [])['node_id'] ?? null) !== $input['source_node_id']
            || ! NodeRole::query()->whereKey($input['assignment_id'] ?? null)->where('node_id', $operation->node_id)
                ->where('role', $input['role'] ?? null)->exists()) {
            throw new ResourceOperationException('operation.execution_forbidden', 'This relocation belongs to another native root.', 422);
        }

        return $input;
    }

    public static function ownerId(Operation $operation): ?int
    {
        try {
            return ValidatedData::integer(self::bound($operation)['target_node_id']);
        } catch (ResourceOperationException|InvalidArgumentException) {
            return null;
        }
    }
}
