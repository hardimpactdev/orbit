<?php

declare(strict_types=1);

namespace App\Services\Nodes\Roles;

use App\Models\Node;
use App\Models\NodeRoleAssignment;

class NodeRoleDependencyInspector
{
    /**
     * @return list<string>
     */
    public function dependentSummaries(Node $node, NodeRoleAssignment $assignment): array
    {
        return [];
    }

    public function removeOrbitOwnedDependents(Node $node, NodeRoleAssignment $assignment, bool $purgeData): void {}
}
