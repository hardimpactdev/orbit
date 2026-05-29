<?php

declare(strict_types=1);

use App\Services\Runtime\OrbitContainerNames;
use Tests\TestCase;

uses(TestCase::class);

it('uses deterministic names for Orbit-owned runtime containers and network', function (): void {
    $names = new OrbitContainerNames;

    expect($names->runtime())->toBe('orbit-runtime')
        ->and($names->caddy())->toBe('orbit-caddy')
        ->and($names->network())->toBe('orbit-network');
});

it('allows the runtime container name to be provided by the topology launcher', function (): void {
    $previous = getenv('ORBIT_RUNTIME_CONTAINER');

    putenv('ORBIT_RUNTIME_CONTAINER=orbit-e2e-run-gateway-orbit-runtime');

    try {
        $names = new OrbitContainerNames;

        expect($names->runtime())->toBe('orbit-e2e-run-gateway-orbit-runtime')
            ->and($names->caddy())->toBe('orbit-caddy')
            ->and($names->network())->toBe('orbit-network');
    } finally {
        if ($previous === false) {
            putenv('ORBIT_RUNTIME_CONTAINER');
        } else {
            putenv("ORBIT_RUNTIME_CONTAINER={$previous}");
        }
    }
});

it('scopes Docker runtime names by E2E network and node scope', function (): void {
    $previousNetwork = getenv('ORBIT_E2E_DOCKER_NETWORK');
    $previousNodeContainer = getenv('ORBIT_NODE_CONTAINER');

    putenv('ORBIT_E2E_DOCKER_NETWORK=orbit-e2e-run-123');
    putenv('ORBIT_NODE_CONTAINER=orbit-e2e-run-123-prod');

    try {
        $names = new OrbitContainerNames;

        expect($names->network())->toBe('orbit-e2e-run-123')
            ->and($names->caddy())->toBe('orbit-e2e-run-123-prod-orbit-caddy')
            ->and($names->e2eScopedName('orbit-websocket-ws-1'))->toBe('orbit-e2e-run-123-prod-orbit-websocket-ws-1')
            ->and(OrbitContainerNames::forNodeScope('websocket')->caddy())->toBe('orbit-e2e-run-123-websocket-orbit-caddy')
            ->and(OrbitContainerNames::forNodeScope('websocket')->e2eScopedName('orbit-websocket-ws-1'))->toBe('orbit-e2e-run-123-websocket-orbit-websocket-ws-1');
    } finally {
        if ($previousNetwork === false) {
            putenv('ORBIT_E2E_DOCKER_NETWORK');
        } else {
            putenv("ORBIT_E2E_DOCKER_NETWORK={$previousNetwork}");
        }

        if ($previousNodeContainer === false) {
            putenv('ORBIT_NODE_CONTAINER');
        } else {
            putenv("ORBIT_NODE_CONTAINER={$previousNodeContainer}");
        }
    }
});
