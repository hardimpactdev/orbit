<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use App\Actions\Nodes\AssignRoleAction;
use App\Domain\Routes\RoutePublication;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
use App\Models\NodeRole;
use App\Models\Operation;
use App\Models\Route;
use App\Support\ValidatedData;
use InvalidArgumentException;

/** Admission checks for a singleton transfer. Both Node effects are steps of one native run. */
final readonly class NodeRoleRelocateInput
{
    public function __construct(private RoleRegistry $roles, private AssignRoleAction $assign) {}

    /** Admission owns the transaction, assignment transfer and Settings records.
     * @return array{assignment:NodeRole,source:Node}
     */
    public function prepare(Node $target, RoleName $role, bool $force, ?Node $from = null): array
    {
        if ($role === RoleName::Swamp) {
            // Serve on the swamp Node runs every Swamp run, so a run cannot move it. A live move is Nick's decision.
            throw new ResourceOperationException('node.role_relocate_swamp_held',
                'The swamp role stays on its Node: serve there runs every Swamp run, so a run cannot move it.', 422, details: ['role' => $role->value]);
        }
        if (! $this->roles->definition($role)->relocatable) {
            throw new NodeRoleValidationException("Role [{$role->value}] cannot be relocated.", ['field' => 'role', 'role' => $role->value]);
        }
        if (! $force) {
            throw new NodeRoleValidationException('Use --force to relocate this node role.',
                ['field' => 'force', 'reason' => 'destructive_consent_required', 'role' => $role->value, 'dependents' => []]);
        }
        // A Cluster has one Ingress; the target's Cluster names the one that moves, and the move keeps that Cluster.
        $query = NodeRole::query()->with('node')->where('role', $role);
        if ($role === RoleName::Ingress) {
            if (! is_int($target->cluster_id)) {
                throw new NodeRoleValidationException('Role [ingress] relocates only to a member of its Cluster.', ['field' => 'node', 'role' => $role->value]);
            }
            $query->where('cluster_id', $target->cluster_id);
            $this->guardIngressTarget($target);
        }
        $assignment = $query->first();
        if (! $assignment instanceof NodeRole) {
            throw new NodeRoleValidationException("Role [{$role->value}] is not assigned.", ['field' => $role === RoleName::Ingress ? 'node' : 'role', 'role' => $role->value]);
        }
        $holder = $assignment->node;
        $source = $from ?? $holder;
        if ($source->is($target) || ($holder->is($target) && $from === null)
            || (! $holder->is($target) && ! $holder->is($source))) {
            throw new NodeRoleValidationException('The source does not own this singleton transfer.', ['field' => 'from', 'role' => $role->value]);
        }
        if ($role === RoleName::Ingress) {
            $this->guardIngressSource($source, ValidatedData::integer($target->cluster_id));
        }
        $this->assign->preflightTransfer($target, $role);
        foreach ([$source, $target] as $node) {
            if (! app(ManagedNodeEligibility::class)->allows($node)) {
                throw new ResourceOperationException('operation.kind_unavailable', 'Role relocation requires two active Linux Nodes with recorded SSH management.', 422);
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

    /**
     * A source that runs a target of a public Route answers for it with its Let's Encrypt certificate until its removal. A Router on
     * another Node is published again when the Ingress moves, with a hop that trusts the Orbit certificate the source serves only
     * after its removal, so that Route would fail in between. A Router on the source keeps its own sites until then.
     */
    private function guardIngressSource(Node $source, int $cluster): void
    {
        if (NodeRole::query()->where('cluster_id', $cluster)->where('role', RoleName::Router)->where('node_id', $source->id)->exists()
            || ! $this->servesPublicRoute($source, $cluster)) {
            return;
        }
        throw new NodeRoleValidationException('Role [ingress] relocates from a Node that runs a target of a public Route only when that Node is the Router too.',
            ['field' => 'from', 'role' => RoleName::Ingress->value, 'reason' => 'source_serves_public_route']);
    }

    private function servesPublicRoute(Node $node, int $cluster): bool
    {
        return Route::query()->where('cluster_id', $cluster)->where('publication', RoutePublication::Public)
            ->whereHas('targets.instance', static fn ($query) => $query->where('node_id', $node->id))->exists();
    }

    /**
     * The target's Caddyfile changes when it takes the Ingress. A Router or a workload of a public Route then serves that
     * Route's private hop from its public site, whose Let's Encrypt certificate exists only once public DNS names the
     * target, so the hop would fail until then. Such a target needs a staged move; a dedicated Ingress Node does not.
     */
    private function guardIngressTarget(Node $target): void
    {
        $reason = match (true) {
            NodeRole::query()->where('node_id', $target->id)->where('role', RoleName::Router)->exists() => 'target_is_router',
            $this->servesPublicRoute($target, ValidatedData::integer($target->cluster_id)) => 'target_serves_public_route',
            default => null,
        };
        if ($reason !== null) {
            throw new NodeRoleValidationException('Role [ingress] relocates only to a Node that is not the Router and runs no target of a public Route.',
                ['field' => 'node', 'role' => RoleName::Ingress->value, 'reason' => $reason]);
        }
    }
}
