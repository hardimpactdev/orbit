<?php

declare(strict_types=1);

use Mockery as m;
use PHPUnit\Framework\SkippedWithMessageException;
use Tests\E2E\Support\E2EInstance;
use Tests\E2E\Support\E2ETopologyFactory;
use Tests\E2E\Support\E2ETopologyKind;
use Tests\E2E\Support\E2ETopologyLease;
use Tests\E2E\Support\SshKeyPair;

afterEach(function (): void {
    m::close();
});

it('has correct enum string values', function (): void {
    expect(E2ETopologyKind::Control->value)->toBe('control')
        ->and(E2ETopologyKind::ControlGateway->value)->toBe('control-gateway')
        ->and(E2ETopologyKind::ControlGatewayDev->value)->toBe('control-gateway-dev')
        ->and(E2ETopologyKind::ControlGatewayDevProd->value)->toBe('control-gateway-dev-prod');
});

it('uses minimal strategy by default', function (): void {
    withE2ETopologyEnvironment([], function (): void {
        $factory = E2ETopologyFactory::fromEnvironment();

        expect($factory->resolveKind(E2ETopologyKind::ControlGateway))->toBe(E2ETopologyKind::ControlGateway);
    });
});

it('minimal strategy requests exact kind', function (): void {
    withE2ETopologyEnvironment(['ORBIT_E2E_TOPOLOGY_STRATEGY' => 'minimal'], function (): void {
        $factory = E2ETopologyFactory::fromEnvironment();

        expect($factory->resolveKind(E2ETopologyKind::ControlGateway))->toBe(E2ETopologyKind::ControlGateway)
            ->and($factory->resolveKind(E2ETopologyKind::Control))->toBe(E2ETopologyKind::Control)
            ->and($factory->resolveKind(E2ETopologyKind::ControlGatewayDevProd))->toBe(E2ETopologyKind::ControlGatewayDevProd);
    });
});

it('superset strategy resolves smaller requests to ControlGatewayDevProd', function (): void {
    withE2ETopologyEnvironment(['ORBIT_E2E_TOPOLOGY_STRATEGY' => 'superset'], function (): void {
        $factory = E2ETopologyFactory::fromEnvironment();

        expect($factory->resolveKind(E2ETopologyKind::Control))->toBe(E2ETopologyKind::ControlGatewayDevProd)
            ->and($factory->resolveKind(E2ETopologyKind::ControlGateway))->toBe(E2ETopologyKind::ControlGatewayDevProd)
            ->and($factory->resolveKind(E2ETopologyKind::ControlGatewayDev))->toBe(E2ETopologyKind::ControlGatewayDevProd)
            ->and($factory->resolveKind(E2ETopologyKind::ControlGatewayDevProd))->toBe(E2ETopologyKind::ControlGatewayDevProd);
    });
});

it('falls back to minimal for unknown strategy', function (): void {
    withE2ETopologyEnvironment(['ORBIT_E2E_TOPOLOGY_STRATEGY' => 'unknown'], function (): void {
        $factory = E2ETopologyFactory::fromEnvironment();

        expect($factory->resolveKind(E2ETopologyKind::ControlGateway))->toBe(E2ETopologyKind::ControlGateway);
    });
});

it('skips test when requiring a topology', function (): void {
    withE2ETopologyEnvironment([], function (): void {
        $factory = E2ETopologyFactory::fromEnvironment();

        expect(fn () => $factory->require(E2ETopologyKind::Control))
            ->toThrow(SkippedWithMessageException::class, 'Prepared topology not available: control');
    });
});

it('lease cleanup is idempotent', function (): void {
    $control = m::mock(E2EInstance::class);
    $control->shouldReceive('delete')->once();

    $lease = new E2ETopologyLease(
        kind: E2ETopologyKind::Control,
        control: $control,
        gateway: null,
        dev: null,
        prod: null,
        sshKeyPair: new SshKeyPair('/tmp/fake', '/tmp/fake.pub'),
    );

    $lease->cleanup();
    $lease->cleanup();
});

it('cleans up all instances', function (): void {
    $control = m::mock(E2EInstance::class);
    $gateway = m::mock(E2EInstance::class);
    $dev = m::mock(E2EInstance::class);
    $prod = m::mock(E2EInstance::class);

    $control->shouldReceive('delete')->once();
    $gateway->shouldReceive('delete')->once();
    $dev->shouldReceive('delete')->once();
    $prod->shouldReceive('delete')->once();

    $lease = new E2ETopologyLease(
        kind: E2ETopologyKind::ControlGatewayDevProd,
        control: $control,
        gateway: $gateway,
        dev: $dev,
        prod: $prod,
        sshKeyPair: new SshKeyPair('/tmp/fake', '/tmp/fake.pub'),
    );

    $lease->cleanup();
});

/**
 * @param  array<string, string>  $values
 */
function withE2ETopologyEnvironment(array $values, Closure $callback): void
{
    $keys = ['ORBIT_E2E_PROVIDER', 'ORBIT_E2E_TOPOLOGY_STRATEGY'];
    $previous = [];

    foreach ($keys as $key) {
        $previous[$key] = getenv($key);
        putenv($key);
    }

    foreach ($values as $key => $value) {
        putenv("{$key}={$value}");
    }

    try {
        $callback();
    } finally {
        foreach ($previous as $key => $value) {
            if (is_string($value)) {
                putenv("{$key}={$value}");

                continue;
            }

            putenv($key);
        }
    }
}
