<?php

declare(strict_types=1);

use App\E2E\Support\E2EConfig;
use App\E2E\Support\E2ETopologyKind;
use App\E2E\Support\IncusHost;
use App\E2E\Support\IncusHostPool;
use App\E2E\Support\IncusTopologyTemplate;
use Illuminate\Contracts\Process\ProcessResult;
use Mockery as m;

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

function failedProcessResult(string $error = ''): ProcessResult
{
    $result = m::mock(ProcessResult::class);
    $result->shouldReceive('successful')->andReturn(false);
    $result->shouldReceive('errorOutput')->andReturn($error);
    $result->shouldReceive('output')->andReturn('');

    return $result;
}

function mockIncusTopologyCurrentSnapshots(IncusHost $host, int $count): void
{
    $host->shouldReceive('run')
        ->times($count)
        ->withArgs(fn (string $command, int $timeoutSeconds): bool => $timeoutSeconds === 30
            && str_contains($command, 'clean-operator'))
        ->andReturn(successfulProcessResult());
}

function makeIncusTopologyTemplateTestConfig(string $topologyCpus = '1', string $topologyMemory = '2GiB', string $incusStoragePool = ''): E2EConfig
{
    return new E2EConfig(
        providerNames: ['incus'],
        topologyProviderNames: ['incus'],
        host: 'beast',
        sourceImage: '',
        blankImage: '',
        baseImage: '',
        hcloudServerType: '',
        hcloudLocation: '',
        hcloudBlankImage: '',
        bootstrapUser: 'provisioner',
        controlUser: 'control',
        instancePrefix: 'orbit-e2e',
        timeoutSeconds: 60,
        cpus: '2',
        memory: '2GiB',
        topologyCpus: $topologyCpus,
        topologyMemory: $topologyMemory,
        topologyStateSize: '4GiB',
        incusStoragePool: $incusStoragePool,
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
        ->toBe('orbit-template-gateway')
        ->and(IncusTopologyTemplate::snapshotName(E2ETopologyKind::ControlGateway))
        ->toBe('clean-operator-gateway')
        ->and(IncusTopologyTemplate::cloneName('abc123', 'control'))
        ->toBe('orbit-e2e-abc123-control');
});

it('returns true when all template instances and clean snapshots exist', function (): void {
    $host = m::mock(IncusHost::class);
    $host->shouldReceive('run')
        ->once()
        ->withArgs(function (string $command, int $timeoutSeconds): bool {
            return $timeoutSeconds === 30
                && str_contains($command, 'orbit-template-control')
                && str_contains($command, 'orbit-template-gateway')
                && str_contains($command, 'orbit-template-dev')
                && str_contains($command, 'clean-operator-gateway-appdev')
                && str_contains($command, 'clean-control-gateway-dev')
                && substr_count($command, 'grep -q') === 6;
        })
        ->andReturn(successfulProcessResult());

    expect(IncusTopologyTemplate::availableOn($host, E2ETopologyKind::ControlGatewayDev))->toBeTrue();
});

it('returns false when any template instance is missing', function (): void {
    $host = m::mock(IncusHost::class);
    $host->shouldReceive('run')
        ->once()
        ->andReturn(failedProcessResult());

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
    $hostWithout->shouldReceive('run')->andReturn(failedProcessResult());

    $hostWith = m::mock(IncusHost::class, [$config])->makePartial();
    $hostWith->shouldReceive('run')->andReturn(successfulProcessResult());
    $hostWith->shouldReceive('runningE2EInstanceCount')->andReturn(0);

    $pool = new IncusHostPool([$hostWithout, $hostWith]);

    expect($pool->firstAvailableFor(E2ETopologyKind::Control))->toBe($hostWith);
});

it('returns null when no host has required templates', function (): void {
    $config = makeIncusTopologyTemplateTestConfig();

    $host1 = m::mock(IncusHost::class, [$config])->makePartial();
    $host1->shouldReceive('run')->andReturn(failedProcessResult());

    $host2 = m::mock(IncusHost::class, [$config])->makePartial();
    $host2->shouldReceive('run')->andReturn(failedProcessResult());

    $pool = new IncusHostPool([$host1, $host2]);

    expect($pool->firstAvailableFor(E2ETopologyKind::ControlGateway))->toBeNull();
});

it('skips host when capacity is insufficient and selects the next', function (): void {
    $config = makeIncusTopologyTemplateTestConfig();

    $tightHost = m::mock(IncusHost::class, [$config])->makePartial();
    $tightHost->shouldReceive('run')->andReturn(successfulProcessResult());
    // 4 max - 3 running = 1 free slot, but we need 4 slots for ControlGatewayDevProd.
    $tightHost->shouldReceive('runningE2EInstanceCount')->andReturn(3);

    $freeHost = m::mock(IncusHost::class, [$config])->makePartial();
    $freeHost->shouldReceive('run')->andReturn(successfulProcessResult());
    $freeHost->shouldReceive('runningE2EInstanceCount')->andReturn(0);

    $pool = new IncusHostPool([$tightHost, $freeHost]);

    expect($pool->firstAvailableFor(E2ETopologyKind::ControlGatewayDevProd))->toBe($freeHost);
});

it('returns null when every host with templates is at capacity', function (): void {
    $config = makeIncusTopologyTemplateTestConfig();

    $host1 = m::mock(IncusHost::class, [$config])->makePartial();
    $host1->shouldReceive('run')->andReturn(successfulProcessResult());
    $host1->shouldReceive('runningE2EInstanceCount')->andReturn(4);

    $host2 = m::mock(IncusHost::class, [$config])->makePartial();
    $host2->shouldReceive('run')->andReturn(successfulProcessResult());
    $host2->shouldReceive('runningE2EInstanceCount')->andReturn(4);

    $pool = new IncusHostPool([$host1, $host2]);

    expect($pool->firstAvailableFor(E2ETopologyKind::Control))->toBeNull();
});

it('builds a batch script that copies all roles in parallel, applies limits, then starts in parallel', function (): void {
    $config = makeIncusTopologyTemplateTestConfig('1', '2GiB');
    $host = m::mock(IncusHost::class, [$config])->makePartial();
    mockIncusTopologyCurrentSnapshots($host, 4);

    $script = IncusTopologyTemplate::buildBatchScript(
        $host,
        E2ETopologyKind::ControlGatewayDevProd,
        'runX',
        IncusTopologyTemplate::rolesFor(E2ETopologyKind::ControlGatewayDevProd),
    );

    // Every role gets a backgrounded copy with a captured pid.
    foreach (['control', 'gateway', 'dev', 'prod'] as $role) {
        expect($script)->toContain("incus copy 'orbit-template-{$role}/clean-operator-gateway-appdev-appprod' 'orbit-e2e-runX-{$role}' &");
        expect($script)->toContain("incus start 'orbit-e2e-runX-{$role}' &");
        expect($script)->toContain("incus config set 'orbit-e2e-runX-{$role}' limits.cpu='1' limits.memory='2GiB'");
        expect($script)->toContain("incus config device override 'orbit-e2e-runX-{$role}' eth0 hwaddr=");
    }

    // All copy commands appear before any start command (the dev block is
    // copy/wait/limits/start/wait, in that order).
    $firstStartPos = strpos($script, 'incus start');
    $firstIdentityPos = strpos($script, 'incus config device override');
    foreach (['control', 'gateway', 'dev', 'prod'] as $role) {
        $copyPos = strpos($script, "incus copy 'orbit-template-{$role}/clean-operator-gateway-appdev-appprod'");
        expect($copyPos)->toBeLessThan($firstStartPos);
    }

    expect($firstIdentityPos)->toBeLessThan($firstStartPos);
});

it('adds an explicit storage pool to topology clone copies when configured', function (): void {
    $config = makeIncusTopologyTemplateTestConfig(incusStoragePool: 'orbit-e2e');
    $host = m::mock(IncusHost::class, [$config])->makePartial();
    mockIncusTopologyCurrentSnapshots($host, 2);

    $script = IncusTopologyTemplate::buildBatchScript(
        $host,
        E2ETopologyKind::ControlGateway,
        'runZ',
        IncusTopologyTemplate::rolesFor(E2ETopologyKind::ControlGateway),
    );

    expect($script)->toContain("incus copy 'orbit-template-control/clean-operator-gateway' 'orbit-e2e-runZ-control' --storage 'orbit-e2e' &")
        ->and($script)->toContain("incus copy 'orbit-template-gateway/clean-operator-gateway' 'orbit-e2e-runZ-gateway' --storage 'orbit-e2e' &");
});

it('does not use synthetic provider-interface routes for prepared gateway clones', function (): void {
    $source = file_get_contents(app_path('E2E/Support/IncusTopologyProvider.php'));

    expect($source)->not->toContain('ip addr add')
        ->and($source)->not->toContain('ip route replace')
        ->and($source)->not->toContain('DockerTopologyNetworkPlan')
        ->and($source)->toContain("private const string GatewayWireGuardIp = '10.6.0.2'")
        ->and($source)->toContain("private const string DevWireGuardIp = '10.6.0.4'")
        ->and($source)->toContain('retargetRealWireGuard')
        ->and($source)->toContain('E2EWgEasyGateway');
});

it('enables stateful migration before starting clones when stateful reset is requested', function (): void {
    $previous = getenv('ORBIT_E2E_TOPOLOGY_RESET');
    putenv('ORBIT_E2E_TOPOLOGY_RESET=stateful-restore');

    try {
        $config = makeIncusTopologyTemplateTestConfig();
        $host = m::mock(IncusHost::class, [$config])->makePartial();
        mockIncusTopologyCurrentSnapshots($host, 2);

        $script = IncusTopologyTemplate::buildBatchScript(
            $host,
            E2ETopologyKind::ControlGateway,
            'runState',
            IncusTopologyTemplate::rolesFor(E2ETopologyKind::ControlGateway),
        );

        expect($script)->toContain("incus config set 'orbit-e2e-runState-control' migration.stateful=true")
            ->and($script)->toContain("incus config set 'orbit-e2e-runState-gateway' migration.stateful=true")
            ->and($script)->toContain("incus config device set 'orbit-e2e-runState-control' root size.state='4GiB' || incus config device override 'orbit-e2e-runState-control' root size.state='4GiB'")
            ->and($script)->toContain("incus config device set 'orbit-e2e-runState-gateway' root size.state='4GiB' || incus config device override 'orbit-e2e-runState-gateway' root size.state='4GiB'")
            ->and(strpos($script, 'migration.stateful=true'))->toBeLessThan(strpos($script, 'incus start'));
    } finally {
        if ($previous === false) {
            putenv('ORBIT_E2E_TOPOLOGY_RESET');
        } else {
            putenv("ORBIT_E2E_TOPOLOGY_RESET={$previous}");
        }
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
        ->and($captured)->toContain("'orbit-e2e-runY-gateway'")
        ->and($captured)->toContain("incus config device override 'orbit-e2e-runY-control' eth0 hwaddr=");
});

it('throws when the batch script fails, surfacing the host error output', function (): void {
    $config = makeIncusTopologyTemplateTestConfig();

    $failure = m::mock(ProcessResult::class);
    $failure->shouldReceive('successful')->andReturn(false);
    $failure->shouldReceive('errorOutput')->andReturn("incus copy: not found\n");

    $host = m::mock(IncusHost::class, [$config])->makePartial();
    $host->shouldReceive('run')->andReturn($failure);

    expect(fn () => IncusTopologyTemplate::clone($host, E2ETopologyKind::Control, 'runZ'))
        ->toThrow(RuntimeException::class, 'Topology batch failed for operator');
});
