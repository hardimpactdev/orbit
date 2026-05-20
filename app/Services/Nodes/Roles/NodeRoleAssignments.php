<?php

declare(strict_types=1);

namespace App\Services\Nodes\Roles;

use App\Enums\Nodes\NodeRoleName;
use App\Enums\Nodes\NodeRoleStatus;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class NodeRoleAssignments
{
    /**
     * @return list<string>
     */
    public function appHostRoles(): array
    {
        return [
            NodeRoleName::AppDevelopment->value,
            NodeRoleName::AppProduction->value,
        ];
    }

    /**
     * @return list<string>
     */
    public function toolHostRoles(): array
    {
        return [
            ...$this->appHostRoles(),
            NodeRoleName::Database->value,
            NodeRoleName::Agent->value,
        ];
    }

    /**
     * @return list<string>
     */
    public function gatewayOrAppHostRoles(): array
    {
        return [
            NodeRoleName::Gateway->value,
            ...$this->appHostRoles(),
        ];
    }

    public function nodeHasActiveRole(Node $node, string $role): bool
    {
        return $this->activeAssignment($node, $role) instanceof NodeRoleAssignment;
    }

    public function activeAssignment(Node $node, string $role): ?NodeRoleAssignment
    {
        if ($node->relationLoaded('roleAssignments')) {
            return $node->roleAssignments
                ->first(fn (NodeRoleAssignment $assignment): bool => $assignment->role === $role
                    && $assignment->status === NodeRoleStatus::Active->value);
        }

        return $node->roleAssignments()
            ->where('role', $role)
            ->where('status', NodeRoleStatus::Active->value)
            ->first();
    }

    public function nodeHasActiveGatewayRole(Node $node): bool
    {
        return $this->nodeHasActiveRole($node, NodeRoleName::Gateway->value);
    }

    public function nodeHasActiveVpnRole(Node $node): bool
    {
        return $this->nodeHasActiveRole($node, NodeRoleName::Vpn->value);
    }

    public function nodeHasActiveAgentRole(Node $node): bool
    {
        return $this->nodeHasActiveRole($node, NodeRoleName::Agent->value);
    }

    public function activeGatewayNodeQuery(): Builder
    {
        return Node::query()
            ->where('status', 'active')
            ->whereIn('id', $this->activeNodeIdsForRole(NodeRoleName::Gateway->value));
    }

    public function activeVpnNodeQuery(): Builder
    {
        return Node::query()
            ->where('status', 'active')
            ->whereIn('id', $this->activeNodeIdsForRole(NodeRoleName::Vpn->value));
    }

    public function nodeIsGateway(Node $node): bool
    {
        return $node->status === 'active'
            && $this->nodeHasActiveGatewayRole($node);
    }

    public function nodeHasActiveAppHostRole(Node $node): bool
    {
        return $this->nodeHasAnyActiveRole($node, $this->appHostRoles());
    }

    public function nodeHasActiveToolHostRole(Node $node): bool
    {
        return $this->nodeHasAnyActiveRole($node, $this->toolHostRoles());
    }

    public function activeAppHostEnvironment(Node $node): ?string
    {
        if ($this->nodeHasActiveRole($node, NodeRoleName::AppDevelopment->value)) {
            return 'development';
        }

        if ($this->nodeHasActiveRole($node, NodeRoleName::AppProduction->value)) {
            return 'production';
        }

        return null;
    }

    public function assignmentRoleLabel(Node $node): string
    {
        if ($this->nodeHasActiveGatewayRole($node)) {
            return NodeRoleName::Gateway->value;
        }

        if ($this->nodeHasActiveAppHostRole($node)) {
            return 'app';
        }

        if ($this->nodeHasActiveRole($node, NodeRoleName::Database->value)) {
            return NodeRoleName::Database->value;
        }

        if ($this->nodeHasActiveAgentRole($node)) {
            return NodeRoleName::Agent->value;
        }

        return 'control';
    }

    public function nodeCanServeGatewayOrAppHostWorkloads(Node $node): bool
    {
        return $this->nodeIsGateway($node)
            || $this->nodeHasActiveAppHostRole($node);
    }

    public function nodeCanHostManagedTools(Node $node): bool
    {
        return $this->nodeIsGateway($node)
            || $this->nodeHasActiveToolHostRole($node);
    }

    /**
     * @param  list<string>  $roles
     */
    public function nodeHasAnyActiveRole(Node $node, array $roles): bool
    {
        if (! $node->relationLoaded('roleAssignments')) {
            return $node->roleAssignments()
                ->whereIn('role', $roles)
                ->where('status', NodeRoleStatus::Active->value)
                ->exists();
        }

        return $node->roleAssignments
            ->contains(fn (NodeRoleAssignment $assignment): bool => in_array($assignment->role, $roles, true)
                && $assignment->status === NodeRoleStatus::Active->value);
    }

    /**
     * @return list<int>
     */
    public function activeNodeIdsForRole(string $role): array
    {
        return $this->activeNodeIdsForRoles([$role]);
    }

    /**
     * @return list<int>
     */
    public function activeAppHostNodeIds(): array
    {
        return $this->activeNodeIdsForRoles($this->appHostRoles());
    }

    /**
     * @return list<int>
     */
    public function activeToolHostNodeIds(): array
    {
        return $this->activeNodeIdsForRoles($this->toolHostRoles());
    }

    /**
     * @return list<int>
     */
    public function activeAgentNodeIds(): array
    {
        return $this->activeNodeIdsForRole(NodeRoleName::Agent->value);
    }

    /**
     * @return list<int>
     */
    public function activeGatewayOrAppHostNodeIds(): array
    {
        return $this->activeNodeIdsForRoles($this->gatewayOrAppHostRoles());
    }

    /**
     * @param  list<string>  $roles
     * @return list<int>
     */
    public function activeNodeIdsForRoles(array $roles): array
    {
        return NodeRoleAssignment::query()
            ->whereIn('role', $roles)
            ->where('status', NodeRoleStatus::Active->value)
            ->distinct()
            ->orderBy('node_id')
            ->pluck('node_id')
            ->map(fn (mixed $nodeId): int => (int) $nodeId)
            ->all();
    }

    public function find(Node $node, string $role): ?NodeRoleAssignment
    {
        return $node->roleAssignments()
            ->where('role', $role)
            ->first();
    }

    /**
     * @return Collection<int, NodeRoleAssignment>
     */
    public function conflicting(Node $node, NodeRoleDefinition $definition): Collection
    {
        return $node->roleAssignments()
            ->whereIn('status', [
                NodeRoleStatus::Active->value,
                NodeRoleStatus::Pending->value,
                NodeRoleStatus::Error->value,
            ])
            ->whereIn('role', $definition->conflictsWith)
            ->orderBy('role')
            ->get();
    }

    public function platformSupported(NodeRoleDefinition $definition, ?string $platform): bool
    {
        $normalizedPlatform = $this->normalizePlatform($platform);

        if ($normalizedPlatform === null) {
            return false;
        }

        return in_array($normalizedPlatform, $definition->supportedPlatforms, true);
    }

    public function normalizePlatform(?string $platform): ?string
    {
        if (! is_string($platform) || trim($platform) === '') {
            return null;
        }

        return explode('_', $platform, 2)[0];
    }
}
