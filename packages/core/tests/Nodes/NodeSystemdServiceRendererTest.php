<?php

declare(strict_types=1);

use Orbit\Core\Nodes\NodeSystemdServiceRenderer;

describe(NodeSystemdServiceRenderer::class, function (): void {
    it('renders the agent behind network-online without requiring one WireGuard unit name', function (): void {
        $renderer = new NodeSystemdServiceRenderer;

        $unit = $renderer->agentUnit(
            user: 'orbit',
            agentBinary: '/home/orbit/.local/bin/orbit-agent',
            orbitBinary: '/home/orbit/.local/bin/orbit',
            configPath: '/home/orbit/.config/orbit/agent.toml',
            httpBind: '10.6.0.20:9477',
        );

        expect($unit)
            ->toContain('After=network-online.target')
            ->toContain('Wants=network-online.target')
            ->toContain('Environment=ORBIT_AGENT_ORBIT_BINARY=/home/orbit/.local/bin/orbit')
            ->toContain('Restart=always')
            ->not->toContain('wg-quick@');
    });

    it('renders a boot reconciler that retries Caddy and starts only managed always-on runtime kinds', function (): void {
        $renderer = new NodeSystemdServiceRenderer;

        expect($renderer->runtimeBootScript())
            ->toContain('label=orbit.managed=true')
            ->toContain('label=orbit.container.kind=$kind')
            ->toContain('managed_container_ids caddy')
            ->toContain('app-runtime workspace-runtime websocket-runtime')
            ->toContain('{{.HostConfig.RestartPolicy.Name}}')
            ->toContain('{{.HostConfig.NetworkMode}}')
            ->toContain('.NetworkSettings.Networks')
            ->toContain('[ "$restart_policy" = "always" ]')
            ->toContain('docker network connect "$network" "$container"')
            ->toContain('reconnect_configured_network "$container"')
            ->toContain('docker restart')
            ->toContain('docker start')
            ->not->toContain('unless-stopped')->and($renderer->runtimeBootUnit())->toContain(
                'After=docker.service network-online.target',
            )->toContain('Requires=docker.service')->toContain('Restart=on-failure')->toContain(
                'RestartSec=5',
            )->toContain('StartLimitIntervalSec=0')->toContain('WantedBy=multi-user.target')
            ->not->toContain('wg-quick@');
    });
});
