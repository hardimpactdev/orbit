<?php

declare(strict_types=1);

namespace App\Services\RemoteShell;

use App\Models\Node;
use Throwable;

/**
 * Detects when the gateway runtime is containerized so host-owned work can be
 * forced onto the gateway host boundary (SSH/host CLI) instead of the
 * orbit-gateway container filesystem/network/systemd namespace.
 *
 * force_remote_host must only apply when the target node is the gateway. Agent
 * push targets never leave via host SSH; minting host-boundary token context
 * for them produces invalid_token on the Agent verifier.
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

        // Only gateway targets leave the container. Never throw from transport
        // selection when role tables or model identity are unavailable.
        try {
            if (! $node->exists) {
                return false;
            }

            return $node->hasActiveRole('gateway');
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * True when the current process is the orbit-gateway container (or an
     * equivalent prepared topology container).
     *
     * Prefer shouldForceRemoteHostFor(Node) at call sites so Agent-push nodes
     * never inherit host-boundary token context.
     */
    public static function shouldForceRemoteHost(): bool
    {
        return self::isContainerizedGatewayRuntime();
    }

    private static function isContainerizedGatewayRuntime(): bool
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
