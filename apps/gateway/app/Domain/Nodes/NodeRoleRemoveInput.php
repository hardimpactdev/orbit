<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use App\Domain\Routes\CaddyPublicationPlan;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentDelivery;
use App\Infrastructure\Nodes\NodeRoleFirewallInput;
use App\Models\Node;
use App\Models\NodeRole;

/** Public removal intent. The caller holds the Node row lock and has marked the assignment removing. */
final readonly class NodeRoleRemoveInput
{
    public function __construct(private AgentDelivery $delivery, private NodeRoleFirewallInput $firewall,
        private RoleRegistry $roles, private NodeRoleRuntimeInput $runtime) {}

    /** @return array<string,mixed> */
    public function capture(Node $node, NodeRole $assignment, bool $purgeData = false, bool $offline = false, bool $deleted = false, ?NodeRole $desiredAssignment = null): array
    {
        $role = $assignment->role;
        if ($assignment->node_id !== $node->id || $assignment->status !== LifecycleStatus::Removing
            || ! $this->roles->definition($role)->mutable) {
            throw new ResourceOperationException('operation.execution_forbidden', 'Removal requires its claimed Node role assignment.', 422);
        }
        if (($role === RoleName::AppDev && $node->instances()->exists()) || $offline || $deleted) {
            throw new ResourceOperationException('operation.kind_unavailable', 'Role cleanup requires its admitted online Node and detached Instances.', 422);
        }

        return ['proxy_node_ids' => app(NodeRolePlacement::class)->proxyNodeIds(), 'schema_version' => 1, 'node_id' => $node->id, 'assignment_id' => $assignment->id,
            'role' => $role->value, 'agent_sha256' => $this->delivery->pin($node), 'user' => $node->user,
            'purge_data' => $purgeData, 'offline' => $offline,
            'publication' => app(NodeRolePublicationInput::class)->capture($node, $role),
            'certificates' => app(NodeRoleCertificateInput::class)->capture($node, $role, removing: true),
            'metrics_fleet' => app(NodeRoleMetricsInput::class)->capture($desiredAssignment ?? $assignment),
            'runtime' => $this->runtime->removal($node, $role, $purgeData),
            'caddy' => in_array($role, [RoleName::AppProd, RoleName::Ingress], true) ? CaddyPublicationPlan::forNode($node) : null,
            'firewall' => $role === RoleName::Database ? null : $this->firewall->remove($node, $role),
            'public_ssh' => $node->roles()->whereKeyNot($assignment->id)->doesntExist() ? $this->firewall->publicSsh($node) : null];
    }
}
