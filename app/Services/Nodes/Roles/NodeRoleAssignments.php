<?php

declare(strict_types=1);

namespace App\Services\Nodes\Roles;

use App\Models\Node;
use App\Models\NodeRoleAssignment;
use Illuminate\Database\Eloquent\Collection;

class NodeRoleAssignments
{
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
            ->whereIn('status', ['active', 'pending', 'error'])
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
