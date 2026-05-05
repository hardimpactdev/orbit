<?php

declare(strict_types=1);

use App\E2E\Support\E2ETopologyFactory;
use App\E2E\Support\E2ETopologyKind;

$envBackup = [];

beforeEach(function () use (&$envBackup): void {
    $envBackup['ORBIT_E2E_TOPOLOGY_STRATEGY'] = getenv('ORBIT_E2E_TOPOLOGY_STRATEGY');
    putenv('ORBIT_E2E_TOPOLOGY_STRATEGY');
});

afterEach(function () use (&$envBackup): void {
    $value = $envBackup['ORBIT_E2E_TOPOLOGY_STRATEGY'] ?? false;

    if ($value === false) {
        putenv('ORBIT_E2E_TOPOLOGY_STRATEGY');

        return;
    }

    putenv("ORBIT_E2E_TOPOLOGY_STRATEGY={$value}");
});

it('minimal strategy: requested kind equals resolved kind for all four kinds', function (): void {
    putenv('ORBIT_E2E_TOPOLOGY_STRATEGY=minimal');
    $factory = E2ETopologyFactory::fromEnvironment();

    expect($factory->resolveKind(E2ETopologyKind::Control))->toBe(E2ETopologyKind::Control)
        ->and($factory->resolveKind(E2ETopologyKind::ControlGateway))->toBe(E2ETopologyKind::ControlGateway)
        ->and($factory->resolveKind(E2ETopologyKind::ControlGatewayDev))->toBe(E2ETopologyKind::ControlGatewayDev)
        ->and($factory->resolveKind(E2ETopologyKind::ControlGatewayDevProd))->toBe(E2ETopologyKind::ControlGatewayDevProd);
});

it('superset strategy: Control resolves to ControlGatewayDevProd', function (): void {
    putenv('ORBIT_E2E_TOPOLOGY_STRATEGY=superset');
    $factory = E2ETopologyFactory::fromEnvironment();

    expect($factory->resolveKind(E2ETopologyKind::Control))->toBe(E2ETopologyKind::ControlGatewayDevProd);
});

it('superset strategy: ControlGateway resolves to ControlGatewayDevProd', function (): void {
    putenv('ORBIT_E2E_TOPOLOGY_STRATEGY=superset');
    $factory = E2ETopologyFactory::fromEnvironment();

    expect($factory->resolveKind(E2ETopologyKind::ControlGateway))->toBe(E2ETopologyKind::ControlGatewayDevProd);
});

it('superset strategy: ControlGatewayDev resolves to ControlGatewayDevProd', function (): void {
    putenv('ORBIT_E2E_TOPOLOGY_STRATEGY=superset');
    $factory = E2ETopologyFactory::fromEnvironment();

    expect($factory->resolveKind(E2ETopologyKind::ControlGatewayDev))->toBe(E2ETopologyKind::ControlGatewayDevProd);
});

it('superset strategy: ControlGatewayDevProd resolves to ControlGatewayDevProd', function (): void {
    putenv('ORBIT_E2E_TOPOLOGY_STRATEGY=superset');
    $factory = E2ETopologyFactory::fromEnvironment();

    expect($factory->resolveKind(E2ETopologyKind::ControlGatewayDevProd))->toBe(E2ETopologyKind::ControlGatewayDevProd);
});

it('unknown strategy falls back to minimal behavior', function (): void {
    putenv('ORBIT_E2E_TOPOLOGY_STRATEGY=foobar');
    $factory = E2ETopologyFactory::fromEnvironment();

    expect($factory->resolveKind(E2ETopologyKind::Control))->toBe(E2ETopologyKind::Control)
        ->and($factory->resolveKind(E2ETopologyKind::ControlGateway))->toBe(E2ETopologyKind::ControlGateway)
        ->and($factory->resolveKind(E2ETopologyKind::ControlGatewayDev))->toBe(E2ETopologyKind::ControlGatewayDev)
        ->and($factory->resolveKind(E2ETopologyKind::ControlGatewayDevProd))->toBe(E2ETopologyKind::ControlGatewayDevProd);
});

it('empty ORBIT_E2E_TOPOLOGY_STRATEGY uses minimal default', function (): void {
    putenv('ORBIT_E2E_TOPOLOGY_STRATEGY=');
    $factory = E2ETopologyFactory::fromEnvironment();

    expect($factory->resolveKind(E2ETopologyKind::ControlGateway))->toBe(E2ETopologyKind::ControlGateway);
});

it('unset ORBIT_E2E_TOPOLOGY_STRATEGY uses minimal default', function (): void {
    putenv('ORBIT_E2E_TOPOLOGY_STRATEGY');
    $factory = E2ETopologyFactory::fromEnvironment();

    expect($factory->resolveKind(E2ETopologyKind::ControlGateway))->toBe(E2ETopologyKind::ControlGateway);
});

it('ORBIT_E2E_TOPOLOGY_STRATEGY=superset enables superset', function (): void {
    putenv('ORBIT_E2E_TOPOLOGY_STRATEGY=superset');
    $factory = E2ETopologyFactory::fromEnvironment();

    expect($factory->resolveKind(E2ETopologyKind::Control))->toBe(E2ETopologyKind::ControlGatewayDevProd);
});
