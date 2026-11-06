<?php

declare(strict_types=1);

namespace App\Infrastructure\WebSocket;

/**
 * Renders the systemd unit that keeps `orbit:reverb:start` running on the node. Reverb loads its app registry from the Gateway API at start,
 * so the unit waits until the Gateway answers on the address the registry URL names (`gateway.orbit`). When the machines of a fleet boot
 * together, Reverb then accepts at about 5 s after the boot and not at its next reconcile, about 33 s later. The wait is stock `curl` retries,
 * at most about 90 s, and its `-` prefix makes it non-fatal: a Gateway that never answers does not hold the unit.
 */
final readonly class WebSocketServiceUnitRenderer
{
    /** The wait: up to 30 retries, one second apart, also after a refused connection. */
    private const string GatewayWait = '-/usr/bin/curl -sk -o /dev/null -m 2 --retry 30 --retry-delay 1 --retry-connrefused --retry-all-errors https://gateway.orbit/up';

    public function render(string $installPath, string $managedUser, string $managedGroup, int $port): string
    {
        $wait = self::GatewayWait;

        return <<<UNIT
            # Managed by Orbit. Do not edit; the websocket role converge overwrites this file.
            [Unit]
            Description=Orbit websocket role: Reverb realtime server
            After=network-online.target
            Wants=network-online.target

            [Service]
            Type=simple
            User={$managedUser}
            Group={$managedGroup}
            WorkingDirectory={$installPath}
            TimeoutStartSec=180
            ExecStartPre={$wait}
            ExecStart=/usr/bin/php {$installPath}/artisan orbit:reverb:start --host=127.0.0.1 --port={$port}
            Restart=always
            RestartSec=1

            [Install]
            WantedBy=multi-user.target

            UNIT;
    }
}
