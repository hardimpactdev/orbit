<?php

declare(strict_types=1);

use Illuminate\Contracts\Process\ProcessResult;
use Mockery as m;
use Tests\E2E\Support\E2EConfig;
use Tests\E2E\Support\E2ETopologyKind;
use Tests\E2E\Support\IncusHost;
use Tests\E2E\Support\IncusHostPool;
use Tests\E2E\Support\IncusTopologyTemplate;

afterEach(function (): void {
    m::close();
});

function successfulProcessResult(): ProcessResult
{
    $result = m::mock(ProcessResult::class);
    $result->shouldReceive('successful')->andReturn(true);
    $result->shouldReceive('errorOutput')->andReturn('');
    $result->shouldReceive('output')->andReturn('');

    return $result;
}

function makeIncusTopologyTemplateTestConfig(string $topologyCpus = '1', string $topologyMemory = '2GiB'): E2EConfig
{
    return new E2EConfig(
        providerNames: ['incus'],
        host: 'beast',
        sourceImage: '',
        blankImage: '',
        controlImage: '',
        gatewayImage: '',
        hcloudServerType: '',
        hcloudLocation: '',
        hcloudBlankImage: '',
        hcloudControlImage: '',
        hcloudGatewayImage: '',
        bootstrapUser: 'provisioner',
        controlUser: 'control',
        instancePrefix: 'orbit-e2e',
        timeoutSeconds: 60,
        cpus: '2',
        memory: '2GiB',
        topologyCpus: $topologyCpus,
        topologyMemory: $topologyMemory,
        keep: false,
    );
}

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

it('clones with limits applied between copy and start', function (): void {
    $config = makeIncusTopologyTemplateTestConfig('1', '2GiB');

    $host = m::mock(IncusHost::class, [$config])->makePartial();
    $host->shouldReceive('copyInstance')
        ->with('orbit-template-control-control', 'orbit-e2e-runX-control')
        ->ordered()
        ->andReturn(successfulProcessResult());
    $host->shouldReceive('setInstanceLimits')
        ->with('orbit-e2e-runX-control', '1', '2GiB')
        ->ordered()
        ->andReturn(successfulProcessResult());
    $host->shouldReceive('startInstance')
        ->with('orbit-e2e-runX-control')
        ->ordered()
        ->andReturn(successfulProcessResult());
    // waitForAgent uses host->run('incus exec ... -- true').
    $host->shouldReceive('run')->andReturn(successfulProcessResult());

    $instances = IncusTopologyTemplate::clone($host, E2ETopologyKind::Control, 'runX');

    expect($instances)->toHaveKey('control');
});

it('passes configured topology cpus and memory to setInstanceLimits', function (): void {
    $config = makeIncusTopologyTemplateTestConfig('2', '4GiB');

    $host = m::mock(IncusHost::class, [$config])->makePartial();
    $host->shouldReceive('copyInstance')->andReturn(successfulProcessResult());
    $host->shouldReceive('setInstanceLimits')
        ->once()
        ->with('orbit-e2e-runY-control', '2', '4GiB')
        ->andReturn(successfulProcessResult());
    $host->shouldReceive('startInstance')->andReturn(successfulProcessResult());
    $host->shouldReceive('run')->andReturn(successfulProcessResult());

    $instances = IncusTopologyTemplate::clone($host, E2ETopologyKind::Control, 'runY');

    expect($instances)->toHaveKey('control');
});

it('throws when setInstanceLimits fails', function (): void {
    $config = makeIncusTopologyTemplateTestConfig();

    $failure = m::mock(ProcessResult::class);
    $failure->shouldReceive('successful')->andReturn(false);
    $failure->shouldReceive('errorOutput')->andReturn('config set failed');

    $host = m::mock(IncusHost::class, [$config])->makePartial();
    $host->shouldReceive('copyInstance')->andReturn(successfulProcessResult());
    $host->shouldReceive('setInstanceLimits')->andReturn($failure);

    expect(fn () => IncusTopologyTemplate::clone($host, E2ETopologyKind::Control, 'runZ'))
        ->toThrow(RuntimeException::class, 'Could not apply topology limits');
});
