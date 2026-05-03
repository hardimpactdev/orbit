<?php

declare(strict_types=1);

use Mockery as m;
use Tests\E2E\Support\E2EConfig;
use Tests\E2E\Support\E2ETopologyKind;
use Tests\E2E\Support\IncusHost;
use Tests\E2E\Support\IncusHostPool;
use Tests\E2E\Support\IncusTopologyTemplate;

afterEach(function (): void {
    m::close();
});

it('maps each topology kind to expected roles', function (): void {
    expect(IncusTopologyTemplate::rolesFor(E2ETopologyKind::Control))->toBe(['control'])
        ->and(IncusTopologyTemplate::rolesFor(E2ETopologyKind::ControlGateway))->toBe(['control', 'gateway'])
        ->and(IncusTopologyTemplate::rolesFor(E2ETopologyKind::ControlGatewayDev))->toBe(['control', 'gateway', 'dev'])
        ->and(IncusTopologyTemplate::rolesFor(E2ETopologyKind::ControlGatewayDevProd))->toBe(['control', 'gateway', 'dev', 'prod']);
});

it('generates correct template and clone names', function (): void {
    expect(IncusTopologyTemplate::templateName(E2ETopologyKind::ControlGateway, 'gateway'))
        ->toBe('orbit-template-control-gateway-gateway')
        ->and(IncusTopologyTemplate::cloneName('abc123', 'control'))
        ->toBe('orbit-e2e-abc123-control');
});

it('returns true when all template instances exist', function (): void {
    $host = m::mock(IncusHost::class);
    $host->shouldReceive('instanceExists')
        ->with('orbit-template-control-gateway-dev-control')
        ->andReturn(true);
    $host->shouldReceive('instanceExists')
        ->with('orbit-template-control-gateway-dev-gateway')
        ->andReturn(true);
    $host->shouldReceive('instanceExists')
        ->with('orbit-template-control-gateway-dev-dev')
        ->andReturn(true);

    expect(IncusTopologyTemplate::availableOn($host, E2ETopologyKind::ControlGatewayDev))->toBeTrue();
});

it('returns false when any template instance is missing', function (): void {
    $host = m::mock(IncusHost::class);
    $host->shouldReceive('instanceExists')
        ->with('orbit-template-control-gateway-control')
        ->andReturn(true);
    $host->shouldReceive('instanceExists')
        ->with('orbit-template-control-gateway-gateway')
        ->andReturn(false);

    expect(IncusTopologyTemplate::availableOn($host, E2ETopologyKind::ControlGateway))->toBeFalse();
});

it('parses ORBIT_E2E_INCUS_HOSTS correctly', function (): void {
    $previous = getenv('ORBIT_E2E_INCUS_HOSTS');
    putenv('ORBIT_E2E_INCUS_HOSTS=host1,host2,host3');

    try {
        $config = E2EConfig::fromEnvironment();
        $pool = IncusHostPool::fromEnvironment($config);

        $hosts = (new ReflectionClass($pool))->getProperty('hosts')->getValue($pool);

        expect($hosts)->toHaveCount(3)
            ->and($hosts[0]->config->host)->toBe('host1')
            ->and($hosts[1]->config->host)->toBe('host2')
            ->and($hosts[2]->config->host)->toBe('host3');
    } finally {
        if ($previous === false) {
            putenv('ORBIT_E2E_INCUS_HOSTS');
        } else {
            putenv("ORBIT_E2E_INCUS_HOSTS={$previous}");
        }
    }
});

it('returns single host when ORBIT_E2E_INCUS_HOSTS is unset', function (): void {
    $previous = getenv('ORBIT_E2E_INCUS_HOSTS');
    putenv('ORBIT_E2E_INCUS_HOSTS');

    try {
        $config = E2EConfig::fromEnvironment();
        $pool = IncusHostPool::fromEnvironment($config);

        $hosts = (new ReflectionClass($pool))->getProperty('hosts')->getValue($pool);

        expect($hosts)->toHaveCount(1)
            ->and($hosts[0]->config->host)->toBe($config->host);
    } finally {
        if ($previous === false) {
            putenv('ORBIT_E2E_INCUS_HOSTS');
        } else {
            putenv("ORBIT_E2E_INCUS_HOSTS={$previous}");
        }
    }
});

it('returns first host with required templates', function (): void {
    $hostWithout = m::mock(IncusHost::class);
    $hostWithout->shouldReceive('instanceExists')->andReturn(false);

    $hostWith = m::mock(IncusHost::class);
    $hostWith->shouldReceive('instanceExists')->andReturn(true);

    $pool = new IncusHostPool([$hostWithout, $hostWith]);

    expect($pool->firstAvailableFor(E2ETopologyKind::Control))->toBe($hostWith);
});

it('returns null when no host has required templates', function (): void {
    $host1 = m::mock(IncusHost::class);
    $host1->shouldReceive('instanceExists')->andReturn(false);

    $host2 = m::mock(IncusHost::class);
    $host2->shouldReceive('instanceExists')->andReturn(false);

    $pool = new IncusHostPool([$host1, $host2]);

    expect($pool->firstAvailableFor(E2ETopologyKind::ControlGateway))->toBeNull();
});
