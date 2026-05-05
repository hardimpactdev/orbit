<?php

declare(strict_types=1);

use Illuminate\Contracts\Process\ProcessResult;
use Mockery as m;
use Tests\E2E\Support\E2EConfig;
use Tests\E2E\Support\IncusHost;

afterEach(function (): void {
    m::close();
});

function incusHostTestConfig(string $incusStoragePool = ''): E2EConfig
{
    return new E2EConfig(
        providerNames: ['incus'],
        topologyProviderNames: ['incus'],
        host: 'beast',
        sourceImage: 'images:ubuntu/26.04/cloud',
        blankImage: 'orbit-blank-ubuntu-26.04',
        baseImage: 'orbit-base-ubuntu-26.04',
        hcloudServerType: 'cpx11',
        hcloudLocation: 'ash',
        hcloudBlankImage: 'ubuntu-24.04',
        hcloudControlImage: '',
        hcloudGatewayImage: '',
        bootstrapUser: 'provisioner',
        controlUser: 'control',
        instancePrefix: 'orbit-e2e',
        timeoutSeconds: 60,
        cpus: '2',
        memory: '2GiB',
        topologyCpus: '1',
        topologyMemory: '2GiB',
        topologyStateSize: '4GiB',
        incusStoragePool: $incusStoragePool,
        incusMaxVmsPerHost: 4,
        dockerHosts: ['local'],
        dockerMaxContainersPerHost: 8,
        keep: false,
    );
}

function incusHostTestProcessResult(): ProcessResult
{
    $result = m::mock(ProcessResult::class);
    $result->shouldReceive('successful')->andReturn(true);
    $result->shouldReceive('output')->andReturn('');
    $result->shouldReceive('errorOutput')->andReturn('');

    return $result;
}

function recordingIncusHost(E2EConfig $config, array &$commands): IncusHost
{
    return new class($config, $commands) extends IncusHost
    {
        /** @var list<string> */
        private array $commands;

        /**
         * @param  list<string>  $commands
         */
        public function __construct(E2EConfig $config, array &$commands)
        {
            parent::__construct($config);
            $this->commands = &$commands;
        }

        public function run(string $command, ?int $timeoutSeconds = null): ProcessResult
        {
            $this->commands[] = $command;

            return incusHostTestProcessResult();
        }
    };
}

it('adds configured storage pool to launch and copy commands', function (): void {
    $commands = [];
    $host = recordingIncusHost(incusHostTestConfig('orbit-e2e'), $commands);

    $host->launchInstance('orbit-base-ubuntu-26.04', 'orbit-template-control-control');
    $host->copyInstance('orbit-template-control-control/clean', 'orbit-e2e-run-control');

    expect($commands[0])->toContain("incus launch 'orbit-base-ubuntu-26.04' 'orbit-template-control-control' --vm --storage 'orbit-e2e' >/dev/null")
        ->and($commands[1])->toContain("incus copy 'orbit-template-control-control/clean' 'orbit-e2e-run-control' --storage 'orbit-e2e'");
});

it('uses incus snapshot restore and supports stateful restore', function (): void {
    $commands = [];
    $host = recordingIncusHost(incusHostTestConfig(), $commands);

    $host->restoreSnapshot('orbit-e2e-run-control', 'lease-clean');
    $host->restoreSnapshot('orbit-e2e-run-control', 'lease-warm', stateful: true);

    expect($commands[0])->toContain("incus snapshot restore 'orbit-e2e-run-control' 'lease-clean'")
        ->and($commands[1])->toContain("incus snapshot restore 'orbit-e2e-run-control' 'lease-warm' --stateful");
});

it('uses reusable stateful snapshots for warm topology reset points', function (): void {
    $commands = [];
    $host = recordingIncusHost(incusHostTestConfig(), $commands);

    $host->snapshotStatefulInstance('orbit-e2e-run-control', 'lease-warm');

    expect($commands[0])->toContain("incus snapshot create 'orbit-e2e-run-control' 'lease-warm' --stateful --reuse");
});

it('can restore snapshots concurrently', function (): void {
    $commands = [];
    $host = recordingIncusHost(incusHostTestConfig(), $commands);

    $host->restoreSnapshotsConcurrently([
        'orbit-e2e-run-control',
        'orbit-e2e-run-gateway',
    ], 'lease-warm', stateful: true);

    expect($commands[0])->toContain("incus snapshot restore 'orbit-e2e-run-control' 'lease-warm' --stateful & PID_RESTORE_0=$!")
        ->and($commands[0])->toContain("incus snapshot restore 'orbit-e2e-run-gateway' 'lease-warm' --stateful & PID_RESTORE_1=$!")
        ->and($commands[0])->toContain('wait $PID_RESTORE_0')
        ->and($commands[0])->toContain('wait $PID_RESTORE_1');
});
