<?php

declare(strict_types=1);

use App\E2E\Support\E2EConfig;
use App\E2E\Support\E2EPhaseTimer;
use App\E2E\Support\E2EProvisionCheckpointManifest;
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

function incusTopologyBuilderPreparedBakeResult(string $command): ?ProcessResult
{
    if (str_contains($command, 'nohup sh -lc')) {
        return incusTopologyBuilderProcessResult();
    }

    if (str_contains($command, '/tmp/orbit-e2e-prepared-bake.status') && ! str_contains($command, 'cat >')) {
        return incusTopologyBuilderProcessResult(implode("\n", [
            '__orbit_bake_status dev 0',
            '__orbit_bake_status prod 0',
            '__orbit_bake_status agent 0',
            '__orbit_bake_status websocket 0',
            '',
        ]));
    }

    if (str_contains($command, '/tmp/orbit-e2e-prepared-bake.done') && ! str_contains($command, 'cat >')) {
        return incusTopologyBuilderProcessResult(implode("\n", [
            '__orbit_bake_status dev 0',
            '__orbit_bake_status prod 0',
            '__orbit_bake_status agent 0',
            '__orbit_bake_status websocket 0',
            '',
        ]));
    }

    if (! str_contains($command, '/tmp/orbit-e2e-prepared-bake.sh')) {
        return null;
    }

    if (str_contains($command, 'cat >')) {
        return null;
    }

    return incusTopologyBuilderProcessResult(implode("\n", [
        '__orbit_bake_status dev 0',
        '__orbit_bake_status prod 0',
        '__orbit_bake_status agent 0',
        '__orbit_bake_status websocket 0',
        '',
    ]));
}

function incusTopologyBuilderPreparedRoleResult(string $command): ?ProcessResult
{
    if (! str_contains($command, '/tmp/orbit-e2e-prepared-downstream-roles.sh')) {
        return null;
    }

    if (str_contains($command, 'cat >')) {
        return null;
    }

    return incusTopologyBuilderProcessResult(implode("\n", [
        '__orbit_prepare_status dev 0',
        '__orbit_prepare_status prod 0',
        '__orbit_prepare_status agent 0',
        '',
    ]));
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

it('does not erase trusted gateway CA config when switching the operator to the WireGuard URL', function (): void {
    $source = file_get_contents(repo_path('apps/e2e/app/E2E/Support/IncusTopologyBuilder.php'));

    expect($source)->toBeString();

    preg_match('/private function useWireGuardGatewayUrl\(.*?private function writeOperatorCliConfig/s', (string) $source, $matches);

    expect($matches[0] ?? '')
        ->toContain('local_gateway_settings')
        ->toContain('gateway_url')
        ->not->toContain('writeOperatorCliConfig($operator, $key)');
});

it('does not seed database and redis fixture state in the plain app-dev provisioning stage', function (): void {
    $source = file_get_contents(repo_path('apps/e2e/app/E2E/Support/IncusTopologyBuilder.php'));

    expect($source)->toBeString();

    preg_match('/private function buildDevelopmentAppStage\(.*?private function buildProductionAppStage/s', (string) $source, $matches);

    expect($matches[0] ?? '')
        ->toContain('dev.node-new')
        ->not->toContain('seedAppdevDatabaseAndRedis');
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
        $host->shouldReceive('waitForCloudInit')->times(2);
        $host->shouldReceive('provisionInstance')->with('orbit-template-operator-base', 'operator', '/tmp/orbit-e2e-bundle-test', 'operator')->once()->andReturn(incusTopologyBuilderProcessResult());
        $host->shouldReceive('provisionInstance')->with('orbit-template-gateway-base', 'gateway', '/tmp/orbit-e2e-bundle-test')->once()->andReturn(incusTopologyBuilderProcessResult());
        $host->shouldReceive('stopInstance')->times(8)->andReturn(incusTopologyBuilderProcessResult());
        $host->shouldReceive('snapshotInstance')->times(8)->andReturn(incusTopologyBuilderProcessResult());
        $host->shouldReceive('run')->andReturnUsing(function (string $command, ?int $timeoutSeconds = null) use (&$commands): ProcessResult {
            $commands[] = $command;

            if (($result = incusTopologyBuilderPreparedBakeResult($command)) !== null) {
                return $result;
            }

            if (($result = incusTopologyBuilderPreparedRoleResult($command)) !== null) {
                return $result;
            }

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

        $timer = new E2EPhaseTimer;
        $builder = new IncusTopologyBuilder($host, $timer);
        $builder->useBundle('/tmp/orbit-e2e-bundle-test');

        $manifest = $builder->build(E2ETopologyKind::OperatorGatewayAppdevAppprodAgent);
        $commandOutput = implode("\n", $commands);
        $phaseNames = array_column($timer->events(), 'name');
        $runtimePrerequisitesPhase = array_search('prepared.dev.runtime-prerequisites', $phaseNames, true);
        $redisSeedPhase = array_search('dev.database-redis-seed', $phaseNames, true);
        $runnerStartPosition = strpos($commandOutput, 'nohup sh -lc');
        $runtimePrerequisiteCommandPosition = strpos($commandOutput, 'sudo -u "$runtime_user" docker image inspect');
        $developmentReadyWaitPosition = strpos($commandOutput, 'while [ ! -f "$DEV_READY_MARKER" ]; do');
        $developmentBakePosition = strpos($commandOutput, '--role=app-dev');
        $productionBakePosition = strpos($commandOutput, 'orbit:internal:bake-ingress-node');
        $agentBakePosition = strpos($commandOutput, 'orbit:internal:bake-agent-node');

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
            ->and($commandOutput)->toContain('/tmp/orbit-e2e-prepared-downstream-roles.sh')
            ->and($commandOutput)->toContain('PID_PREPARE_DEV=$!')
            ->and($commandOutput)->toContain('PID_PREPARE_PROD=$!')
            ->and($commandOutput)->toContain('PID_PREPARE_AGENT=$!')
            ->and($commandOutput)->toContain('wait "$PID_PREPARE_DEV"')
            ->and($commandOutput)->toContain('wait "$PID_PREPARE_PROD"')
            ->and($commandOutput)->toContain('wait "$PID_PREPARE_AGENT"')
            ->and($commandOutput)->toContain('PID_BAKE_DEV=$!')
            ->and($commandOutput)->toContain('PID_BAKE_PROD=$!')
            ->and($commandOutput)->toContain('PID_BAKE_AGENT=$!')
            ->and($commandOutput)->toContain('set -euo pipefail;')
            ->and($commandOutput)->toContain('PID_BAKE_DEV=$!;')
            ->and($commandOutput)->toContain("incus exec 'orbit-template-gateway-base' -- sh -lc")
            ->and($runnerStartPosition)->toBeInt()
            ->and($runtimePrerequisiteCommandPosition)->toBeInt()
            ->and($runnerStartPosition)->toBeLessThan($runtimePrerequisiteCommandPosition)
            ->and($productionBakePosition)->toBeInt()
            ->and($agentBakePosition)->toBeInt()
            ->and($developmentReadyWaitPosition)->toBeInt()
            ->and($developmentBakePosition)->toBeInt()
            ->and($productionBakePosition)->toBeLessThan($developmentReadyWaitPosition)
            ->and($agentBakePosition)->toBeLessThan($developmentReadyWaitPosition)
            ->and($developmentReadyWaitPosition)->toBeLessThan($developmentBakePosition)
            ->and($commandOutput)->toContain('orbit-gateway:prepared-current')
            ->and($commandOutput)->toContain('artisan tinker --execute=')
            ->and($commandOutput)->not->toContain('cd /home/orbit/orbit && php artisan')
            ->and($commandOutput)->not->toContain('cd /home/orbit/orbit && php apps/gateway/artisan orbit:internal:bake')
            ->and($commandOutput)->toContain('artisan orbit:internal:bake-app-node')
            ->and($commandOutput)->toContain('artisan orbit:internal:bake-ingress-node')
            ->and($commandOutput)->toContain('artisan orbit:internal:bake-agent-node')
            ->and($commandOutput)->toContain('app-dev-1')
            ->and($commandOutput)->toContain('app-prod-1')
            ->and($commandOutput)->toContain('agent-1')
            ->and($commandOutput)->toContain('/tmp/orbit-e2e-prepared-bake.sh')
            ->and($commandOutput)->toContain('caddy-2-alpine.tar')
            ->and($commandOutput)->toContain('frankenphp-1-php8.5-bookworm.tar')
            ->and($commandOutput)->toContain('caddy:2-alpine')
            ->and($commandOutput)->toContain('dunglas/frankenphp:1-php8.5-bookworm')
            ->and($commandOutput)->toContain('docker.io')
            ->and($commandOutput)->toContain('sudo -u "$bootstrap_user" docker image inspect')
            ->and($commandOutput)->toContain('runtime_user=orbit')
            ->and($commandOutput)->toContain('usermod -aG docker "$runtime_user"')
            ->and($commandOutput)->toContain('sudo -u "$runtime_user" docker image inspect')
            ->and($commandOutput)->not->toContain('orbit-template-app-dev-base/var/tmp/orbit-gateway-current.tar')
            ->and(substr_count($commandOutput, 'ORBIT_E2E_NODE_WIREGUARD_ADDRESS='))->toBe(0)
            ->and($commandOutput)->toContain('prepared Incus base image is missing E2E dependencies')
            ->and($commandOutput)->toContain('for command in composer git supervisorctl wg wg-quick dig ufw; do')
            ->and($commandOutput)->toContain('apt-get -o DPkg::Lock::Timeout=300 install -y -qq docker.io')
            ->and($commandOutput)->toContain('systemctl enable --now supervisor.service')
            ->and($runtimePrerequisitesPhase)->toBeInt()
            ->and($redisSeedPhase)->toBeInt()
            ->and($runtimePrerequisitesPhase)->toBeLessThan($redisSeedPhase);
        expect(substr_count($commandOutput, 'orbit-template-gateway-base/root/.ssh/id_ed25519'))->toBe(2);
    });
});

it('builds full prepared websocket roles on the app-dev node', function (): void {
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
        $host->shouldReceive('waitForCloudInit')->times(2);
        $host->shouldReceive('provisionInstance')->with('orbit-template-operator-base', 'operator', '/tmp/orbit-e2e-bundle-test', 'operator')->once()->andReturn(incusTopologyBuilderProcessResult());
        $host->shouldReceive('provisionInstance')->with('orbit-template-gateway-base', 'gateway', '/tmp/orbit-e2e-bundle-test')->once()->andReturn(incusTopologyBuilderProcessResult());
        $host->shouldReceive('stopInstance')->times(8)->andReturn(incusTopologyBuilderProcessResult());
        $host->shouldReceive('snapshotInstance')->times(8)->andReturn(incusTopologyBuilderProcessResult());
        $host->shouldReceive('run')->andReturnUsing(function (string $command, ?int $timeoutSeconds = null) use (&$commands): ProcessResult {
            $commands[] = $command;

            if (($result = incusTopologyBuilderPreparedBakeResult($command)) !== null) {
                return $result;
            }

            if (($result = incusTopologyBuilderPreparedRoleResult($command)) !== null) {
                return $result;
            }

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

        $timer = new E2EPhaseTimer;
        $builder = new IncusTopologyBuilder($host, $timer);
        $builder->useBundle('/tmp/orbit-e2e-bundle-test');

        $manifest = $builder->build(E2ETopologyKind::OperatorGatewayAppdevAppprodAgentWebsocket);
        $commandOutput = implode("\n", $commands);
        $phaseNames = array_column($timer->events(), 'name');
        $realWireGuardPhase = array_search('prepared-websocket.real-wireguard', $phaseNames, true);
        $runtimePrerequisitesPhase = array_search('prepared-websocket.dev.runtime-prerequisites', $phaseNames, true);
        $developmentWireGuardPhase = array_search('prepared-websocket.dev.real-wireguard', $phaseNames, true);
        $websocketBakePhase = array_search('prepared-websocket.websocket.bake', $phaseNames, true);
        $runnerStartPosition = strpos($commandOutput, 'nohup sh -lc');
        $runtimePrerequisiteCommandPosition = strpos($commandOutput, 'sudo -u "$runtime_user" docker image inspect');
        $developmentReadyWaitPosition = strpos($commandOutput, 'while [ ! -f "$DEV_READY_MARKER" ]; do');
        $developmentBakePosition = strpos($commandOutput, '--role=app-dev');
        $productionBakePosition = strpos($commandOutput, 'orbit:internal:bake-ingress-node');
        $agentBakePosition = strpos($commandOutput, 'orbit:internal:bake-agent-node');
        $devWaitPosition = strpos($commandOutput, 'wait "$PID_BAKE_DEV"');
        $devStatusPosition = $devWaitPosition === false ? false : strpos($commandOutput, '"$STATUS_DEV";', $devWaitPosition);
        $seedWaitPosition = strpos($commandOutput, 'until [ -f "$SEED_MARKER" ]; do');

        expect($manifest)->toHaveCount(5)
            ->and($manifest)->sequence(
                fn ($template) => $template->role->toBe('operator')->snapshot->toBe('clean-operator_gateway_app-dev_app-prod_agent_websocket-base'),
                fn ($template) => $template->role->toBe('gateway')->snapshot->toBe('clean-operator_gateway_app-dev_app-prod_agent_websocket-base'),
                fn ($template) => $template->role->toBe('dev')->snapshot->toBe('clean-operator_gateway_app-dev_app-prod_agent_websocket-base'),
                fn ($template) => $template->role->toBe('prod')->snapshot->toBe('clean-operator_gateway_app-dev_app-prod_agent_websocket-base'),
                fn ($template) => $template->role->toBe('agent')->snapshot->toBe('clean-operator_gateway_app-dev_app-prod_agent_websocket-base'),
            )
            ->and($commandOutput)->not->toContain("incus launch 'orbit-base-ubuntu-26.04' 'orbit-template-websocket-base'")
            ->and($commandOutput)->toContain('orbit-gateway:prepared-current')
            ->and($commandOutput)->toContain('artisan orbit:internal:bake-websocket-node')
            ->and($commandOutput)->toContain('PID_BAKE_WEBSOCKET=$!')
            ->and($runnerStartPosition)->toBeInt()
            ->and($runtimePrerequisiteCommandPosition)->toBeInt()
            ->and($runnerStartPosition)->toBeLessThan($runtimePrerequisiteCommandPosition)
            ->and($productionBakePosition)->toBeInt()
            ->and($agentBakePosition)->toBeInt()
            ->and($developmentReadyWaitPosition)->toBeInt()
            ->and($developmentBakePosition)->toBeInt()
            ->and($productionBakePosition)->toBeLessThan($developmentReadyWaitPosition)
            ->and($agentBakePosition)->toBeLessThan($developmentReadyWaitPosition)
            ->and($developmentReadyWaitPosition)->toBeLessThan($developmentBakePosition)
            ->and($devWaitPosition)->toBeLessThan(strpos($commandOutput, 'orbit:internal:bake-websocket-node'))
            ->and($devStatusPosition)->toBeInt()
            ->and($seedWaitPosition)->toBeInt()
            ->and($devStatusPosition)->toBeLessThan($seedWaitPosition)
            ->and(strpos($commandOutput, 'orbit:internal:bake-websocket-node'))->toBeLessThan(strpos($commandOutput, 'wait "$PID_BAKE_PROD"'))
            ->and(strpos($commandOutput, 'orbit:internal:bake-websocket-node'))->toBeLessThan(strpos($commandOutput, 'wait "$PID_BAKE_AGENT"'))
            ->and($commandOutput)->not->toContain('cd /home/orbit/orbit && php apps/gateway/artisan orbit:internal:bake-websocket-node')
            ->and($commandOutput)->toContain('app-dev-1')
            ->and($commandOutput)->toContain('10.201.0.12')
            ->and($commandOutput)->toContain('--wireguard-address=')
            ->and($commandOutput)->toContain('10.6.0.4')
            ->and($commandOutput)->toContain('--redis-node=')
            ->and($commandOutput)->toContain('app-dev-1')
            ->and($commandOutput)->toContain('incus file push')
            ->and($commandOutput)->toContain('orbit-gateway-current.tar')
            ->and($commandOutput)->toContain('caddy-2-alpine.tar')
            ->and($commandOutput)->toContain('frankenphp-1-php8.5-bookworm.tar')
            ->and($commandOutput)->toContain('caddy:2-alpine')
            ->and($commandOutput)->toContain('dunglas/frankenphp:1-php8.5-bookworm')
            ->and($commandOutput)->toContain('docker.io')
            ->and($commandOutput)->toContain('bootstrap_user=')
            ->and($commandOutput)->toContain('provisioner')
            ->and($commandOutput)->toContain('docker load -i')
            ->and($commandOutput)->toContain('docker tag')
            ->and($commandOutput)->toContain('orbit-gateway:prepared-current')
            ->and($commandOutput)->toContain('orbit-gateway:current')
            ->and($commandOutput)->toContain('usermod -aG docker "$bootstrap_user"')
            ->and($commandOutput)->toContain('runtime_user=orbit')
            ->and($commandOutput)->toContain('usermod -aG docker "$runtime_user"')
            ->and($commandOutput)->toContain('sudo -u "$bootstrap_user" docker image inspect')
            ->and($commandOutput)->toContain('sudo -u "$runtime_user" docker image inspect')
            ->and($commandOutput)->toContain('--converge-runtime')
            ->and($commandOutput)->not->toContain('--environment=')
            ->and($commandOutput)->not->toContain('node.role')
            ->and($realWireGuardPhase)->toBeInt()
            ->and($runtimePrerequisitesPhase)->toBeInt()
            ->and($developmentWireGuardPhase)->toBeInt()
            ->and($websocketBakePhase)->toBeInt()
            ->and($runtimePrerequisitesPhase)->toBeLessThan($realWireGuardPhase)
            ->and($runtimePrerequisitesPhase)->toBeLessThan($developmentWireGuardPhase)
            ->and($developmentWireGuardPhase)->toBeLessThan($websocketBakePhase);
    });
});

it('keeps successful app-dev agent and websocket checkpoints when app production bake fails', function (): void {
    withE2ETopologyEnvironment([], function (): void {
        $config = incusTopologyBuilderConfig();
        $commands = [];
        $snapshots = [];

        Process::fake([
            'wg genkey' => Process::result(output: "private-key\n"),
            'wg pubkey' => Process::result(output: "public-key\n"),
        ]);

        $host = m::mock(IncusHost::class, [$config])->makePartial();
        $host->shouldReceive('imageExists')->with($config->baseImage)->andReturn(true);
        $host->shouldReceive('instanceExists')->andReturn(false);
        $host->shouldReceive('waitForCloudInit')->zeroOrMoreTimes();
        $host->shouldReceive('provisionInstance')->with('orbit-template-operator-base', 'operator', '/tmp/orbit-e2e-bundle-test', 'operator')->once()->andReturn(incusTopologyBuilderProcessResult());
        $host->shouldReceive('provisionInstance')->with('orbit-template-gateway-base', 'gateway', '/tmp/orbit-e2e-bundle-test')->once()->andReturn(incusTopologyBuilderProcessResult());
        $host->shouldReceive('stopInstance')->zeroOrMoreTimes()->andReturn(incusTopologyBuilderProcessResult());
        $host->shouldReceive('snapshotInstance')
            ->zeroOrMoreTimes()
            ->andReturnUsing(function (string $name, string $snapshot) use (&$snapshots): ProcessResult {
                $snapshots[] = "{$name}:{$snapshot}";

                return incusTopologyBuilderProcessResult();
            });
        $host->shouldReceive('run')->andReturnUsing(function (string $command, ?int $timeoutSeconds = null) use (&$commands): ProcessResult {
            $commands[] = $command;

            if (str_contains($command, 'nohup sh -lc')) {
                return incusTopologyBuilderProcessResult();
            }

            if (str_contains($command, '/tmp/orbit-e2e-prepared-bake.status') && ! str_contains($command, 'cat >')) {
                return incusTopologyBuilderProcessResult(implode("\n", [
                    '__orbit_bake_status dev 0',
                    '__orbit_bake_status prod 1',
                    '__orbit_bake_status agent 0',
                    '__orbit_bake_status websocket 0',
                    '',
                ]), 'prod failed', successful: false);
            }

            if (($result = incusTopologyBuilderPreparedRoleResult($command)) !== null) {
                return $result;
            }

            if (str_contains($command, '/tmp/orbit-e2e-prepared-bake.sh') && ! str_contains($command, 'cat >')) {
                return incusTopologyBuilderProcessResult(implode("\n", [
                    '__orbit_bake_status dev 0',
                    '__orbit_bake_status prod 1',
                    '__orbit_bake_status agent 0',
                    '__orbit_bake_status websocket 0',
                    '',
                ]), 'prod failed', successful: false);
            }

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

        expect(fn () => $builder->build(E2ETopologyKind::OperatorGatewayAppdevAppprodAgentWebsocket))
            ->toThrow(RuntimeException::class, 'Could not bake prepared downstream roles: prod');

        $commandOutput = implode("\n", $commands);

        expect($commandOutput)
            ->toContain('artisan orbit:internal:bake-websocket-node')
            ->and($snapshots)->toContain('orbit-template-app-dev-base:clean-operator_gateway_app-dev_app-prod_agent_websocket-base')
            ->and($snapshots)->toContain('orbit-template-agent-base:clean-operator_gateway_app-dev_app-prod_agent_websocket-base')
            ->and($snapshots)->not->toContain('orbit-template-app-prod-base:clean-operator_gateway_app-dev_app-prod_agent_websocket-base');
    });
});

it('reuses valid app production and agent checkpoints while retrying missing app development websocket work', function (): void {
    withE2ETopologyEnvironment([], function (): void {
        $config = incusTopologyBuilderConfig();
        $kind = E2ETopologyKind::OperatorGatewayAppdevAppprodAgentWebsocket;
        $commands = [];
        $fingerprint = [
            'schema_version' => 1,
            'topology_kind' => $kind->value,
            'role_dag' => [
                'operator' => [],
                'gateway' => ['operator'],
                'dev' => ['gateway'],
                'prod' => ['gateway'],
                'agent' => ['gateway'],
            ],
            'fingerprints' => ['global' => 'global-current'],
            'role_fingerprints' => [
                'operator' => 'operator-current',
                'gateway' => 'gateway-current',
                'dev' => 'dev-current',
                'prod' => 'prod-current',
                'agent' => 'agent-current',
            ],
        ];
        $checkpointSnapshot = 'clean-operator_gateway_app-dev_app-prod_agent_websocket-base';

        Process::fake([
            'wg genkey' => Process::result(output: "private-key\n"),
            'wg pubkey' => Process::result(output: "public-key\n"),
        ]);

        $host = m::mock(IncusHost::class, [$config])->makePartial();
        $host->shouldReceive('imageExists')->with($config->baseImage)->andReturn(true);
        $host->shouldReceive('readTextFile')->andReturn(json_encode(E2EProvisionCheckpointManifest::create(
            kind: $kind,
            fingerprint: $fingerprint,
            checkpoints: [
                ['role' => 'operator', 'name' => 'orbit-template-operator-base', 'snapshot' => $checkpointSnapshot],
                ['role' => 'gateway', 'name' => 'orbit-template-gateway-base', 'snapshot' => $checkpointSnapshot],
                ['role' => 'prod', 'name' => 'orbit-template-app-prod-base', 'snapshot' => $checkpointSnapshot],
                ['role' => 'agent', 'name' => 'orbit-template-agent-base', 'snapshot' => $checkpointSnapshot],
            ],
            complete: false,
        ), JSON_THROW_ON_ERROR));
        $host->shouldReceive('snapshotExists')->andReturn(true);
        $host->shouldReceive('instanceExists')->andReturn(false);
        $host->shouldReceive('stopInstancesIfRunning')->once()->andReturn(incusTopologyBuilderProcessResult());
        $host->shouldReceive('startInstancesIfStopped')->once()->andReturn(incusTopologyBuilderProcessResult());
        $host->shouldReceive('waitForCloudInit')->never();
        $host->shouldReceive('stopInstance')->zeroOrMoreTimes()->andReturn(incusTopologyBuilderProcessResult());
        $host->shouldReceive('snapshotInstance')->zeroOrMoreTimes()->andReturn(incusTopologyBuilderProcessResult());
        $host->shouldReceive('writeTextFile')->zeroOrMoreTimes()->andReturn(incusTopologyBuilderProcessResult());
        $host->shouldReceive('run')->andReturnUsing(function (string $command, ?int $timeoutSeconds = null) use (&$commands): ProcessResult {
            $commands[] = $command;

            if (($result = incusTopologyBuilderPreparedBakeResult($command)) !== null) {
                return $result;
            }

            if (($result = incusTopologyBuilderPreparedRoleResult($command)) !== null) {
                return $result;
            }

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
        $builder->useProvisionFingerprint($fingerprint);

        $manifest = $builder->build($kind, replaceExisting: true);
        $commandOutput = implode("\n", $commands);

        expect(array_column($manifest, 'role'))->toBe(['operator', 'gateway', 'dev', 'prod', 'agent'])
            ->and($commandOutput)->toContain("incus launch 'orbit-base-ubuntu-26.04' 'orbit-template-app-dev-base'")
            ->and($commandOutput)->not->toContain("incus launch 'orbit-base-ubuntu-26.04' 'orbit-template-app-prod-base'")
            ->and($commandOutput)->not->toContain("incus launch 'orbit-base-ubuntu-26.04' 'orbit-template-agent-base'")
            ->and($commandOutput)->toContain('PID_PREPARE_DEV=$!')
            ->and($commandOutput)->not->toContain('PID_PREPARE_PROD=$!')
            ->and($commandOutput)->not->toContain('PID_PREPARE_AGENT=$!')
            ->and($commandOutput)->toContain('artisan orbit:internal:bake-app-node')
            ->and($commandOutput)->toContain('artisan orbit:internal:bake-websocket-node')
            ->and($commandOutput)->not->toContain('artisan orbit:internal:bake-agent-node')
            ->and($commandOutput)->not->toContain('artisan orbit:internal:bake-ingress-node');
    });
});

it('retries websocket bake when all concrete role checkpoints are valid but the websocket topology is incomplete', function (): void {
    withE2ETopologyEnvironment([], function (): void {
        $config = incusTopologyBuilderConfig();
        $kind = E2ETopologyKind::OperatorGatewayAppdevAppprodAgentWebsocket;
        $commands = [];
        $fingerprint = [
            'schema_version' => 1,
            'topology_kind' => $kind->value,
            'role_dag' => [
                'operator' => [],
                'gateway' => ['operator'],
                'dev' => ['gateway'],
                'prod' => ['gateway'],
                'agent' => ['gateway'],
            ],
            'fingerprints' => ['global' => 'global-current'],
            'role_fingerprints' => [
                'operator' => 'operator-current',
                'gateway' => 'gateway-current',
                'dev' => 'dev-current',
                'prod' => 'prod-current',
                'agent' => 'agent-current',
            ],
        ];
        $checkpointSnapshot = 'clean-operator_gateway_app-dev_app-prod_agent_websocket-base';

        Process::fake([
            'wg genkey' => Process::result(output: "private-key\n"),
            'wg pubkey' => Process::result(output: "public-key\n"),
        ]);

        $host = m::mock(IncusHost::class, [$config])->makePartial();
        $host->shouldReceive('imageExists')->with($config->baseImage)->andReturn(true);
        $host->shouldReceive('readTextFile')->andReturn(json_encode(E2EProvisionCheckpointManifest::create(
            kind: $kind,
            fingerprint: $fingerprint,
            checkpoints: [
                ['role' => 'operator', 'name' => 'orbit-template-operator-base', 'snapshot' => $checkpointSnapshot],
                ['role' => 'gateway', 'name' => 'orbit-template-gateway-base', 'snapshot' => $checkpointSnapshot],
                ['role' => 'dev', 'name' => 'orbit-template-app-dev-base', 'snapshot' => $checkpointSnapshot],
                ['role' => 'prod', 'name' => 'orbit-template-app-prod-base', 'snapshot' => $checkpointSnapshot],
                ['role' => 'agent', 'name' => 'orbit-template-agent-base', 'snapshot' => $checkpointSnapshot],
            ],
            complete: false,
        ), JSON_THROW_ON_ERROR));
        $host->shouldReceive('snapshotExists')->andReturn(true);
        $host->shouldReceive('instanceExists')->andReturn(false);
        $host->shouldReceive('stopInstancesIfRunning')->once()->andReturn(incusTopologyBuilderProcessResult());
        $host->shouldReceive('startInstancesIfStopped')->once()->andReturn(incusTopologyBuilderProcessResult());
        $host->shouldReceive('waitForCloudInit')->never();
        $host->shouldReceive('stopInstance')->zeroOrMoreTimes()->andReturn(incusTopologyBuilderProcessResult());
        $host->shouldReceive('snapshotInstance')->zeroOrMoreTimes()->andReturn(incusTopologyBuilderProcessResult());
        $host->shouldReceive('writeTextFile')->zeroOrMoreTimes()->andReturn(incusTopologyBuilderProcessResult());
        $host->shouldReceive('run')->andReturnUsing(function (string $command, ?int $timeoutSeconds = null) use (&$commands): ProcessResult {
            $commands[] = $command;

            if (str_contains($command, 'artisan orbit:internal:bake-websocket-node')) {
                return incusTopologyBuilderProcessResult();
            }

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

        $timer = new E2EPhaseTimer;
        $builder = new IncusTopologyBuilder($host, $timer);
        $builder->useBundle('/tmp/orbit-e2e-bundle-test');
        $builder->useProvisionFingerprint($fingerprint);

        $manifest = $builder->build($kind, replaceExisting: true);
        $commandOutput = implode("\n", $commands);
        $phaseNames = array_column($timer->events(), 'name');

        expect(array_column($manifest, 'role'))->toBe(['operator', 'gateway', 'dev', 'prod', 'agent'])
            ->and($phaseNames)->toContain('prepared-websocket.dev.real-wireguard')
            ->and($phaseNames)->toContain('prepared-websocket.dev.database-redis-seed')
            ->and($phaseNames)->toContain('prepared-websocket.websocket.bake')
            ->and($commandOutput)->toContain('artisan orbit:internal:bake-websocket-node')
            ->and($commandOutput)->not->toContain('/tmp/orbit-e2e-prepared-bake.sh')
            ->and($commandOutput)->not->toContain('PID_PREPARE_DEV=$!')
            ->and($commandOutput)->not->toContain('PID_BAKE_DEV=$!')
            ->and($commandOutput)->not->toContain('artisan orbit:internal:bake-app-node')
            ->and($commandOutput)->not->toContain('artisan orbit:internal:bake-agent-node')
            ->and($commandOutput)->not->toContain('artisan orbit:internal:bake-ingress-node');
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
        ->and($deletedSnapshots)->not->toContain('orbit-template-operator-base:clean-operator_gateway_app-dev_app-prod_agent-base');
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
    $host->shouldReceive('waitForCloudInit')->times(2);
    $host->shouldReceive('provisionInstance')->with('orbit-template-operator-base', 'operator', '/tmp/orbit-e2e-bundle-test', 'operator')->once()->andReturn(incusTopologyBuilderProcessResult());
    $host->shouldReceive('stopInstance')->times(8)->andReturn(incusTopologyBuilderProcessResult());
    $host->shouldReceive('snapshotInstance')->times(8)->andReturn(incusTopologyBuilderProcessResult());
    $host->shouldReceive('run')->andReturnUsing(function (string $command, ?int $timeoutSeconds = null) use (&$commands): ProcessResult {
        $commands[] = $command;

        if (($result = incusTopologyBuilderPreparedBakeResult($command)) !== null) {
            return $result;
        }

        if (($result = incusTopologyBuilderPreparedRoleResult($command)) !== null) {
            return $result;
        }

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
        ->and($commandOutput)->toContain('orbit-gateway:prepared-current')
        ->and($commandOutput)->toContain('artisan orbit:internal:bootstrap-gateway-local gateway')
        ->and($commandOutput)->toContain('artisan tinker --execute=')
        ->and($commandOutput)->not->toContain('/tmp/orbit-e2e-appdev-database-redis.php')
        ->and($commandOutput)->not->toContain('cd /home/orbit/orbit && php artisan')
        ->and($commandOutput)->toContain('app-dev-1')
        ->and($commandOutput)->toContain('10.201.0.12')
        ->and($commandOutput)->toContain('--user=')
        ->and($commandOutput)->toContain('provisioner')
        ->and($commandOutput)->toContain('app-prod-1')
        ->and($commandOutput)->toContain('10.201.0.13')
        ->and($commandOutput)->not->toContain('cd /home/orbit/orbit && php apps/gateway/artisan orbit:internal:bake')
        ->and($commandOutput)->toContain('artisan orbit:internal:bake-app-node')
        ->and($commandOutput)->toContain('artisan orbit:internal:bake-ingress-node')
        ->and($commandOutput)->toContain('artisan orbit:internal:bake-agent-node')
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
