<?php

declare(strict_types=1);

namespace App\Services\RemoteShell;

use App\Models\Node;
use App\Services\Nodes\Roles\NodeRoleAssignments;

/**
 * Detects when the gateway runtime is containerized so host-owned work can be
 * forced onto the gateway host boundary (SSH/host CLI) instead of the
 * orbit-gateway container filesystem/network/systemd namespace.
 *
 * force_remote_host must only apply when the target node is the active
 * gateway. Agent-push targets never leave via host SSH; minting host-boundary
 * token context for them produces invalid_token on the Agent verifier.
 */
final class GatewayHostExecution
{
    /**
     * True when host-owned work for $node must leave a containerized gateway
     * runtime via force_remote_host. Always false for non-gateway targets.
     */
    public static function shouldForceRemoteHostFor(Node $node): bool
    {
        if (! self::isContainerizedGatewayRuntime()) {
            return false;
        }

        return app(NodeRoleAssignments::class)->nodeIsGateway($node);
    }

    /**
     * True when the gateway process is running inside the orbit-gateway container
     * (or an equivalent prepared container runtime). Shared by host-boundary
     * force_remote_host and RemoteHostExecutor local-bash selection.
     */
    public static function isContainerizedGatewayRuntime(): bool
    {
        $exposureMode = getenv('ORBIT_GATEWAY_EXPOSURE_MODE');

        if (is_string($exposureMode) && trim($exposureMode) !== '') {
            return true;
        }

        $hostPath = getenv('ORBIT_HOST_PATH');

        if (is_string($hostPath) && trim($hostPath) !== '') {
            return true;
        }

        $sourcePath = getenv('ORBIT_SOURCE_PATH');

        return is_string($sourcePath) && trim($sourcePath) === '/opt/orbit';
    }
}
