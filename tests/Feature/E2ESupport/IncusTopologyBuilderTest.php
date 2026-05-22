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

it('rebuilds prerequisites when no complete reusable base exists', function (): void {
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
    $host->shouldReceive('snapshotExists')->andReturn(false);
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

it('reuses the highest complete prior stage before rebuilding a larger topology', function (): void {
    $config = E2EConfig::fromEnvironment();
    $deleted = [];

    $existing = [
        'orbit-template-control',
        'orbit-template-gateway',
        'orbit-template-dev',
        'orbit-template-prod',
        'orbit-template-agent',
    ];

    $baseSnapshots = [
        'orbit-template-control:clean-operator_gateway_app-dev',
        'orbit-template-gateway:clean-operator_gateway_app-dev',
        'orbit-template-dev:clean-operator_gateway_app-dev',
    ];

    $host = m::mock(IncusHost::class, [$config])->makePartial();
    $host->shouldReceive('imageExists')->andReturn(true);
    $host->shouldReceive('instanceExists')
        ->andReturnUsing(fn (string $name): bool => in_array($name, $existing, true));
    $host->shouldReceive('snapshotExists')
        ->andReturnUsing(fn (string $name, string $snapshot): bool => in_array("{$name}:{$snapshot}", $baseSnapshots, true));
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

    expect(fn () => $builder->build(E2ETopologyKind::OperatorGatewayAppdevAppprodAgent, replaceExisting: true))
        ->toThrow(RuntimeException::class, 'Could not create work directory')
        ->and($deleted)->toBe([
            'orbit-template-agent',
            'orbit-template-prod',
        ]);
});

it('rebuilds ingress-specific templates without deleting the standard prod template', function (): void {
    $config = E2EConfig::fromEnvironment();
    $deleted = [];

    $existing = [
        'orbit-template-control',
        'orbit-template-gateway',
        'orbit-template-prod',
        'orbit-template-ingress-control',
        'orbit-template-ingress-gateway',
        'orbit-template-ingress-prod',
        'orbit-template-ingress',
    ];

    $host = m::mock(IncusHost::class, [$config])->makePartial();
    $host->shouldReceive('imageExists')->andReturn(true);
    $host->shouldReceive('instanceExists')
        ->andReturnUsing(fn (string $name): bool => in_array($name, $existing, true));
    $host->shouldReceive('snapshotExists')
        ->andReturnUsing(fn (string $name, string $snapshot): bool => in_array($name, ['orbit-template-control', 'orbit-template-gateway'], true)
            && $snapshot === 'clean-operator_gateway');
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

    expect(fn () => $builder->build(E2ETopologyKind::OperatorGatewayAppprodIngress, replaceExisting: true))
        ->toThrow(RuntimeException::class, 'Could not create work directory')
        ->and($deleted)->toContain('orbit-template-ingress-control')
        ->and($deleted)->toContain('orbit-template-ingress-gateway')
        ->and($deleted)->toContain('orbit-template-ingress-prod')
        ->and($deleted)->toContain('orbit-template-ingress')
        ->and($deleted)->not->toContain('orbit-template-prod');
});

it('rebuilds appdev ingress templates without deleting app-prod ingress templates', function (): void {
    $config = E2EConfig::fromEnvironment();
    $deleted = [];

    $existing = [
        'orbit-template-control',
        'orbit-template-gateway',
        'orbit-template-dev',
        'orbit-template-appdev-ingress',
        'orbit-template-ingress',
    ];

    $baseSnapshots = [
        'orbit-template-control:clean-operator_gateway_app-dev',
        'orbit-template-gateway:clean-operator_gateway_app-dev',
        'orbit-template-dev:clean-operator_gateway_app-dev',
    ];

    $host = m::mock(IncusHost::class, [$config])->makePartial();
    $host->shouldReceive('imageExists')->andReturn(true);
    $host->shouldReceive('instanceExists')
        ->andReturnUsing(fn (string $name): bool => in_array($name, $existing, true));
    $host->shouldReceive('snapshotExists')
        ->andReturnUsing(fn (string $name, string $snapshot): bool => in_array("{$name}:{$snapshot}", $baseSnapshots, true));
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

    expect(fn () => $builder->build(E2ETopologyKind::OperatorGatewayAppdevIngress, replaceExisting: true))
        ->toThrow(RuntimeException::class, 'Could not create work directory')
        ->and($deleted)->toContain('orbit-template-appdev-ingress')
        ->and($deleted)->not->toContain('orbit-template-ingress');
});

it('restores a reusable base stage before continuing a force rebuild', function (): void {
    $config = E2EConfig::fromEnvironment();

    $host = m::mock(IncusHost::class, [$config])->makePartial();
    $host->shouldReceive('imageExists')->andReturn(true);
    $host->shouldReceive('instanceExists')
        ->andReturnUsing(fn (string $name): bool => $name === 'orbit-template-control');
    $host->shouldReceive('snapshotExists')
        ->with('orbit-template-control', 'clean-operator')
        ->andReturn(true);
    $host->shouldReceive('deleteInstance')->never();
    $host->shouldReceive('stopInstancesIfRunning')
        ->with(['orbit-template-control'])
        ->once()
        ->andReturn(incusTopologyBuilderProcessResult());
    $host->shouldReceive('restoreSnapshotsConcurrently')
        ->with(['orbit-template-control'], 'clean-operator')
        ->once()
        ->andReturn(incusTopologyBuilderProcessResult());
    $host->shouldReceive('startInstance')
        ->with('orbit-template-control')
        ->once()
        ->andReturn(incusTopologyBuilderProcessResult(errorOutput: 'start failed', successful: false));
    $host->shouldReceive('run')
        ->andReturnUsing(function (string $command): ProcessResult {
            if (str_starts_with($command, 'mktemp -d ')) {
                return incusTopologyBuilderProcessResult("/tmp/orbit-topology-builder-test\n");
            }

            return incusTopologyBuilderProcessResult();
        });

    $builder = new IncusTopologyBuilder($host);
    $builder->useBundle('/tmp/orbit-e2e-bundle-test');

    expect(fn () => $builder->build(E2ETopologyKind::OperatorGateway, replaceExisting: true))
        ->toThrow(RuntimeException::class, 'Could not start orbit-template-control: start failed');
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
            'snapshot' => 'clean-operator_gateway_app-dev_app-prod',
        ],
        [
            'role' => 'gateway',
            'name' => 'orbit-template-gateway',
            'snapshot' => 'clean-operator_gateway_app-dev_app-prod',
        ],
        [
            'role' => 'dev',
            'name' => 'orbit-template-dev',
            'snapshot' => 'clean-operator_gateway_app-dev_app-prod',
        ],
        [
            'role' => 'prod',
            'name' => 'orbit-template-prod',
            'snapshot' => 'clean-operator_gateway_app-dev_app-prod',
        ],
    ])->and($commandOutput)->toContain("incus launch 'orbit-blank-ubuntu-26.04' 'orbit-template-control'")
        ->and($commandOutput)->toContain("incus launch 'orbit-blank-ubuntu-26.04' 'orbit-template-gateway'")
        ->and($commandOutput)->toContain("incus launch 'orbit-blank-ubuntu-26.04' 'orbit-template-dev'")
        ->and($commandOutput)->toContain("incus launch 'orbit-blank-ubuntu-26.04' 'orbit-template-prod'")
        ->and($commandOutput)->not->toContain('orbit-template-operator_gateway_app-dev_app-prod-control')
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
        ->and($commandOutput)->toContain('orbit:internal:bootstrap-gateway-local gateway')
        ->and($commandOutput)->toContain('--public-host=')
        ->and($commandOutput)->toContain('public_endpoint')
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
        ->and($commandOutput)->toContain('--role=ingress')
        ->and($commandOutput)->not->toContain('orbit:internal:bake-app-node');
});

it('builds ingress topology without development or agent stages', function (): void {
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
    $host->shouldReceive('stopInstance')->times(7)->andReturn(incusTopologyBuilderProcessResult());
    $host->shouldReceive('snapshotInstance')->times(7)->andReturn(incusTopologyBuilderProcessResult());
    $host->shouldReceive('run')->andReturnUsing(function (string $command, ?int $timeoutSeconds = null) use (&$commands): ProcessResult {
        $commands[] = $command;

        if (str_contains($command, 'docker exec wg-easy wg show wg0 public-key')) {
            return incusTopologyBuilderProcessResult("wg-easy-public-key\n");
        }

        if (str_starts_with($command, 'mktemp -d ')) {
            return incusTopologyBuilderProcessResult("/tmp/orbit-topology-builder-test\n");
        }

        if (str_contains($command, 'orbit-template-ingress-control')) {
            return incusTopologyBuilderProcessResult("10.201.0.10\n");
        }

        if (str_contains($command, 'orbit-template-ingress-gateway')) {
            return incusTopologyBuilderProcessResult("10.201.0.11\n");
        }

        if (str_contains($command, 'orbit-template-ingress-prod')) {
            return incusTopologyBuilderProcessResult("10.201.0.13\n");
        }

        if (str_contains($command, 'orbit-template-ingress')) {
            return incusTopologyBuilderProcessResult("10.201.0.14\n");
        }

        if (str_contains($command, 'orbit-template-prod')) {
            return incusTopologyBuilderProcessResult("10.201.0.13\n");
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

    $manifest = $builder->build(E2ETopologyKind::OperatorGatewayAppprodIngress);
    $commandOutput = implode("\n", $commands);

    expect($manifest)->toBe([
        [
            'role' => 'control',
            'name' => 'orbit-template-ingress-control',
            'snapshot' => 'clean-operator_gateway_app-prod_ingress',
        ],
        [
            'role' => 'gateway',
            'name' => 'orbit-template-ingress-gateway',
            'snapshot' => 'clean-operator_gateway_app-prod_ingress',
        ],
        [
            'role' => 'prod',
            'name' => 'orbit-template-ingress-prod',
            'snapshot' => 'clean-operator_gateway_app-prod_ingress',
        ],
        [
            'role' => 'ingress',
            'name' => 'orbit-template-ingress',
            'snapshot' => 'clean-operator_gateway_app-prod_ingress',
        ],
    ])->and($commandOutput)->toContain("incus launch 'orbit-blank-ubuntu-26.04' 'orbit-template-ingress'")
        ->and($commandOutput)->toContain("incus launch 'orbit-blank-ubuntu-26.04' 'orbit-template-ingress-prod'")
        ->and($commandOutput)->toContain("incus copy 'orbit-template-control/clean-operator_gateway' 'orbit-template-ingress-control'")
        ->and($commandOutput)->toContain("incus copy 'orbit-template-gateway/clean-operator_gateway' 'orbit-template-ingress-gateway'")
        ->and($commandOutput)->toContain('edge-1')
        ->and($commandOutput)->toContain('--role=ingress')
        ->and($commandOutput)->toContain('--role=app-prod')
        ->and($commandOutput)->toContain('--ingress=')
        ->and($commandOutput)->not->toContain('app-dev-1')
        ->and($commandOutput)->not->toContain('agent-1');
});

it('builds downstream small topology scaffold without websocket or s3 runtime roles', function (): void {
    $config = incusTopologyBuilderConfig();
    $commands = [];

    Process::fake([
        'wg genkey' => Process::result(output: "private-key\n"),
        'wg pubkey' => Process::result(output: "public-key\n"),
    ]);

    $host = m::mock(IncusHost::class, [$config])->makePartial();
    $host->shouldReceive('imageExists')->with($config->blankImage)->andReturn(true);
    $host->shouldReceive('instanceExists')->andReturn(false);
    $host->shouldReceive('waitForCloudInit')->times(6);
    $host->shouldReceive('stopInstance')->times(16)->andReturn(incusTopologyBuilderProcessResult());
    $host->shouldReceive('snapshotInstance')->times(16)->andReturn(incusTopologyBuilderProcessResult());
    $host->shouldReceive('run')->andReturnUsing(function (string $command, ?int $timeoutSeconds = null) use (&$commands): ProcessResult {
        $commands[] = $command;

        if (str_contains($command, 'docker exec wg-easy wg show wg0 public-key')) {
            return incusTopologyBuilderProcessResult("wg-easy-public-key\n");
        }

        if (str_starts_with($command, 'mktemp -d ')) {
            return incusTopologyBuilderProcessResult("/tmp/orbit-topology-builder-test\n");
        }

        if (str_contains($command, 'orbit-template-websocket')) {
            return incusTopologyBuilderProcessResult("10.201.0.15\n");
        }

        if (str_contains($command, 'orbit-template-s3')) {
            return incusTopologyBuilderProcessResult("10.201.0.16\n");
        }

        if (str_contains($command, 'orbit-template-appdev-ingress')) {
            return incusTopologyBuilderProcessResult("10.201.0.14\n");
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

    $manifest = $builder->build(E2ETopologyKind::OperatorGatewayAppdevIngressWebsocketS3);
    $commandOutput = implode("\n", $commands);

    expect($manifest)->toBe([
        [
            'role' => 'control',
            'name' => 'orbit-template-control',
            'snapshot' => 'clean-operator_gateway_app-dev_ingress_websocket_s3',
        ],
        [
            'role' => 'gateway',
            'name' => 'orbit-template-gateway',
            'snapshot' => 'clean-operator_gateway_app-dev_ingress_websocket_s3',
        ],
        [
            'role' => 'dev',
            'name' => 'orbit-template-dev',
            'snapshot' => 'clean-operator_gateway_app-dev_ingress_websocket_s3',
        ],
        [
            'role' => 'ingress',
            'name' => 'orbit-template-appdev-ingress',
            'snapshot' => 'clean-operator_gateway_app-dev_ingress_websocket_s3',
        ],
        [
            'role' => 'websocket',
            'name' => 'orbit-template-websocket',
            'snapshot' => 'clean-operator_gateway_app-dev_ingress_websocket_s3',
        ],
        [
            'role' => 's3',
            'name' => 'orbit-template-s3',
            'snapshot' => 'clean-operator_gateway_app-dev_ingress_websocket_s3',
        ],
    ])->and($commandOutput)->toContain("incus launch 'orbit-blank-ubuntu-26.04' 'orbit-template-websocket'")
        ->and($commandOutput)->toContain("incus launch 'orbit-blank-ubuntu-26.04' 'orbit-template-s3'")
        ->and($commandOutput)->toContain("incus launch 'orbit-blank-ubuntu-26.04' 'orbit-template-appdev-ingress'")
        ->and($commandOutput)->toContain('app-dev-1')
        ->and($commandOutput)->toContain('edge-1')
        ->and($commandOutput)->toContain('NodeRoleName::Database')
        ->and($commandOutput)->toContain('redis')
        ->and($commandOutput)->toContain('/tmp/orbit-e2e-bundle/e2e-provision-node')
        ->and($commandOutput)->not->toContain('--role=websocket')
        ->and($commandOutput)->not->toContain('--role=s3')
        ->and($commandOutput)->not->toContain('bake-websocket')
        ->and($commandOutput)->not->toContain('bake-s3')
        ->and($commandOutput)->not->toContain('reverb')
        ->and($commandOutput)->not->toContain('rustfs');
});
