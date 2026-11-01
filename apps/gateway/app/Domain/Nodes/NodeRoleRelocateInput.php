<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use App\Actions\Nodes\AssignRoleAction;
use App\Domain\AppDev\PrivateDnsAnswerExpiry;
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
    public function __construct(private RoleRegistry $roles, private AssignRoleAction $assign,
        private NodeRoleDirectInput $target, private NodeRoleRemoveInput $source) {}

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

    /** The admitted singleton already names the target before desired-state capture.
     * @return array<string,mixed>
     */
    public function capture(Node $target, Node $source, NodeRole $assignment): array
    {
        if ($assignment->node_id !== $target->id || $source->is($target)
            || ! $this->roles->definition($assignment->role)->relocatable) {
            throw new ResourceOperationException('operation.execution_forbidden', 'The relocated assignment must name its admitted target.', 422);
        }
        $ghost = new NodeRole(['node_id' => $source->id, 'role' => $assignment->role, 'status' => LifecycleStatus::Removing]);
        $ghost->setAttribute('id', $assignment->id);
        $ghost->setRelation('node', $source);

        return ['proxy_node_ids' => app(NodeRolePlacement::class)->proxyNodeIds(), 'schema_version' => 1, 'role' => $assignment->role->value, 'assignment_id' => $assignment->id,
            'publication' => app(NodeRolePublicationInput::class)->capture($target, $assignment->role, $source),
            'source_node_id' => $source->id, 'target_node_id' => $target->id,
            'target' => $this->target->capture($target, $assignment, false),
            'certificates' => app(NodeRoleCertificateInput::class)->captureForExecution($target, $assignment->role, $source),
            'metrics_fleet' => app(NodeRoleMetricsInput::class)->capture($assignment),
            'source' => $this->source->capture($source, $ghost, desiredAssignment: $assignment),
            'withdrawal_grace_seconds' => $assignment->role === RoleName::WebSocket ? PrivateDnsAnswerExpiry::WithdrawalGraceSeconds : 0];
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
