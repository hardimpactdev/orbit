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

    /**
     * @return list<string>
     */
    public static function binPathsToVerify(Node $node): array
    {
        return [self::binPath($node)];
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
        $caddyImage = $plan->runtimeRoleImage('orbit-caddy');
        $frankenPhpImage = $plan->runtimeRoleImage('orbit-frankenphp');
        $webSocketImage = $plan->runtimeRoleImage('orbit-websocket');

        if ($roles->nodeHostsOrbitCaddy($node) && $caddyImage !== null) {
            $images[] = $caddyImage;
        }

        if (
            ($roles->nodeHasActiveRole($node, NodeRoleName::AppDevelopment->value)
            || $roles->nodeHasActiveRole($node, NodeRoleName::AppProduction->value))
            && $frankenPhpImage !== null
        ) {
            $images[] = $frankenPhpImage;
        }

        if (
            $roles->nodeHasActiveRole($node, NodeRoleName::WebSocket->value)
            && $webSocketImage !== null
        ) {
            $images[] = $webSocketImage;
        }

        return array_values(array_unique($images));
    }
}
