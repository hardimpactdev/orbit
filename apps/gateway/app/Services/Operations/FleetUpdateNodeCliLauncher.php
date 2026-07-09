<?php

declare(strict_types=1);

namespace App\Services\Operations;

use App\Enums\Nodes\NodeRoleName;
use App\Models\Node;
use App\Models\OperationUpdatePlan;
use App\Services\Nodes\NodeHostPaths;
use App\Services\Nodes\Roles\NodeRoleAssignments;

final class FleetUpdateNodeCliLauncher
{
    public static function binPath(Node $node): string
    {
        return NodeHostPaths::homeDirectoryFor($node->platform, $node->user).'/.local/bin/orbit';
    }

    public static function shouldVerifyRoleImages(Node $node): bool
    {
        return ! NodeHostPaths::isMacosPlatform($node->platform);
    }

    /**
     * @return list<string>
     */
    public static function requiredRoleImages(
        OperationUpdatePlan $plan,
        Node $node,
        NodeRoleAssignments $roles,
    ): array {
        if (! self::shouldVerifyRoleImages($node)) {
            return [];
        }

        $images = [];

        if ($roles->nodeHostsOrbitCaddy($node) && is_string($plan->role_images['orbit-caddy'] ?? null)) {
            $images[] = $plan->role_images['orbit-caddy'];
        }

        if (
            $roles->nodeHasActiveRole($node, NodeRoleName::WebSocket->value)
            && is_string($plan->role_images['orbit-websocket'] ?? null)
        ) {
            $images[] = $plan->role_images['orbit-websocket'];
        }

        return array_values(array_unique($images));
    }
}
