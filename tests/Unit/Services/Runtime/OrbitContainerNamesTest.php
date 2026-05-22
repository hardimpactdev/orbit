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
