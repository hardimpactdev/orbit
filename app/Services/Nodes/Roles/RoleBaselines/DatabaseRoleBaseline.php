<?php

declare(strict_types=1);

namespace App\Services\Nodes\Roles\RoleBaselines;

use App\Models\Node;
use App\Models\NodeRoleAssignment;
use RuntimeException;

class DatabaseRoleBaseline implements RoleBaseline
{
    public function converge(Node $node, NodeRoleAssignment $assignment): void
    {
        if ($node->role === 'gateway') {
            throw new RuntimeException('The database role cannot be assigned to a gateway node.');
        }

        if (! str_starts_with((string) $node->platform, 'ubuntu')) {
            throw new RuntimeException('The database role requires an Ubuntu host.');
        }
    }

    public function remove(Node $node, NodeRoleAssignment $assignment, bool $purgeData): void {}
}
