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
        topologyProviderNames: ['incus'],
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
        incusMaxVmsPerHost: 4,
        dockerHosts: ['local'],
        dockerMaxContainersPerHost: 8,
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

it('returns first host with required templates and capacity', function (): void {
    $config = makeIncusTopologyTemplateTestConfig();

    $hostWithout = m::mock(IncusHost::class, [$config])->makePartial();
    $hostWithout->shouldReceive('instanceExists')->andReturn(false);

    $hostWith = m::mock(IncusHost::class, [$config])->makePartial();
    $hostWith->shouldReceive('instanceExists')->andReturn(true);
    $hostWith->shouldReceive('runningE2EInstanceCount')->andReturn(0);

    $pool = new IncusHostPool([$hostWithout, $hostWith]);

    expect($pool->firstAvailableFor(E2ETopologyKind::Control))->toBe($hostWith);
});

it('returns null when no host has required templates', function (): void {
    $config = makeIncusTopologyTemplateTestConfig();

    $host1 = m::mock(IncusHost::class, [$config])->makePartial();
    $host1->shouldReceive('instanceExists')->andReturn(false);

    $host2 = m::mock(IncusHost::class, [$config])->makePartial();
    $host2->shouldReceive('instanceExists')->andReturn(false);

    $pool = new IncusHostPool([$host1, $host2]);

    expect($pool->firstAvailableFor(E2ETopologyKind::ControlGateway))->toBeNull();
});

it('skips host when capacity is insufficient and selects the next', function (): void {
    $config = makeIncusTopologyTemplateTestConfig();

    $tightHost = m::mock(IncusHost::class, [$config])->makePartial();
    $tightHost->shouldReceive('instanceExists')->andReturn(true);
    // 4 max - 3 running = 1 free slot, but we need 4 slots for ControlGatewayDevProd.
    $tightHost->shouldReceive('runningE2EInstanceCount')->andReturn(3);

    $freeHost = m::mock(IncusHost::class, [$config])->makePartial();
    $freeHost->shouldReceive('instanceExists')->andReturn(true);
    $freeHost->shouldReceive('runningE2EInstanceCount')->andReturn(0);

    $pool = new IncusHostPool([$tightHost, $freeHost]);

    expect($pool->firstAvailableFor(E2ETopologyKind::ControlGatewayDevProd))->toBe($freeHost);
});

it('returns null when every host with templates is at capacity', function (): void {
    $config = makeIncusTopologyTemplateTestConfig();

    $host1 = m::mock(IncusHost::class, [$config])->makePartial();
    $host1->shouldReceive('instanceExists')->andReturn(true);
    $host1->shouldReceive('runningE2EInstanceCount')->andReturn(4);

    $host2 = m::mock(IncusHost::class, [$config])->makePartial();
    $host2->shouldReceive('instanceExists')->andReturn(true);
    $host2->shouldReceive('runningE2EInstanceCount')->andReturn(4);

    $pool = new IncusHostPool([$host1, $host2]);

    expect($pool->firstAvailableFor(E2ETopologyKind::Control))->toBeNull();
});

it('builds a batch script that copies all roles in parallel, applies limits, then starts in parallel', function (): void {
    $config = makeIncusTopologyTemplateTestConfig('1', '2GiB');
    $host = m::mock(IncusHost::class, [$config])->makePartial();

    $script = IncusTopologyTemplate::buildBatchScript(
        $host,
        E2ETopologyKind::ControlGatewayDevProd,
        'runX',
        IncusTopologyTemplate::rolesFor(E2ETopologyKind::ControlGatewayDevProd),
    );

    // Every role gets a backgrounded copy with a captured pid.
    foreach (['control', 'gateway', 'dev', 'prod'] as $role) {
        expect($script)->toContain("incus copy 'orbit-template-control-gateway-dev-prod-{$role}' 'orbit-e2e-runX-{$role}' &");
        expect($script)->toContain("incus start 'orbit-e2e-runX-{$role}' &");
        expect($script)->toContain("incus config set 'orbit-e2e-runX-{$role}' limits.cpu='1' limits.memory='2GiB'");
    }

    // All copy commands appear before any start command (the dev block is
    // copy/wait/limits/start/wait, in that order).
    $firstStartPos = strpos($script, 'incus start');
    foreach (['control', 'gateway', 'dev', 'prod'] as $role) {
        $copyPos = strpos($script, "incus copy 'orbit-template-control-gateway-dev-prod-{$role}'");
        expect($copyPos)->toBeLessThan($firstStartPos);
    }
});

it('clones runs the batch script through the host and waits for each agent', function (): void {
    $config = makeIncusTopologyTemplateTestConfig();
    $host = m::mock(IncusHost::class, [$config])->makePartial();

    $captured = null;
    $host->shouldReceive('run')
        ->withArgs(function (string $command) use (&$captured): bool {
            // First run is the batch (matches incus copy/start). Subsequent
            // runs are the per-role waitForAgent (incus exec ... true).
            if ($captured === null && str_contains($command, 'incus copy')) {
                $captured = $command;
            }

            return true;
        })
        ->andReturn(successfulProcessResult());

    $instances = IncusTopologyTemplate::clone($host, E2ETopologyKind::ControlGateway, 'runY');

    expect($instances)->toHaveKeys(['control', 'gateway'])
        ->and($captured)->toContain('incus copy')
        ->and($captured)->toContain("'orbit-e2e-runY-control'")
        ->and($captured)->toContain("'orbit-e2e-runY-gateway'");
});

it('throws when the batch script fails, surfacing the host error output', function (): void {
    $config = makeIncusTopologyTemplateTestConfig();

    $failure = m::mock(ProcessResult::class);
    $failure->shouldReceive('successful')->andReturn(false);
    $failure->shouldReceive('errorOutput')->andReturn("incus copy: not found\n");

    $host = m::mock(IncusHost::class, [$config])->makePartial();
    $host->shouldReceive('run')->andReturn($failure);

    expect(fn () => IncusTopologyTemplate::clone($host, E2ETopologyKind::Control, 'runZ'))
        ->toThrow(RuntimeException::class, 'Topology batch failed for control');
});
