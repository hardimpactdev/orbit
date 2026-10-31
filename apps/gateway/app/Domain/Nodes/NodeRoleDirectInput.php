<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tools\ToolManagerIntent;
use App\Domain\Tools\ToolManagerName;
use App\Infrastructure\AgentCommands\AgentDelivery;
use App\Infrastructure\Nodes\NodeRoleFirewallInput;
use App\Infrastructure\Nodes\Roles\RolePrerequisites;
use App\Models\Node;
use App\Models\NodeRole;

/** Public intent only. Account discovery and every effect belong to the native root. */
final readonly class NodeRoleDirectInput
{
    public function __construct(private AgentDelivery $delivery, private RolePrerequisites $prerequisites,
        private NodeRoleFirewallInput $firewall, private NodeConvergeInput $footprint, private ToolManagerIntent $managers) {}

    /** @return array<string,mixed> */
    public function capture(Node $node, NodeRole $assignment, bool $created): array
    {
        if ($assignment->node_id !== $node->id || ! in_array($assignment->role, [RoleName::AppDev, RoleName::Database, RoleName::CiRunner], true)) {
            throw new ResourceOperationException('operation.execution_forbidden', 'The role snapshot requires its admitted Node assignment.', 422);
        }
        $role = $assignment->role;
        $prerequisites = $this->prerequisites->input($node, $role,
            new ManagedUserAccount($node->user, NodeConvergeInput::GroupToken, NodeConvergeInput::HomeToken));
        unset($prerequisites['user'], $prerequisites['group'], $prerequisites['home']);
        $footprint = $this->footprint->capture($node, false, false);
        unset($footprint['secret_hash']);
        $managers = [];
        if ($role === RoleName::AppDev) {
            foreach ([ToolManagerName::Vp, ToolManagerName::Composer] as $manager) {
                $intent = $this->managers->input($node, $manager);
                $managers[] = ['manager' => $manager->value, 'platform' => $node->platform, 'architecture' => $node->architecture,
                    'user' => $node->user, 'materialize' => $intent['materialize'] ?? null];
            }
        }

        return ['schema_version' => 1, 'node_id' => $node->id, 'agent_sha256' => $this->delivery->pin($node),
            'assignment_id' => $assignment->id, 'role' => $role->value, 'created' => $created, 'user' => $node->user,
            'prerequisites' => $prerequisites, 'firewall' => $role === RoleName::Database ? null : $this->firewall->converge($node, $role),
            'managers' => $managers, 'footprint' => $footprint,
            'metrics_node_id' => Node::query()->where('status', LifecycleStatus::Active)->whereHas('roles',
                static fn ($query) => $query->where('role', RoleName::Metrics)->where('status', LifecycleStatus::Active))->value('id')];
    }
}
