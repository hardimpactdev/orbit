<?php

declare(strict_types=1);

namespace App\Infrastructure\WebSocket;

/**
 * Renders the systemd unit that keeps `orbit:reverb:start` running on the node. Reverb loads its app registry from the Gateway API at start,
 * so the unit waits, bounded, until the Gateway answers on the address the registry URL names (`gateway.orbit`). When the machines of a fleet
 * boot together, Reverb then accepts at about 5 s after the boot and not at its next reconcile, about 33 s later. A Gateway that never answers
 * does not hold the unit: the wait ends and Reverb starts as before.
 */
final readonly class WebSocketServiceUnitRenderer
{
    /** The wait: up to 120 probes, a quarter of a second apart. `$$` is a literal dollar sign in a unit. */
    private const string GatewayWait = "/bin/sh -c 'for i in \$\$(seq 1 120); do curl -sk -m 1 -o /dev/null https://gateway.orbit/up && exit 0; sleep 0.25; done; exit 0'";

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
