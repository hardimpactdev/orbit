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
