<?php

declare(strict_types=1);

namespace App\Services\RemoteShell;

/**
 * Detects when the gateway runtime is containerized so host-owned work can be
 * forced onto the gateway host boundary (SSH/host CLI) instead of the
 * orbit-gateway container filesystem/network/systemd namespace.
 */
final class GatewayHostExecution
{
    /**
     * True when the current process is the orbit-gateway container (or an
     * equivalent prepared topology container) and host-owned checks must leave
     * the container via force_remote_host.
     */
    public static function shouldForceRemoteHost(): bool
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
