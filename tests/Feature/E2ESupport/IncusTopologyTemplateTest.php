<?php

declare(strict_types=1);

use App\E2E\Support\E2EConfig;
use App\E2E\Support\E2EPhaseTimer;
use App\E2E\Support\E2EResourceLeasePool;
use App\E2E\Support\E2ETopologyAcquisitionOptions;
use App\E2E\Support\E2ETopologyKind;
use App\E2E\Support\IncusHost;
use App\E2E\Support\IncusHostPool;
use App\E2E\Support\IncusTopologyProvider;
use App\E2E\Support\IncusTopologyTemplate;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;
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
            && str_contains($command, '/snapshots/clean-prepared-'))
        ->andReturn(successfulProcessResult());
}

function makeIncusTopologyTemplateTestConfig(string $topologyCpus = '1', string $topologyMemory = '2GiB', string $incusStoragePool = '', string $blankImage = '', string $topologyRootSize = '16GiB'): E2EConfig
{
    return new E2EConfig(
        providerNames: ['incus'],
        topologyProviderNames: ['incus'],
        host: 'beast',
        sourceImage: '',
        blankImage: $blankImage,
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
        topologyRootSize: $topologyRootSize,
        topologyStateSize: '4GiB',
        incusStoragePool: $incusStoragePool,
        dockerHosts: ['local'],
        keep: false,
        incusHostVmCaps: ['beast' => 4, 'sidecar1' => 4, 'sidecar2' => 4],
    );
}

it('maps each topology kind to expected roles', function (): void {
    $ingressKind = E2ETopologyKind::tryFromInput('operator_gateway_app-prod_ingress');

    expect($ingressKind)->not->toBeNull();

    expect(IncusTopologyTemplate::rolesFor(E2ETopologyKind::Control))->toBe(['control'])
        ->and(IncusTopologyTemplate::rolesFor(E2ETopologyKind::ControlGateway))->toBe(['control', 'gateway'])
        ->and(IncusTopologyTemplate::rolesFor(E2ETopologyKind::ControlGatewayDev))->toBe(['control', 'gateway', 'dev'])
        ->and(IncusTopologyTemplate::rolesFor(E2ETopologyKind::ControlGatewayDevProd))->toBe(['control', 'gateway', 'dev', 'prod'])
        ->and(IncusTopologyTemplate::rolesFor(E2ETopologyKind::OperatorGatewayAgent))->toBe(['control', 'gateway', 'agent'])
        ->and(IncusTopologyTemplate::rolesFor(E2ETopologyKind::OperatorGatewayAppdevAppprodAgent))->toBe(['control', 'gateway', 'dev', 'prod', 'agent'])
        ->and(IncusTopologyTemplate::rolesFor($ingressKind))->toBe(['control', 'gateway', 'prod']);
});

it('generates correct template and clone names', function (): void {
    expect(IncusTopologyTemplate::templateName(E2ETopologyKind::ControlGateway, 'gateway'))
        ->toBe('orbit-template-prepared-gateway')
        ->and(IncusTopologyTemplate::templateName(E2ETopologyKind::OperatorGatewayAppprodIngress, 'control'))
        ->toBe('orbit-template-prepared-control')
        ->and(IncusTopologyTemplate::templateName(E2ETopologyKind::OperatorGatewayAppprodIngress, 'gateway'))
        ->toBe('orbit-template-prepared-gateway')
        ->and(IncusTopologyTemplate::templateName(E2ETopologyKind::OperatorGatewayAppprodIngress, 'prod'))
        ->toBe('orbit-template-prepared-prod')
        ->and(IncusTopologyTemplate::snapshotName(E2ETopologyKind::ControlGateway))
        ->toBe('clean-prepared-operator_gateway')
        ->and(IncusTopologyTemplate::cloneName('abc123', 'control'))
        ->toBe('orbit-e2e-abc123-control');
});

it('returns true when all template instances and clean snapshots exist', function (): void {
    $host = m::mock(IncusHost::class);
    $host->shouldReceive('run')
        ->once()
        ->withArgs(function (string $command, int $timeoutSeconds): bool {
            return $timeoutSeconds === 30
                && str_contains($command, 'orbit-template-prepared-control')
                && str_contains($command, 'orbit-template-prepared-gateway')
                && str_contains($command, 'orbit-template-prepared-dev')
                && str_contains($command, '/1.0/instances/orbit-template-prepared-control/snapshots/clean-prepared-operator_gateway_app-dev_app-prod_agent')
                && ! str_contains($command, 'grep -q')
                && substr_count($command, '/snapshots/clean-prepared-') === 3;
        })
        ->andReturn(successfulProcessResult());

    expect(IncusTopologyTemplate::availableOn($host, E2ETopologyKind::ControlGatewayDev))->toBeTrue();
});

it('checks prepared snapshots by exact snapshot path instead of prefix matching', function (): void {
    $host = m::mock(IncusHost::class);
    $host->shouldReceive('run')
        ->once()
        ->withArgs(function (string $command): bool {
            return str_contains($command, "incus query '/1.0/instances/orbit-template-prepared-control/snapshots/clean-prepared-operator_gateway' >/dev/null 2>&1")
                && str_contains($command, "incus query '/1.0/instances/orbit-template-prepared-gateway/snapshots/clean-prepared-operator_gateway' >/dev/null 2>&1")
                && ! str_contains($command, 'incus info \'orbit-template-prepared-control\' --show-log=false')
                && ! str_contains($command, 'grep -q');
        })
        ->andReturn(failedProcessResult());

    expect(IncusTopologyTemplate::availableOn($host, E2ETopologyKind::ControlGateway))->toBeFalse();
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

it('uses configured incus host slots as pool candidates when explicit hosts are unset', function (): void {
    withE2EEnvironment([
        'ORBIT_E2E_INCUS_HOSTS',
    ], [
        'ORBIT_E2E_INCUS_HOST_SLOTS' => 'sidecar1:1,sidecar2:2',
    ], function (): void {
        $config = E2EConfig::fromEnvironment();
        $pool = IncusHostPool::fromEnvironment($config);

        $hosts = (new ReflectionClass($pool))->getProperty('hosts')->getValue($pool);

        expect($hosts)->toHaveCount(2)
            ->and($hosts[0]->config->host)->toBe('sidecar1')
            ->and($hosts[1]->config->host)->toBe('sidecar2');
    });
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

it('skips transient capacity checks when requested', function (): void {
    $config = makeIncusTopologyTemplateTestConfig();

    $host = m::mock(IncusHost::class, [$config])->makePartial();
    $host->shouldReceive('run')->andReturn(successfulProcessResult());
    $host->shouldNotReceive('runningE2EInstanceCount');

    $availability = (new IncusHostPool([$host]))->availabilityFor(
        E2ETopologyKind::ControlGatewayDevProd,
        checkCapacity: false,
    );

    expect($availability['host'])->toBe($host)
        ->and($availability['reason'])->toBeNull();
});

it('does not fail provider availability on transient incus capacity when host slots are configured', function (): void {
    $probedCapacity = false;

    Process::fake(function ($process) use (&$probedCapacity) {
        if (str_contains($process->command, 'incus list --format json')) {
            $probedCapacity = true;
        }

        return Process::result();
    });

    withE2EEnvironment([
        'ORBIT_E2E_INCUS_HOSTS',
    ], [
        'ORBIT_E2E_INCUS_HOST_SLOTS' => 'sidecar1:1',
        'ORBIT_E2E_INCUS_HOST_VM_CAPS' => 'sidecar1:1',
    ], function () use (&$probedCapacity): void {
        $provider = new IncusTopologyProvider(E2EConfig::fromEnvironment());
        $availability = $provider->availability(E2ETopologyKind::ControlGateway);

        expect($availability->available)->toBeTrue()
            ->and($availability->message)->toContain('sidecar1')
            ->and($probedCapacity)->toBeFalse();
    });
});

it('leases configured incus host slots before acquiring a topology', function (): void {
    $leaseDirectory = storage_path('framework/e2e/test-leases-'.bin2hex(random_bytes(4)));

    exec('rm -rf '.escapeshellarg($leaseDirectory));

    $pool = new E2EResourceLeasePool($leaseDirectory, waitSeconds: 0, staleSeconds: 60);
    $heldLease = $pool->acquire('incus', ['sidecar1' => 1]);

    try {
        withE2EEnvironment([
            'ORBIT_E2E_INCUS_HOSTS',
        ], [
            'ORBIT_E2E_INCUS_HOST_SLOTS' => 'sidecar1:1',
            'ORBIT_E2E_LEASE_DIRECTORY' => $leaseDirectory,
            'ORBIT_E2E_SLOT_WAIT_SECONDS' => '0',
        ], function (): void {
            $provider = new IncusTopologyProvider(E2EConfig::fromEnvironment());

            expect(fn () => $provider->acquire(
                E2ETopologyKind::Control,
                'run123',
                new E2EPhaseTimer,
                new E2ETopologyAcquisitionOptions,
            ))->toThrow(RuntimeException::class, 'No incus E2E slot became available');
        });
    } finally {
        $heldLease->release();
        exec('rm -rf '.escapeshellarg($leaseDirectory));
    }
});

it('can restrict availability checks to a leased incus host', function (): void {
    $config = makeIncusTopologyTemplateTestConfig();

    $host1 = m::mock(IncusHost::class, [$config->forHost('sidecar1')])->makePartial();
    $host1->shouldNotReceive('run');

    $host2 = m::mock(IncusHost::class, [$config->forHost('sidecar2')])->makePartial();
    $host2->shouldReceive('run')->andReturn(successfulProcessResult());
    $host2->shouldReceive('runningE2EInstanceCount')->andReturn(0);

    $availability = (new IncusHostPool([$host1, $host2]))->availabilityFor(
        E2ETopologyKind::ControlGateway,
        hostNames: ['sidecar2'],
    );

    expect($availability['host'])->toBe($host2)
        ->and($availability['reason'])->toBeNull();
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

it('reports capacity details when every prepared Incus host is full', function (): void {
    $config = makeIncusTopologyTemplateTestConfig();

    $host = m::mock(IncusHost::class, [$config])->makePartial();
    $host->shouldReceive('run')->andReturn(successfulProcessResult());
    $host->shouldReceive('runningE2EInstanceCount')->andReturn(4);

    $availability = (new IncusHostPool([$host]))->availabilityFor(E2ETopologyKind::ControlGateway);

    expect($availability['host'])->toBeNull()
        ->and($availability['reason'])->toBe('beast has 0/2 free VM slots (4/4 Orbit E2E VMs running)');
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
        expect($script)->toContain("incus copy 'orbit-template-prepared-{$role}/clean-prepared-operator_gateway_app-dev_app-prod_agent' 'orbit-e2e-runX-{$role}' &");
        expect($script)->toContain("incus start 'orbit-e2e-runX-{$role}' &");
        expect($script)->toContain("incus config set 'orbit-e2e-runX-{$role}' limits.cpu='1' limits.memory='2GiB'");
        expect($script)->toContain("incus config device override 'orbit-e2e-runX-{$role}' eth0 hwaddr=");
        expect($script)->toContain("incus config device set 'orbit-e2e-runX-{$role}' root size='16GiB' || incus config device override 'orbit-e2e-runX-{$role}' root size='16GiB'");
    }

    // All copy commands appear before any start command (the dev block is
    // copy/wait/limits/start/wait, in that order).
    $firstStartPos = strpos($script, 'incus start');
    $firstIdentityPos = strpos($script, 'incus config device override');
    foreach (['control', 'gateway', 'dev', 'prod'] as $role) {
        $copyPos = strpos($script, "incus copy 'orbit-template-prepared-{$role}/clean-prepared-operator_gateway_app-dev_app-prod_agent'");
        expect($copyPos)->toBeLessThan($firstStartPos);
    }

    expect($firstIdentityPos)->toBeLessThan($firstStartPos);
    expect(strpos($script, "root size='16GiB'"))->toBeLessThan($firstStartPos);
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

    expect($script)->toContain("incus copy 'orbit-template-prepared-control/clean-prepared-operator_gateway' 'orbit-e2e-runZ-control' --storage 'orbit-e2e' &")
        ->and($script)->toContain("incus copy 'orbit-template-prepared-gateway/clean-prepared-operator_gateway' 'orbit-e2e-runZ-gateway' --storage 'orbit-e2e' &");
});

it('requires the requested Incus roles in the prepared full snapshot', function (): void {
    withE2ETopologyEnvironment([], function (): void {
        $config = makeIncusTopologyTemplateTestConfig(blankImage: 'orbit-blank-ubuntu-26.04');
        $host = m::mock(IncusHost::class, [$config])->makePartial();
        $host->shouldReceive('run')
            ->once()
            ->withArgs(fn (string $command, int $timeoutSeconds): bool => $timeoutSeconds === 30
                && str_contains($command, "incus info 'orbit-template-prepared-control'")
                && str_contains($command, "incus info 'orbit-template-prepared-gateway'")
                && str_contains($command, "incus info 'orbit-template-prepared-dev'")
                && str_contains($command, "incus info 'orbit-template-prepared-prod'")
                && str_contains($command, "incus info 'orbit-template-prepared-agent'")
                && str_contains($command, 'clean-prepared-operator_gateway_app-dev_app-prod_agent')
                && ! str_contains($command, "incus image info 'orbit-blank-ubuntu-26.04'")
                && ! str_contains($command, "snapshots/clean-prepared-operator'")
                && ! str_contains($command, "snapshots/clean-prepared-operator_gateway'")
                && ! str_contains($command, 'orbit-template-prepared-ingress'))
            ->andReturn(successfulProcessResult());

        expect(IncusTopologyTemplate::availableOn($host, E2ETopologyKind::OperatorGatewayAppdevAppprodAgent))->toBeTrue();
    });
});

it('clones only requested Incus roles from the prepared full snapshot', function (): void {
    withE2ETopologyEnvironment([], function (): void {
        $config = makeIncusTopologyTemplateTestConfig();
        $host = m::mock(IncusHost::class, [$config])->makePartial();
        $snapshotChecks = [];

        $host->shouldReceive('run')
            ->andReturnUsing(function (string $command) use (&$snapshotChecks): ProcessResult {
                if (str_contains($command, '/snapshots/')) {
                    $snapshotChecks[] = $command;
                }

                return successfulProcessResult();
            });

        $script = IncusTopologyTemplate::buildBatchScript(
            $host,
            E2ETopologyKind::OperatorGatewayAgent,
            'runPrepared',
            IncusTopologyTemplate::rolesFor(E2ETopologyKind::OperatorGatewayAgent),
        );
        $checkedSnapshots = implode("\n", $snapshotChecks);

        expect($script)
            ->toContain("incus copy 'orbit-template-prepared-control/clean-prepared-operator_gateway_app-dev_app-prod_agent'")
            ->toContain("incus copy 'orbit-template-prepared-gateway/clean-prepared-operator_gateway_app-dev_app-prod_agent'")
            ->toContain("incus copy 'orbit-template-prepared-agent/clean-prepared-operator_gateway_app-dev_app-prod_agent'")
            ->not->toContain('orbit-template-prepared-dev')
            ->not->toContain('orbit-template-prepared-prod')
            ->not->toContain("orbit-template-prepared-control/clean-prepared-operator'")
            ->and($checkedSnapshots)
            ->toContain("snapshots/clean-prepared-operator_gateway_app-dev_app-prod_agent'")
            ->not->toContain("snapshots/clean-prepared-operator_gateway'")
            ->not->toContain("snapshots/clean-prepared-operator'");
    });
});

it('prepared Incus acquisition retargets selected snapshot roles without dynamic blank provisioning', function (): void {
    $source = file_get_contents(app_path('E2E/Support/IncusTopologyProvider.php'));

    expect($source)
        ->toContain('prepareInstances($instances, $this->config, $sshKeyPair, $timer, $options, $kind)')
        ->toContain('retargetTopology($instances, $config, $sshKeyPair, $kind)')
        ->toContain('--public-host=%s --skip-runtime-install')
        ->toContain('/orbit/apps/cli')
        ->toContain('ORBIT_GATEWAY_URL=%%s')
        ->toContain('orbit:internal:bake-app-node app-dev-1 --role=app-dev')
        ->toContain('seedAppdevDatabaseAndRedis($gateway')
        ->toContain('orbit:internal:bake-ingress-node app-prod-1')
        ->toContain('E2EPreparedTopology::prodHostsIngressRole($kind)')
        ->toContain('orbit:internal:bake-app-node app-prod-1 --role=app-prod')
        ->toContain('orbit:internal:bake-agent-node agent-1')
        ->toContain("private const string DevWireGuardIp = '10.6.0.4'")
        ->toContain("private const string ProdWireGuardIp = '10.6.0.5'")
        ->toContain("private const string AgentWireGuardIp = '10.6.0.6'")
        ->toContain('escapeshellarg(self::DevWireGuardIp)')
        ->toContain('escapeshellarg(self::ProdWireGuardIp)')
        ->toContain('escapeshellarg(self::AgentWireGuardIp)')
        ->toContain("foreach (['dev', 'prod', 'agent', 'ingress'] as \$role)")
        ->not->toContain('prepared.node-new')
        ->not->toContain('launchPreparedBlankRole')
        ->not->toContain('& PID_');
});

it('does not use synthetic provider-interface routes for prepared gateway clones', function (): void {
    $source = file_get_contents(app_path('E2E/Support/IncusTopologyProvider.php'));

    expect($source)->not->toContain('ip addr add')
        ->and($source)->not->toContain('ip route replace')
        ->and($source)->not->toContain('DockerTopologyNetworkPlan')
        ->and($source)->toContain("private const string GatewayWireGuardIp = '10.6.0.2'")
        ->and($source)->toContain("private const string DevWireGuardIp = '10.6.0.4'")
        ->and($source)->toContain("private const string AgentWireGuardIp = '10.6.0.6'")
        ->and($source)->toContain('retargetRealWireGuard')
        ->and($source)->toContain('orbit:internal:bake-agent-node agent-1')
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
