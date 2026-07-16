<?php

declare(strict_types=1);

use App\Enums\Gateway\GatewayExposureMode;
use App\Services\Gateway\GatewayImageReference;
use App\Services\Gateway\GatewaySwarmStackRenderer;

function gatewaySwarmImageForTest(): GatewayImageReference
{
    return GatewayImageReference::fromString(
        'ghcr.io/hardimpactdev/orbit-gateway:1.2.3@sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
    );
}

it('renders the gateway and scheduler Swarm services for gateway-direct mode', function (): void {
    $yaml = new GatewaySwarmStackRenderer()->render(
        gatewaySwarmImageForTest(),
        GatewayExposureMode::GatewayDirect,
    );

    expect($yaml)
        ->toContain('version: "3.8"')
        ->toContain('orbit-gateway:')
        ->toContain(
            'image: "ghcr.io/hardimpactdev/orbit-gateway:1.2.3@sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa"',
        )
        ->toContain('aliases:')
        ->toContain('- orbit-gateway')
        ->toContain('ORBIT_GATEWAY_EXPOSURE_MODE: gateway-direct')
        ->toContain('ORBIT_GATEWAY_CONTAINER: "{{.Task.Name}}"')
        ->toContain('DB_BUSY_TIMEOUT: "5000"')
        ->toContain('DB_JOURNAL_MODE: wal')
        ->toContain('DB_SYNCHRONOUS: NORMAL')
        ->toContain('ORBIT_FORWARD_INSTALL_BINARY: /usr/local/bin/orbit-cli')
        ->toContain('ORBIT_LOCAL_EXECUTOR_BINARY: /usr/local/bin/orbit-cli')
        ->toContain('ORBIT_HOST_PATH_PREFIX: /mnt/orbit-host')
        ->toContain('ORBIT_GATEWAY_TLS_CERT: /etc/orbit/certs/gateway.crt')
        ->toContain('ORBIT_GATEWAY_TLS_KEY: /etc/orbit/certs/gateway.key')
        ->toContain('ports:')
        ->toContain('target: 443')
        ->toContain('published: 443')
        ->toContain('mode: ingress')
        ->toContain('${ORBIT_CONFIG_ROOT:-/home/orbit/.config/orbit}:/home/orbit/.config/orbit')
        ->toContain('${ORBIT_CONFIG_ROOT:-/home/orbit/.config/orbit}/certs:/etc/orbit/certs:ro')
        ->toContain('${ORBIT_INSTALL_ROOT:-/home/orbit/orbit}/bin/orbit-binary:/usr/local/bin/orbit-cli:ro')
        ->toContain('/etc/caddy:/mnt/orbit-host/etc/caddy')
        ->toContain('/etc/orbit:/mnt/orbit-host/etc/orbit')
        ->toContain('/var/run/docker.sock:/var/run/docker.sock')
        ->toContain('/home/orbit/.ssh:/root/.ssh:ro')
        ->toContain('healthcheck:')
        ->toContain('test: ["CMD", "orbit-gateway-healthcheck"]')
        ->toContain('orbit.managed: "true"')
        ->toContain('orbit.service: orbit-gateway')
        ->toContain('order: start-first')
        ->toContain('failure_action: rollback')
        ->toContain('monitor: 60s')
        ->toContain('orbit-scheduler:')
        ->toContain('command: ["php", "artisan", "orbit-scheduler"]')
        ->toContain('ORBIT_FORWARD_INSTALL_BINARY: /usr/local/bin/orbit-cli')
        ->toContain('ORBIT_LOCAL_EXECUTOR_BINARY: /usr/local/bin/orbit-cli')
        ->toContain('${ORBIT_INSTALL_ROOT:-/home/orbit/orbit}/bin/orbit-binary:/usr/local/bin/orbit-cli:ro')
        ->toContain('healthcheck:')
        ->toContain('disable: true')
        ->toContain('orbit.service: orbit-scheduler')
        ->toContain('order: stop-first')
        ->toContain('node.labels.orbit.role.gateway == true')
        ->toContain('external: true');
});

it('omits gateway host ports when router-owned Caddy fronts the gateway', function (): void {
    $yaml = new GatewaySwarmStackRenderer()->render(
        gatewaySwarmImageForTest(),
        GatewayExposureMode::RouterColocated,
    );

    $gatewayBlock = substr(
        $yaml,
        strpos($yaml, '  orbit-gateway:'),
        strpos($yaml, '  orbit-scheduler:') - strpos($yaml, '  orbit-gateway:'),
    );

    expect($gatewayBlock)
        ->toContain('ORBIT_GATEWAY_EXPOSURE_MODE: router-colocated')
        ->toContain('ORBIT_TRUST_WIREGUARD_PROXY_HEADER: "1"')
        ->toContain('aliases:')
        ->toContain('- orbit-gateway')
        ->not
        ->toContain('ports:')
        ->and($yaml)
        ->toContain('orbit-scheduler:')
        ->toContain('order: stop-first');
});

it('renders a gateway-colocated operations Reverb service isolated from the app websocket role', function (): void {
    $yaml = new GatewaySwarmStackRenderer()->render(
        gatewaySwarmImageForTest(),
        GatewayExposureMode::RouterColocated,
        operationsReverbImage: 'hardimpact/orbit-reverb:1.2.3@sha256:dddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddd',
    );

    $operationsBlock = substr(
        $yaml,
        strpos($yaml, '  orbit-operations-reverb:'),
        strrpos($yaml, "\nnetworks:") - strpos($yaml, '  orbit-operations-reverb:'),
    );

    expect($operationsBlock)
        ->toContain('  orbit-operations-reverb:')
        ->toContain(
            'image: "hardimpact/orbit-reverb:1.2.3@sha256:dddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddd"',
        )
        ->toContain('aliases:')
        ->toContain('- orbit-operations-reverb')
        ->toContain('APP_ENV: production')
        ->toContain('APP_DEBUG: "false"')
        ->toContain('CACHE_STORE: array')
        ->toContain('ORBIT_WEBSOCKET_APPS_CONFIG: /etc/orbit/operations-websocket/apps.php')
        ->toContain('REVERB_HOST: orbit-operations-reverb')
        ->toContain('REVERB_PORT: "8080"')
        ->toContain('REVERB_SCALING_ENABLED: "false"')
        ->toContain('REVERB_SCHEME: http')
        ->toContain('REVERB_SERVER_HOST: 0.0.0.0')
        ->toContain('REVERB_SERVER_PORT: "8080"')
        ->toContain(
            'command: ["php", "artisan", "reverb:start", "--host=0.0.0.0", "--port=8080", "--hostname=orbit-operations-reverb"]',
        )
        ->toContain(
            '${ORBIT_CONFIG_ROOT:-/home/orbit/.config/orbit}/operations-websocket:/etc/orbit/operations-websocket:ro',
        )
        ->toContain(
            'test: ["CMD-SHELL", "php -r \'$$socket = @fsockopen(\"127.0.0.1\", 8080); exit(is_resource($$socket) ? 0 : 1);\'"]',
        )
        ->toContain('replicas: 1')
        ->toContain('orbit.service: orbit-operations-reverb')
        ->toContain('node.labels.orbit.role.gateway == true')
        ->toContain('order: stop-first')
        ->not->toContain('ports:')
        ->not->toContain('REDIS_HOST')
        ->not->toContain('REDIS_PORT')
        ->not->toContain('websocket.orbit')
        ->not->toContain('node.labels.orbit.role.websocket == true');
});
