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
        baseImage: 'orbit-base-ubuntu-26.04',
        bootstrapUser: 'provisioner',
        operatorUser: 'operator',
        instancePrefix: 'orbit-e2e',
        timeoutSeconds: 60,
        cpus: '2',
        memory: '2GiB',
        topologyCpus: '1',
        topologyMemory: '2GiB',
        topologyRootSize: '16GiB',
        topologyStateSize: '4GiB',
        incusStoragePool: '',
        dockerHosts: ['local'],
        keep: false,
    );
}

it('throws when the base image is missing', function (): void {
    $config = E2EConfig::fromEnvironment();

    $host = m::mock(IncusHost::class, [$config])->makePartial();
    $host->shouldReceive('imageExists')
        ->with($config->baseImage)
        ->andReturn(false);

    $builder = new IncusTopologyBuilder($host);
    $builder->useBundle('/tmp/orbit-e2e-bundle-test');

    expect(fn () => $builder->build(E2ETopologyKind::Operator))
        ->toThrow(RuntimeException::class, "Required base image [{$config->baseImage}] not found");
});

it('throws when no provisioning bundle has been staged', function (): void {
    $config = E2EConfig::fromEnvironment();

    $host = m::mock(IncusHost::class, [$config])->makePartial();
    $host->shouldReceive('imageExists')->andReturn(true);

    $builder = new IncusTopologyBuilder($host);

    expect(fn () => $builder->build(E2ETopologyKind::Operator))
        ->toThrow(RuntimeException::class, 'No provisioning bundle has been staged');
});

it('throws when a target template instance already exists', function (): void {
    $config = E2EConfig::fromEnvironment();

    $host = m::mock(IncusHost::class, [$config])->makePartial();
    $host->shouldReceive('imageExists')->andReturn(true);
    $host->shouldReceive('instanceExists')
        ->with('orbit-template-operator-base')
        ->andReturn(true);

    $builder = new IncusTopologyBuilder($host);
    $builder->useBundle('/tmp/orbit-e2e-bundle-test');

    expect(fn () => $builder->build(E2ETopologyKind::Operator))
        ->toThrow(RuntimeException::class, 'Template instance [orbit-template-operator-base] already exists');
});

it('deletes target template instances before replacing them', function (): void {
    $config = E2EConfig::fromEnvironment();

    $host = m::mock(IncusHost::class, [$config])->makePartial();
    $host->shouldReceive('imageExists')->andReturn(true);
    $host->shouldReceive('instanceExists')
        ->andReturnUsing(fn (string $name): bool => $name === 'orbit-template-operator-base');
    $host->shouldReceive('deleteInstance')
        ->with('orbit-template-operator-base')
        ->once()
        ->andReturn(incusTopologyBuilderProcessResult());
    $host->shouldReceive('run')
        ->with(m::on(fn (string $command): bool => str_starts_with($command, 'mktemp -d ')))
        ->andReturn(incusTopologyBuilderProcessResult(successful: false));

    $builder = new IncusTopologyBuilder($host);
    $builder->useBundle('/tmp/orbit-e2e-bundle-test');

    expect(fn () => $builder->build(E2ETopologyKind::Operator, replaceExisting: true))
        ->toThrow(RuntimeException::class, 'Could not create work directory');
});

it('does not delete unsuffixed Incus templates when replacing prepared topology artifacts', function (): void {
    withE2ETopologyEnvironment([], function (): void {
        $config = E2EConfig::fromEnvironment();
        $checked = [];
        $deleted = [];

        $host = m::mock(IncusHost::class, [$config])->makePartial();
        $host->shouldReceive('imageExists')->andReturn(true);
        $host->shouldReceive('instanceExists')
            ->andReturnUsing(function (string $name) use (&$checked): bool {
                $checked[] = $name;

                return str_starts_with($name, 'orbit-template-') && str_ends_with($name, '-base');
            });
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

        expect(fn () => $builder->build(E2ETopologyKind::Operator, replaceExisting: true))
            ->toThrow(RuntimeException::class, 'Could not create work directory')
            ->and($checked)->not->toContain('orbit-template-operator')
            ->and($deleted)->not->toContain('orbit-template-operator')
            ->and($deleted)->toContain('orbit-template-operator-base');
    });
});

it('rebuilds prerequisites when no complete reusable base exists', function (): void {
    $config = E2EConfig::fromEnvironment();
    $deleted = [];

    $existing = [
        'orbit-template-operator-base',
        'orbit-template-gateway-base',
        'orbit-template-app-dev-base',
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

    expect(fn () => $builder->build(E2ETopologyKind::OperatorGatewayAppdev, replaceExisting: true))
        ->toThrow(RuntimeException::class, 'Could not create work directory')
        ->and($deleted)->toBe(array_reverse($existing));
});

it('does not reuse an operator-gateway stage when rebuilding the prepared full topology', function (): void {
    $config = E2EConfig::fromEnvironment();
    $deleted = [];

    $existing = [
        'orbit-template-operator-base',
        'orbit-template-gateway-base',
        'orbit-template-app-dev-base',
        'orbit-template-app-prod-base',
        'orbit-template-agent-base',
    ];

    $baseSnapshots = [
        'orbit-template-operator-base:clean-operator_gateway-base',
        'orbit-template-gateway-base:clean-operator_gateway-base',
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
            'orbit-template-agent-base',
            'orbit-template-app-prod-base',
            'orbit-template-app-dev-base',
            'orbit-template-gateway-base',
            'orbit-template-operator-base',
        ]);
});

it('builds full prepared roles from the gateway base with parallel downstream baking', function (): void {
    withE2ETopologyEnvironment([], function (): void {
        $config = incusTopologyBuilderConfig();
        $commands = [];

        Process::fake([
            'wg genkey' => Process::result(output: "private-key\n"),
            'wg pubkey' => Process::result(output: "public-key\n"),
        ]);

        $host = m::mock(IncusHost::class, [$config])->makePartial();
        $host->shouldReceive('imageExists')->with($config->baseImage)->andReturn(true);
        $host->shouldReceive('instanceExists')->andReturn(false);
        $host->shouldReceive('waitForCloudInit')->times(5);
        $host->shouldReceive('provisionInstance')->with('orbit-template-operator-base', 'operator', '/tmp/orbit-e2e-bundle-test', 'operator')->once()->andReturn(incusTopologyBuilderProcessResult());
        $host->shouldReceive('provisionInstance')->with('orbit-template-gateway-base', 'gateway', '/tmp/orbit-e2e-bundle-test')->once()->andReturn(incusTopologyBuilderProcessResult());
        $host->shouldReceive('stopInstance')->times(8)->andReturn(incusTopologyBuilderProcessResult());
        $host->shouldReceive('snapshotInstance')->times(8)->andReturn(incusTopologyBuilderProcessResult());
        $host->shouldReceive('run')->andReturnUsing(function (string $command, ?int $timeoutSeconds = null) use (&$commands): ProcessResult {
            $commands[] = $command;

            if (str_contains($command, 'docker exec wg-easy wg show wg0 public-key')) {
                return incusTopologyBuilderProcessResult("wg-easy-public-key\n");
            }

            if (str_starts_with($command, 'mktemp -d ')) {
                return incusTopologyBuilderProcessResult("/tmp/orbit-topology-builder-test\n");
            }

            if (str_contains($command, 'orbit-template-agent-base')) {
                return incusTopologyBuilderProcessResult("10.201.0.14\n");
            }

            if (str_contains($command, 'orbit-template-app-prod-base')) {
                return incusTopologyBuilderProcessResult("10.201.0.13\n");
            }

            if (str_contains($command, 'orbit-template-app-dev-base')) {
                return incusTopologyBuilderProcessResult("10.201.0.12\n");
            }

            if (str_contains($command, 'orbit-template-gateway-base')) {
                return incusTopologyBuilderProcessResult("10.201.0.11\n");
            }

            if (str_contains($command, 'orbit-template-operator-base')) {
                return incusTopologyBuilderProcessResult("10.201.0.10\n");
            }

            return incusTopologyBuilderProcessResult();
        });

        $builder = new IncusTopologyBuilder($host);
        $builder->useBundle('/tmp/orbit-e2e-bundle-test');

        $manifest = $builder->build(E2ETopologyKind::OperatorGatewayAppdevAppprodAgent);
        $commandOutput = implode("\n", $commands);

        expect($manifest)->toHaveCount(5)
            ->and($manifest)->sequence(
                fn ($template) => $template->role->toBe('operator')->snapshot->toBe('clean-operator_gateway_app-dev_app-prod_agent-base'),
                fn ($template) => $template->role->toBe('gateway')->snapshot->toBe('clean-operator_gateway_app-dev_app-prod_agent-base'),
                fn ($template) => $template->role->toBe('dev')->snapshot->toBe('clean-operator_gateway_app-dev_app-prod_agent-base'),
                fn ($template) => $template->role->toBe('prod')->snapshot->toBe('clean-operator_gateway_app-dev_app-prod_agent-base'),
                fn ($template) => $template->role->toBe('agent')->snapshot->toBe('clean-operator_gateway_app-dev_app-prod_agent-base'),
            )
            ->and($commandOutput)->toContain("incus launch 'orbit-base-ubuntu-26.04' 'orbit-template-app-dev-base'")
            ->and($commandOutput)->toContain("incus launch 'orbit-base-ubuntu-26.04' 'orbit-template-app-prod-base'")
            ->and($commandOutput)->toContain("incus launch 'orbit-base-ubuntu-26.04' 'orbit-template-agent-base'")
            ->and($commandOutput)->toContain('PID_BAKE_DEV=$!')
            ->and($commandOutput)->toContain('PID_BAKE_PROD=$!')
            ->and($commandOutput)->toContain('PID_BAKE_AGENT=$!')
            ->and($commandOutput)->toContain('set -euo pipefail;')
            ->and($commandOutput)->toContain('PID_BAKE_DEV=$!;')
            ->and($commandOutput)->toContain("incus exec 'orbit-template-gateway-base' -- sh -lc 'sudo -iu orbit")
            ->and($commandOutput)->toContain('php apps/gateway/artisan tinker --execute=')
            ->and($commandOutput)->not->toContain('cd /home/orbit/orbit && php artisan')
            ->and($commandOutput)->toContain('php apps/gateway/artisan orbit:internal:bake-app-node')
            ->and($commandOutput)->toContain('php apps/gateway/artisan orbit:internal:bake-ingress-node')
            ->and($commandOutput)->toContain('php apps/gateway/artisan orbit:internal:bake-agent-node')
            ->and($commandOutput)->toContain('app-dev-1')
            ->and($commandOutput)->toContain('app-prod-1')
            ->and($commandOutput)->toContain('agent-1')
            ->and($commandOutput)->toContain('/tmp/orbit-e2e-prepared-bake.sh')
            ->and(substr_count($commandOutput, 'ORBIT_E2E_NODE_WIREGUARD_ADDRESS='))->toBe(0)
            ->and($commandOutput)->toContain('prepared Incus base image is missing E2E dependencies')
            ->and($commandOutput)->toContain('for command in composer git supervisorctl wg wg-quick dig ufw; do')
            ->and($commandOutput)->not->toContain('apt-get')
            ->and($commandOutput)->toContain('systemctl enable --now supervisor.service');
        expect(substr_count($commandOutput, 'orbit-template-gateway-base/root/.ssh/id_ed25519'))->toBe(2);
    });
});

it('rebuilds app production ingress through the prepared prod template', function (): void {
    $config = E2EConfig::fromEnvironment();
    $deleted = [];

    $existing = [
        'orbit-template-operator-base',
        'orbit-template-gateway-base',
        'orbit-template-app-prod-base',
        'orbit-template-operator_gateway_app-prod_ingress-operator-base',
        'orbit-template-operator_gateway_app-prod_ingress-gateway-base',
        'orbit-template-operator_gateway_app-prod_ingress-prod-base',
        'orbit-template-ingress-prod',
        'orbit-template-ingress',
    ];

    $host = m::mock(IncusHost::class, [$config])->makePartial();
    $host->shouldReceive('imageExists')->andReturn(true);
    $host->shouldReceive('instanceExists')
        ->andReturnUsing(fn (string $name): bool => in_array($name, $existing, true));
    $host->shouldReceive('snapshotExists')
        ->andReturnUsing(fn (string $name, string $snapshot): bool => in_array($name, ['orbit-template-operator-base', 'orbit-template-gateway-base'], true)
            && $snapshot === 'clean-operator_gateway-base');
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
        ->and($deleted)->toContain('orbit-template-app-prod-base')
        ->and($deleted)->toContain('orbit-template-operator_gateway_app-prod_ingress-operator-base')
        ->and($deleted)->toContain('orbit-template-operator_gateway_app-prod_ingress-gateway-base')
        ->and($deleted)->toContain('orbit-template-operator_gateway_app-prod_ingress-prod-base')
        ->and($deleted)->not->toContain('orbit-template-ingress-prod')
        ->and($deleted)->not->toContain('orbit-template-ingress');
});

it('restores a reusable base stage before continuing a force rebuild', function (): void {
    $config = E2EConfig::fromEnvironment();
    $deletedSnapshots = [];

    $host = m::mock(IncusHost::class, [$config])->makePartial();
    $host->shouldReceive('imageExists')->andReturn(true);
    $host->shouldReceive('instanceExists')
        ->andReturnUsing(fn (string $name): bool => $name === 'orbit-template-operator-base');
    $host->shouldReceive('snapshotExists')
        ->with('orbit-template-operator-base', 'clean-operator-base')
        ->andReturn(true);
    $host->shouldReceive('deleteInstance')->never();
    $host->shouldReceive('deleteSnapshot')
        ->andReturnUsing(function (string $name, string $snapshot) use (&$deletedSnapshots): ProcessResult {
            $deletedSnapshots[] = "{$name}:{$snapshot}";

            return incusTopologyBuilderProcessResult();
        });
    $host->shouldReceive('stopInstancesIfRunning')
        ->with(['orbit-template-operator-base'])
        ->once()
        ->andReturn(incusTopologyBuilderProcessResult());
    $host->shouldReceive('restoreSnapshotsConcurrently')
        ->with(['orbit-template-operator-base'], 'clean-operator-base')
        ->once()
        ->andReturn(incusTopologyBuilderProcessResult());
    $host->shouldReceive('startInstance')
        ->once()
        ->andReturnUsing(function (string $name): ProcessResult {
            expect($name)->toBe('orbit-template-operator-base');

            return incusTopologyBuilderProcessResult(errorOutput: 'start failed', successful: false);
        });
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
        ->toThrow(RuntimeException::class, 'Could not start orbit-template-operator-base: start failed')
        ->and($deletedSnapshots)->toContain('orbit-template-operator-base:clean-operator_gateway-base')
        ->and($deletedSnapshots)->toContain('orbit-template-operator-base:clean-operator_gateway_app-dev_app-prod_agent-base');
});

it('records phase timings while building topology templates', function (): void {
    $config = incusTopologyBuilderConfig();
    $timer = new E2EPhaseTimer;

    $host = m::mock(IncusHost::class, [$config])->makePartial();
    $host->shouldReceive('imageExists')->with($config->baseImage)->andReturn(true);
    $host->shouldReceive('instanceExists')->with('orbit-template-operator-base')->andReturn(false);
    $host->shouldReceive('waitForCloudInit')->with('orbit-template-operator-base')->once();
    $host->shouldReceive('provisionInstance')
        ->with('orbit-template-operator-base', 'operator', '/tmp/orbit-e2e-bundle-test', 'operator')
        ->once()
        ->andReturn(incusTopologyBuilderProcessResult());
    $host->shouldReceive('stopInstance')->with('orbit-template-operator-base')->once()->andReturn(incusTopologyBuilderProcessResult());
    $host->shouldReceive('snapshotInstance')->with('orbit-template-operator-base', 'clean-operator-base')->once()->andReturn(incusTopologyBuilderProcessResult());
    $host->shouldReceive('run')->andReturnUsing(function (string $command, ?int $timeoutSeconds = null): ProcessResult {
        if (str_starts_with($command, 'mktemp -d ')) {
            return incusTopologyBuilderProcessResult("/tmp/orbit-topology-builder-test\n");
        }

        if (str_contains($command, 'orbit-template-operator-base')) {
            return incusTopologyBuilderProcessResult("10.201.0.10\n");
        }

        return incusTopologyBuilderProcessResult();
    });

    $builder = new IncusTopologyBuilder($host, $timer);
    $builder->useBundle('/tmp/orbit-e2e-bundle-test');

    $builder->build(E2ETopologyKind::Operator);

    $eventNames = array_column($timer->events(), 'name');

    expect($eventNames)->toContain('preflight')
        ->and($eventNames)->toContain('workdir')
        ->and($eventNames)->toContain('ssh-key')
        ->and($eventNames)->toContain('operator.launch')
        ->and($eventNames)->toContain('operator.cloud-init')
        ->and($eventNames)->toContain('operator.provision')
        ->and($eventNames)->toContain('operator.provisioning-ssh-key')
        ->and($eventNames)->toContain('operator.identity')
        ->and($eventNames)->toContain('finalize.stop.operator')
        ->and($eventNames)->toContain('finalize.snapshot.operator')
        ->and($eventNames)->toContain('workdir.cleanup');
});

it('builds prepared topology templates through staged internal gateway baking', function (): void {
    $config = incusTopologyBuilderConfig();
    $commands = [];

    Process::fake([
        'wg genkey' => Process::result(output: "private-key\n"),
        'wg pubkey' => Process::result(output: "public-key\n"),
    ]);

    $host = m::mock(IncusHost::class, [$config])->makePartial();
    $host->shouldReceive('imageExists')->with($config->baseImage)->andReturn(true);
    $host->shouldReceive('instanceExists')->andReturn(false);
    $host->shouldReceive('waitForCloudInit')->times(5);
    $host->shouldReceive('provisionInstance')->with('orbit-template-operator-base', 'operator', '/tmp/orbit-e2e-bundle-test', 'operator')->once()->andReturn(incusTopologyBuilderProcessResult());
    $host->shouldReceive('stopInstance')->times(8)->andReturn(incusTopologyBuilderProcessResult());
    $host->shouldReceive('snapshotInstance')->times(8)->andReturn(incusTopologyBuilderProcessResult());
    $host->shouldReceive('run')->andReturnUsing(function (string $command, ?int $timeoutSeconds = null) use (&$commands): ProcessResult {
        $commands[] = $command;

        if (str_contains($command, 'docker exec wg-easy wg show wg0 public-key')) {
            return incusTopologyBuilderProcessResult("wg-easy-public-key\n");
        }

        if (str_starts_with($command, 'mktemp -d ')) {
            return incusTopologyBuilderProcessResult("/tmp/orbit-topology-builder-test\n");
        }

        if (str_contains($command, 'orbit-template-app-prod-base')) {
            return incusTopologyBuilderProcessResult("10.201.0.13\n");
        }

        if (str_contains($command, 'orbit-template-app-dev-base')) {
            return incusTopologyBuilderProcessResult("10.201.0.12\n");
        }

        if (str_contains($command, 'orbit-template-agent-base')) {
            return incusTopologyBuilderProcessResult("10.201.0.14\n");
        }

        if (str_contains($command, 'orbit-template-gateway-base')) {
            return incusTopologyBuilderProcessResult("10.201.0.11\n");
        }

        if (str_contains($command, 'orbit-template-operator-base')) {
            return incusTopologyBuilderProcessResult("10.201.0.10\n");
        }

        return incusTopologyBuilderProcessResult();
    });

    $builder = new IncusTopologyBuilder($host);
    $builder->useBundle('/tmp/orbit-e2e-bundle-test');

    $manifest = $builder->build(E2ETopologyKind::OperatorGatewayAppdevAppprodAgent);

    $commandOutput = implode("\n", $commands);

    expect($manifest)->toBe([
        [
            'role' => 'operator',
            'name' => 'orbit-template-operator-base',
            'snapshot' => 'clean-operator_gateway_app-dev_app-prod_agent-base',
        ],
        [
            'role' => 'gateway',
            'name' => 'orbit-template-gateway-base',
            'snapshot' => 'clean-operator_gateway_app-dev_app-prod_agent-base',
        ],
        [
            'role' => 'dev',
            'name' => 'orbit-template-app-dev-base',
            'snapshot' => 'clean-operator_gateway_app-dev_app-prod_agent-base',
        ],
        [
            'role' => 'prod',
            'name' => 'orbit-template-app-prod-base',
            'snapshot' => 'clean-operator_gateway_app-dev_app-prod_agent-base',
        ],
        [
            'role' => 'agent',
            'name' => 'orbit-template-agent-base',
            'snapshot' => 'clean-operator_gateway_app-dev_app-prod_agent-base',
        ],
    ])->and($commandOutput)->toContain("incus launch 'orbit-base-ubuntu-26.04' 'orbit-template-operator-base'")
        ->and($commandOutput)->toContain("incus launch 'orbit-base-ubuntu-26.04' 'orbit-template-gateway-base'")
        ->and($commandOutput)->toContain("incus launch 'orbit-base-ubuntu-26.04' 'orbit-template-app-dev-base'")
        ->and($commandOutput)->toContain("incus launch 'orbit-base-ubuntu-26.04' 'orbit-template-app-prod-base'")
        ->and($commandOutput)->toContain("incus launch 'orbit-base-ubuntu-26.04' 'orbit-template-agent-base'")
        ->and($commandOutput)->not->toContain('orbit-template-operator_gateway_app-dev_app-prod-operator-base')
        ->and($commandOutput)->not->toContain('orbit node:new gateway-1')
        ->and($commandOutput)->not->toContain('--role=gateway')
        ->and($commandOutput)->not->toContain('--operator-name=operator-1')
        ->and($commandOutput)->toContain('/var/tmp/orbit-e2e-bundle/e2e-provision-node')
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
        ->and($commandOutput)->toContain('php apps/gateway/artisan orbit:internal:bootstrap-gateway-local gateway')
        ->and($commandOutput)->toContain('php apps/gateway/artisan tinker --execute=')
        ->and($commandOutput)->not->toContain('cd /home/orbit/orbit && php artisan')
        ->and($commandOutput)->toContain('app-dev-1')
        ->and($commandOutput)->toContain('10.201.0.12')
        ->and($commandOutput)->toContain('--user=')
        ->and($commandOutput)->toContain('provisioner')
        ->and($commandOutput)->toContain('app-prod-1')
        ->and($commandOutput)->toContain('10.201.0.13')
        ->and($commandOutput)->toContain('php apps/gateway/artisan orbit:internal:bake-app-node')
        ->and($commandOutput)->toContain('php apps/gateway/artisan orbit:internal:bake-ingress-node')
        ->and($commandOutput)->toContain('php apps/gateway/artisan orbit:internal:bake-agent-node')
        ->and($commandOutput)->toContain('--role=app-dev')
        ->and($commandOutput)->toContain('--role=app-prod')
        ->and($commandOutput)->toContain('--ingress-node=')
        ->and($commandOutput)->not->toContain('/tmp/orbit-e2e-prepared-node-new.sh')
        ->and($commandOutput)->not->toContain('ORBIT_E2E_NODE_WIREGUARD_ADDRESS=')
        ->and($commandOutput)->not->toContain('--roles=app-prod,ingress');
});

it('builds app production ingress on the prod template without development or agent stages', function (): void {
    $config = incusTopologyBuilderConfig();
    $commands = [];

    Process::fake([
        'wg genkey' => Process::result(output: "private-key\n"),
        'wg pubkey' => Process::result(output: "public-key\n"),
    ]);

    $host = m::mock(IncusHost::class, [$config])->makePartial();
    $host->shouldReceive('imageExists')->with($config->baseImage)->andReturn(true);
    $host->shouldReceive('instanceExists')->andReturn(false);
    $host->shouldReceive('waitForCloudInit')->times(3);
    $host->shouldReceive('provisionInstance')->with('orbit-template-operator-base', 'operator', '/tmp/orbit-e2e-bundle-test', 'operator')->once()->andReturn(incusTopologyBuilderProcessResult());
    $host->shouldReceive('stopInstance')->times(6)->andReturn(incusTopologyBuilderProcessResult());
    $host->shouldReceive('snapshotInstance')->times(6)->andReturn(incusTopologyBuilderProcessResult());
    $host->shouldReceive('run')->andReturnUsing(function (string $command, ?int $timeoutSeconds = null) use (&$commands): ProcessResult {
        $commands[] = $command;

        if (str_contains($command, 'docker exec wg-easy wg show wg0 public-key')) {
            return incusTopologyBuilderProcessResult("wg-easy-public-key\n");
        }

        if (str_starts_with($command, 'mktemp -d ')) {
            return incusTopologyBuilderProcessResult("/tmp/orbit-topology-builder-test\n");
        }

        if (str_contains($command, 'orbit-template-app-prod-base')) {
            return incusTopologyBuilderProcessResult("10.201.0.13\n");
        }

        if (str_contains($command, 'orbit-template-gateway-base')) {
            return incusTopologyBuilderProcessResult("10.201.0.11\n");
        }

        if (str_contains($command, 'orbit-template-operator-base')) {
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
            'role' => 'operator',
            'name' => 'orbit-template-operator-base',
            'snapshot' => 'clean-operator_gateway_app-prod_ingress-base',
        ],
        [
            'role' => 'gateway',
            'name' => 'orbit-template-gateway-base',
            'snapshot' => 'clean-operator_gateway_app-prod_ingress-base',
        ],
        [
            'role' => 'prod',
            'name' => 'orbit-template-app-prod-base',
            'snapshot' => 'clean-operator_gateway_app-prod_ingress-base',
        ],
    ])->and($commandOutput)->toContain("incus launch 'orbit-base-ubuntu-26.04' 'orbit-template-app-prod-base'")
        ->and($commandOutput)->not->toContain("incus launch 'orbit-base-ubuntu-26.04' 'orbit-template-ingress-base'")
        ->and($commandOutput)->not->toContain("incus copy 'orbit-template-operator-base/clean-operator_gateway-base'")
        ->and($commandOutput)->not->toContain('edge-1')
        ->and($commandOutput)->toContain('--roles=app-prod,ingress')
        ->and($commandOutput)->not->toContain('--ingress=')
        ->and($commandOutput)->not->toContain('app-dev-1')
        ->and($commandOutput)->not->toContain('agent-1');
});
