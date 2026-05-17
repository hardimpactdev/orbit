<?php

declare(strict_types=1);

namespace App\Services\Nodes\Roles;

use App\Enums\Nodes\NodeRoleName;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Services\Nodes\Roles\RoleBaselines\AppDevelopmentRoleBaseline;
use App\Services\Nodes\Roles\RoleBaselines\AppProductionRoleBaseline;
use App\Services\Nodes\Roles\RoleBaselines\DatabaseRoleBaseline;
use App\Services\Nodes\Roles\RoleBaselines\GatewayRoleBaseline;
use App\Services\Nodes\Roles\RoleBaselines\RoleBaseline;

class NodeRoleBaselineConverger
{
    public function converge(Node $node, NodeRoleAssignment $assignment): void
    {
        $this->baseline($assignment->role)->converge($node, $assignment);
    }

    public function remove(Node $node, NodeRoleAssignment $assignment, bool $purgeData): void
    {
        $this->baseline($assignment->role)->remove($node, $assignment, $purgeData);
    }

    protected function baseline(string $role): RoleBaseline
    {
        return match ($role) {
            NodeRoleName::Gateway->value => new GatewayRoleBaseline,
            NodeRoleName::AppDevelopment->value => new AppDevelopmentRoleBaseline,
            NodeRoleName::AppProduction->value => new AppProductionRoleBaseline,
            NodeRoleName::Database->value => new DatabaseRoleBaseline,
            default => new GatewayRoleBaseline,
        };
    }
}
