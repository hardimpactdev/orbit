<?php

declare(strict_types=1);

use App\E2E\Support\E2EInstance;
use App\E2E\Support\E2ETopologyCapabilities;
use App\E2E\Support\E2ETopologyFactory;
use App\E2E\Support\E2ETopologyKind;
use App\E2E\Support\E2ETopologyLease;
use App\E2E\Support\E2ETopologyUnavailable;
use App\E2E\Support\SshKeyPair;
use Mockery as m;

afterEach(function (): void {
    m::close();
});

it('has correct enum string values', function (): void {
    expect(E2ETopologyKind::Operator->value)->toBe('operator')
        ->and(E2ETopologyKind::OperatorGateway->value)->toBe('operator_gateway')
        ->and(E2ETopologyKind::OperatorGatewayAppdev->value)->toBe('operator_gateway_app-dev')
        ->and(E2ETopologyKind::OperatorGatewayAppdevAppprod->value)->toBe('operator_gateway_app-dev_app-prod')
        ->and(E2ETopologyKind::OperatorGatewayAgent->value)->toBe('operator_gateway_agent')
        ->and(E2ETopologyKind::tryFromInput('operator_gateway_app-prod_ingress')?->value)->toBe('operator_gateway_app-prod_ingress')
        ->and(E2ETopologyKind::Control)->toBe(E2ETopologyKind::Operator)
        ->and(E2ETopologyKind::tryFromInput('operator-gateway-agent'))->toBe(E2ETopologyKind::OperatorGatewayAgent)
        ->and(E2ETopologyKind::tryFromInput('control-gateway-dev-prod'))->toBe(E2ETopologyKind::OperatorGatewayAppdevAppprod);
});

it('resolves requested topology kinds exactly', function (): void {
    withE2ETopologyEnvironment([], function (): void {
        $factory = E2ETopologyFactory::fromEnvironment();
        $ingressKind = E2ETopologyKind::tryFromInput('operator_gateway_app-prod_ingress');

        expect($ingressKind)->not->toBeNull();
        expect($factory->resolveKind(E2ETopologyKind::ControlGateway))->toBe(E2ETopologyKind::ControlGateway)
            ->and($factory->resolveKind(E2ETopologyKind::Control))->toBe(E2ETopologyKind::Control)
            ->and($factory->resolveKind(E2ETopologyKind::ControlGatewayDev))->toBe(E2ETopologyKind::ControlGatewayDev)
            ->and($factory->resolveKind(E2ETopologyKind::ControlGatewayDevProd))->toBe(E2ETopologyKind::ControlGatewayDevProd)
            ->and($factory->resolveKind(E2ETopologyKind::OperatorGatewayAgent))->toBe(E2ETopologyKind::OperatorGatewayAgent)
            ->and($factory->resolveKind($ingressKind))->toBe($ingressKind);
    });
});

it('reports unavailable topology when requiring a topology', function (): void {
    withE2ETopologyEnvironment([
        'ORBIT_E2E_INCUS_HOSTS' => 'orbit-e2e-nonexistent.invalid',
    ], function (): void {
        $factory = E2ETopologyFactory::fromEnvironment();

        expect(fn () => $factory->require(E2ETopologyKind::Control))
            ->toThrow(E2ETopologyUnavailable::class, 'incus: prepared topology operator is not available on any Incus host: orbit-e2e-nonexistent.invalid is missing prepared templates or snapshots');
    });
});

it('reports topology provider failure details when no prepared provider is available', function (): void {
    withE2ETopologyEnvironment([
        'ORBIT_E2E_TOPOLOGY_PROVIDER' => 'incus',
        'ORBIT_E2E_INCUS_HOSTS' => 'orbit-e2e-nonexistent.invalid',
    ], function (): void {
        $factory = E2ETopologyFactory::fromEnvironment();

        expect(fn () => $factory->require(E2ETopologyKind::Control))
            ->toThrow(E2ETopologyUnavailable::class, 'Prepared topology not available');
    });
});

it('fails topology helper acquisition in strict lane mode', function (): void {
    withE2ETopologyEnvironment([
        'ORBIT_E2E_TOPOLOGY_CACHE' => '0',
        'ORBIT_E2E_FAIL_ON_TOPOLOGY_UNAVAILABLE' => '1',
        'ORBIT_E2E_TOPOLOGY_PROVIDER' => 'incus',
        'ORBIT_E2E_INCUS_HOSTS' => 'orbit-e2e-nonexistent.invalid',
    ], function (): void {
        expect(fn () => e2eTopology(E2ETopologyKind::Operator))
            ->toThrow(E2ETopologyUnavailable::class, 'Prepared topology not available');
    });
});

it('refuses providers that do not satisfy required capabilities', function (): void {
    withE2ETopologyEnvironment([
        'ORBIT_E2E_TOPOLOGY_PROVIDER' => 'docker',
    ], function (): void {
        $factory = E2ETopologyFactory::fromEnvironment()
            ->requireCapabilities(new E2ETopologyCapabilities(
                realSsh: true,
                systemd: false,
                hostMutation: false,
                kernelNetworking: false,
            ));

        expect(fn () => $factory->require(E2ETopologyKind::Control))
            ->toThrow(E2ETopologyUnavailable::class, 'capabilities do not satisfy required');
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
        rebuild: fn () => [],
    );

    $lease->cleanup();
    $lease->cleanup();
});

it('stores requested SSH users immutably', function (): void {
    $factory = E2ETopologyFactory::fromEnvironment();
    $controlOnly = $factory->withSshUsers(['control' => 'control']);

    expect($controlOnly)->not->toBe($factory)
        ->and((new ReflectionClass($controlOnly))->getProperty('sshUsers')->getValue($controlOnly))
        ->toBe(['control' => 'control'])
        ->and((new ReflectionClass($factory))->getProperty('sshUsers')->getValue($factory))
        ->toBeNull();
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
        rebuild: fn () => [],
    );

    $lease->cleanup();
});
