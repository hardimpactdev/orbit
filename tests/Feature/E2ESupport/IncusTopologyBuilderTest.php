<?php

declare(strict_types=1);

use App\E2E\Support\E2EConfig;
use App\E2E\Support\E2EPhaseTimer;
use App\E2E\Support\E2ETopologyKind;
use App\E2E\Support\IncusHost;
use App\E2E\Support\IncusTopologyBuilder;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;
use Mockery as m;

afterEach(function (): void {
    m::close();
});

function incusTopologyBuilderProcessResult(string $output = '', string $errorOutput = '', bool $successful = true): ProcessResult
{
    $result = m::mock(ProcessResult::class);
    $result->shouldReceive('successful')->andReturn($successful);
    $result->shouldReceive('output')->andReturn($output);
    $result->shouldReceive('errorOutput')->andReturn($errorOutput);

    return $result;
}

function incusTopologyBuilderConfig(): E2EConfig
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
        bootstrapUser: 'provisioner',
        controlUser: 'control',
        instancePrefix: 'orbit-e2e',
        timeoutSeconds: 60,
        cpus: '2',
        memory: '2GiB',
        topologyCpus: '1',
        topologyMemory: '2GiB',
        topologyStateSize: '4GiB',
        incusStoragePool: '',
        incusMaxVmsPerHost: 4,
        dockerHosts: ['local'],
        dockerMaxContainersPerHost: 8,
        keep: false,
    );
}

it('throws when the blank image is missing', function (): void {
    $config = E2EConfig::fromEnvironment();

    $host = m::mock(IncusHost::class, [$config])->makePartial();
    $host->shouldReceive('imageExists')
        ->with($config->blankImage)
        ->andReturn(false);

    $builder = new IncusTopologyBuilder($host);
    $builder->useBundle('/tmp/orbit-e2e-bundle-test');

    expect(fn () => $builder->build(E2ETopologyKind::Control))
        ->toThrow(RuntimeException::class, "Required blank image [{$config->blankImage}] not found");
});

it('throws when no provisioning bundle has been staged', function (): void {
    $config = E2EConfig::fromEnvironment();

    $host = m::mock(IncusHost::class, [$config])->makePartial();
    $host->shouldReceive('imageExists')->andReturn(true);

    $builder = new IncusTopologyBuilder($host);

    expect(fn () => $builder->build(E2ETopologyKind::Control))
        ->toThrow(RuntimeException::class, 'No provisioning bundle has been staged');
});

it('throws when a target template instance already exists', function (): void {
    $config = E2EConfig::fromEnvironment();

    $host = m::mock(IncusHost::class, [$config])->makePartial();
    $host->shouldReceive('imageExists')->andReturn(true);
    $host->shouldReceive('instanceExists')
        ->with('orbit-template-control')
        ->andReturn(true);

    $builder = new IncusTopologyBuilder($host);
    $builder->useBundle('/tmp/orbit-e2e-bundle-test');

    expect(fn () => $builder->build(E2ETopologyKind::Control))
        ->toThrow(RuntimeException::class, 'Template instance [orbit-template-control] already exists');
});

it('deletes target template instances before replacing them', function (): void {
    $config = E2EConfig::fromEnvironment();

    $host = m::mock(IncusHost::class, [$config])->makePartial();
    $host->shouldReceive('imageExists')->andReturn(true);
    $host->shouldReceive('instanceExists')
        ->andReturnUsing(fn (string $name): bool => $name === 'orbit-template-control');
    $host->shouldReceive('deleteInstance')
        ->with('orbit-template-control')
        ->once()
        ->andReturn(incusTopologyBuilderProcessResult());
    $host->shouldReceive('run')
        ->with(m::on(fn (string $command): bool => str_starts_with($command, 'mktemp -d ')))
        ->andReturn(incusTopologyBuilderProcessResult(successful: false));

    $builder = new IncusTopologyBuilder($host);
    $builder->useBundle('/tmp/orbit-e2e-bundle-test');

    expect(fn () => $builder->build(E2ETopologyKind::Control, replaceExisting: true))
        ->toThrow(RuntimeException::class, 'Could not create work directory');
});

it('replaces prerequisite stage templates before rebuilding a larger topology', function (): void {
    $config = E2EConfig::fromEnvironment();
    $deleted = [];

    $existing = [
        'orbit-template-control',
        'orbit-template-gateway',
        'orbit-template-dev',
    ];

    $host = m::mock(IncusHost::class, [$config])->makePartial();
    $host->shouldReceive('imageExists')->andReturn(true);
    $host->shouldReceive('instanceExists')
        ->andReturnUsing(fn (string $name): bool => in_array($name, $existing, true));
    $host->shouldReceive('deleteInstance')
        ->andReturnUsing(function (string $name) use (&$deleted): ProcessResult {
            $deleted[] = $name;

            return incusTopologyBuilderProcessResult();
        });
    $host->shouldReceive('run')
        ->with(m::on(fn (string $command): bool => str_starts_with($command, 'mktemp -d ')))
        ->andReturn(incusTopologyBuilderProcessResult(successful: false));

    $builder = new IncusTopologyBuilder($host);
    $builder->useBundle('/tmp/orbit-e2e-bundle-test');

    expect(fn () => $builder->build(E2ETopologyKind::ControlGatewayDev, replaceExisting: true))
        ->toThrow(RuntimeException::class, 'Could not create work directory')
        ->and($deleted)->toBe(array_reverse($existing));
});

it('records phase timings while building topology templates', function (): void {
    $config = incusTopologyBuilderConfig();
    $timer = new E2EPhaseTimer;

    $host = m::mock(IncusHost::class, [$config])->makePartial();
    $host->shouldReceive('imageExists')->with($config->blankImage)->andReturn(true);
    $host->shouldReceive('instanceExists')->with('orbit-template-control')->andReturn(false);
    $host->shouldReceive('waitForCloudInit')->with('orbit-template-control')->once();
    $host->shouldReceive('provisionInstance')
        ->with('orbit-template-control', 'control', '/tmp/orbit-e2e-bundle-test', 'control')
        ->once()
        ->andReturn(incusTopologyBuilderProcessResult());
    $host->shouldReceive('stopInstance')->with('orbit-template-control')->once()->andReturn(incusTopologyBuilderProcessResult());
    $host->shouldReceive('snapshotInstance')->with('orbit-template-control', 'clean-operator')->once()->andReturn(incusTopologyBuilderProcessResult());
    $host->shouldReceive('run')->andReturnUsing(function (string $command, ?int $timeoutSeconds = null): ProcessResult {
        if (str_starts_with($command, 'mktemp -d ')) {
            return incusTopologyBuilderProcessResult("/tmp/orbit-topology-builder-test\n");
        }

        if (str_contains($command, 'orbit-template-control')) {
            return incusTopologyBuilderProcessResult("10.201.0.10\n");
        }

        return incusTopologyBuilderProcessResult();
    });

    $builder = new IncusTopologyBuilder($host, $timer);
    $builder->useBundle('/tmp/orbit-e2e-bundle-test');

    $builder->build(E2ETopologyKind::Control);

    $eventNames = array_column($timer->events(), 'name');

    expect($eventNames)->toContain('preflight')
        ->and($eventNames)->toContain('workdir')
        ->and($eventNames)->toContain('ssh-key')
        ->and($eventNames)->toContain('control.launch')
        ->and($eventNames)->toContain('control.cloud-init')
        ->and($eventNames)->toContain('control.provision')
        ->and($eventNames)->toContain('control.provisioning-ssh-key')
        ->and($eventNames)->toContain('control.identity')
        ->and($eventNames)->toContain('finalize.stop.control')
        ->and($eventNames)->toContain('finalize.snapshot.control')
        ->and($eventNames)->toContain('workdir.cleanup');
});

it('builds prepared topology templates through staged node:new snapshots', function (): void {
    $config = incusTopologyBuilderConfig();
    $commands = [];

    Process::fake([
        'wg genkey' => Process::result(output: "private-key\n"),
        'wg pubkey' => Process::result(output: "public-key\n"),
    ]);

    $host = m::mock(IncusHost::class, [$config])->makePartial();
    $host->shouldReceive('imageExists')->with($config->blankImage)->andReturn(true);
    $host->shouldReceive('instanceExists')->andReturn(false);
    $host->shouldReceive('waitForCloudInit')->times(4);
    $host->shouldReceive('provisionInstance')->with('orbit-template-control', 'control', '/tmp/orbit-e2e-bundle-test', 'control')->once()->andReturn(incusTopologyBuilderProcessResult());
    $host->shouldReceive('stopInstance')->times(10)->andReturn(incusTopologyBuilderProcessResult());
    $host->shouldReceive('snapshotInstance')->times(10)->andReturn(incusTopologyBuilderProcessResult());
    $host->shouldReceive('run')->andReturnUsing(function (string $command, ?int $timeoutSeconds = null) use (&$commands): ProcessResult {
        $commands[] = $command;

        if (str_contains($command, 'docker exec wg-easy wg show wg0 public-key')) {
            return incusTopologyBuilderProcessResult("wg-easy-public-key\n");
        }

        if (str_starts_with($command, 'mktemp -d ')) {
            return incusTopologyBuilderProcessResult("/tmp/orbit-topology-builder-test\n");
        }

        if (str_contains($command, 'orbit-template-prod')) {
            return incusTopologyBuilderProcessResult("10.201.0.13\n");
        }

        if (str_contains($command, 'orbit-template-dev')) {
            return incusTopologyBuilderProcessResult("10.201.0.12\n");
        }

        if (str_contains($command, 'orbit-template-gateway')) {
            return incusTopologyBuilderProcessResult("10.201.0.11\n");
        }

        if (str_contains($command, 'orbit-template-control')) {
            return incusTopologyBuilderProcessResult("10.201.0.10\n");
        }

        return incusTopologyBuilderProcessResult();
    });

    $builder = new IncusTopologyBuilder($host);
    $builder->useBundle('/tmp/orbit-e2e-bundle-test');

    $manifest = $builder->build(E2ETopologyKind::ControlGatewayDevProd);

    $commandOutput = implode("\n", $commands);

    expect($manifest)->toBe([
        [
            'role' => 'control',
            'name' => 'orbit-template-control',
            'snapshot' => 'clean-operator-gateway-appdev-appprod',
        ],
        [
            'role' => 'gateway',
            'name' => 'orbit-template-gateway',
            'snapshot' => 'clean-operator-gateway-appdev-appprod',
        ],
        [
            'role' => 'dev',
            'name' => 'orbit-template-dev',
            'snapshot' => 'clean-operator-gateway-appdev-appprod',
        ],
        [
            'role' => 'prod',
            'name' => 'orbit-template-prod',
            'snapshot' => 'clean-operator-gateway-appdev-appprod',
        ],
    ])->and($commandOutput)->toContain("incus launch 'orbit-blank-ubuntu-26.04' 'orbit-template-control'")
        ->and($commandOutput)->toContain("incus launch 'orbit-blank-ubuntu-26.04' 'orbit-template-gateway'")
        ->and($commandOutput)->toContain("incus launch 'orbit-blank-ubuntu-26.04' 'orbit-template-dev'")
        ->and($commandOutput)->toContain("incus launch 'orbit-blank-ubuntu-26.04' 'orbit-template-prod'")
        ->and($commandOutput)->not->toContain('orbit-template-operator-gateway-appdev-appprod-control')
        ->and($commandOutput)->not->toContain('orbit node:new gateway-1')
        ->and($commandOutput)->not->toContain('--role=gateway')
        ->and($commandOutput)->not->toContain('--control-name=control-1')
        ->and($commandOutput)->toContain('/tmp/orbit-e2e-bundle/e2e-provision-node')
        ->and($commandOutput)->toContain('--role=')
        ->and($commandOutput)->toContain('gateway')
        ->and($commandOutput)->toContain('docker run -d')
        ->and($commandOutput)->toContain('--name wg-easy')
        ->and($commandOutput)->toContain('-p 51820:51820/udp')
        ->and($commandOutput)->not->toContain('51822')
        ->and($commandOutput)->toContain('gateway-ca/orbit.crt')
        ->and($commandOutput)->toContain('ca_pem_path')
        ->and($commandOutput)->toContain('/etc/wireguard/wg-orbit.conf')
        ->and($commandOutput)->not->toContain('ListenPort = 51820')
        ->and($commandOutput)->toContain('orbit node:new')
        ->and($commandOutput)->toContain('app-dev-1')
        ->and($commandOutput)->toContain('10.201.0.12')
        ->and($commandOutput)->toContain('--user=')
        ->and($commandOutput)->toContain('provisioner')
        ->and($commandOutput)->toContain('app-prod-1')
        ->and($commandOutput)->toContain('10.201.0.13')
        ->and($commandOutput)->not->toContain('orbit:internal:bake-app-node');
});
