<?php

declare(strict_types=1);

namespace App\Services\Nodes\Roles;

use App\Enums\Nodes\NodeRoleName;
use App\Enums\Nodes\NodeRoleStatus;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Services\Workspaces\WorkspaceRoleGuard;
use Illuminate\Support\Facades\DB;

final readonly class NodeRoleActivator
{
    public function __construct(
        private WorkspaceRoleGuard $workspaceRoleGuard,
        private RoleSelfGrantMaterializer $roleSelfGrantMaterializer,
    ) {}

    public function ensureCanActivate(Node $node, string $role): void
    {
        if ($role !== NodeRoleName::AppProduction->value) {
            return;
        }

        $this->workspaceRoleGuard->ensureNodeOwnsNoWorkspaces($node);
    }

    public function activate(Node $node, NodeRoleAssignment $assignment): NodeRoleAssignment
    {
        $this->ensureCanActivate($node, $assignment->role);

        /** @var NodeRoleAssignment $activatedAssignment */
        $activatedAssignment = DB::transaction(function () use ($node, $assignment): NodeRoleAssignment {
            $this->ensureCanActivate($node, $assignment->role);

            $assignment->forceFill([
                'status' => NodeRoleStatus::Active->value,
                'converged_at' => now(),
                'last_error' => null,
            ])->save();

            $this->roleSelfGrantMaterializer->materializeOnRoleApplied(
                $node,
                NodeRoleName::from($assignment->role),
            );

            return $assignment->fresh() ?? $assignment;
        });

        return $activatedAssignment;
    }
}
