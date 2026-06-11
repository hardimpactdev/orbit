<?php

declare(strict_types=1);

use App\E2E\Support\E2EConfig;
use App\E2E\Support\E2ETopologyKind;
use App\E2E\Support\IncusHost;
use App\E2E\Support\IncusInstance;
use App\E2E\Support\IncusTopologyProvider;
use App\E2E\Support\IncusTopologyTemplate;
use App\E2E\Support\SshKeyPair;
use Illuminate\Contracts\Process\ProcessResult;
use Mockery as m;

beforeEach(function (): void {
    putenv('GH_TOKEN');
    putenv('GITHUB_TOKEN');
});

afterEach(function (): void {
    m::close();
});

function incusAcquisitionReadinessConfig(): E2EConfig
{
    return new E2EConfig(
        providerNames: ['incus'],
        topologyProviderNames: ['incus'],
        host: 'beast',
        sourceImage: '',
        baseImage: '',
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
        incusHostVmCaps: ['beast' => 16],
    );
}

function incusAcquisitionReadinessResult(string $output = '', bool $successful = true): ProcessResult
{
    $result = m::mock(ProcessResult::class);
    $result->shouldReceive('successful')->andReturn($successful);
    $result->shouldReceive('output')->andReturn($output);
    $result->shouldReceive('errorOutput')->andReturn('');

    return $result;
}

/**
 * @return array{0: IncusHost, 1: Closure(): list<string>}
 */
function incusAcquisitionReadinessCapturingHost(): array
{
    $commands = [];
    $host = new class(incusAcquisitionReadinessConfig(), $commands) extends IncusHost
    {
        /**
         * @param  array<int, string>  $commands
         */
        public function __construct(E2EConfig $config, public array &$commands)
        {
            parent::__construct($config);
        }

        #[Override]
        public function run(string $command, ?int $timeoutSeconds = null): ProcessResult
        {
            $this->commands[] = $command;

            if (str_contains($command, 'ip -j -4 address show scope global')) {
                return incusAcquisitionReadinessResult("10.231.7.84\n");
            }

            if (str_contains($command, 'wg show wg0 public-key')) {
                return incusAcquisitionReadinessResult("wg-easy-public-key\n");
            }

            return incusAcquisitionReadinessResult();
        }

        #[Override]
        public function runWithoutMultiplexing(string $command, ?int $timeoutSeconds = null): ProcessResult
        {
            $this->commands[] = $command;

            return incusAcquisitionReadinessResult();
        }
    };

    return [$host, function () use ($host): array {
        return $host->commands;
    }];
}

it('awaits clone agents and refreshes network identity through one parallel host call', function (): void {
    [$host, $commands] = incusAcquisitionReadinessCapturingHost();

    IncusTopologyTemplate::clone($host, E2ETopologyKind::OperatorGateway, 'runReady');

    $readinessCalls = array_values(array_filter($commands(), function (string $command): bool {
        return str_contains($command, '__orbit_task_timing')
            && str_contains($command, 'systemd-machine-id-setup');
    }));

    $agentProbeCalls = array_values(array_filter($commands(), function (string $command): bool {
        return str_contains($command, "incus exec 'orbit-e2e-runReady-operator' -- true");
    }));

    expect($readinessCalls)->toHaveCount(1)
        ->and($readinessCalls[0])
        ->toContain("'orbit-e2e-runReady-operator'")
        ->toContain("'orbit-e2e-runReady-gateway'")
        ->toContain('& PID_TASK_')
        ->and($agentProbeCalls)->toHaveCount(1)
        ->and($agentProbeCalls[0])->toBe($readinessCalls[0]);
});

it('waits for peer routes through one parallel host call chaining gateway probe before operator scan', function (): void {
    [$host, $commands] = incusAcquisitionReadinessCapturingHost();
    $provider = new IncusTopologyProvider(incusAcquisitionReadinessConfig());

    $method = new ReflectionMethod($provider, 'waitForPeerRoutes');
    $method->setAccessible(true);
    $method->invoke($provider, [
        'operator' => new IncusInstance($host, 'clone-operator', commandTransport: true),
        'gateway' => new IncusInstance($host, 'clone-gateway', commandTransport: true),
        'dev' => new IncusInstance($host, 'clone-dev', commandTransport: true),
        'prod' => new IncusInstance($host, 'clone-prod', commandTransport: true),
    ], incusAcquisitionReadinessConfig());

    $peerCalls = array_values(array_filter($commands(), function (string $command): bool {
        return str_contains($command, '__orbit_task_timing') && str_contains($command, '10.6.0.4');
    }));

    expect($peerCalls)->toHaveCount(1)
        ->and($peerCalls[0])
        ->toContain('10.6.0.4')
        ->toContain('10.6.0.5')
        ->toContain('& PID_TASK_')
        ->toContain('ssh-keyscan');

    $devGatewayProbe = strpos($peerCalls[0], 'ServerAliveInterval=30');
    $devOperatorScan = strpos($peerCalls[0], 'ssh-keyscan');

    expect($devGatewayProbe)->toBeInt()
        ->and($devOperatorScan)->toBeInt()
        ->and($devGatewayProbe)->toBeLessThan($devOperatorScan);
});

it('installs the WireGuard mesh on every role through one parallel host call', function (): void {
    [$host, $commands] = incusAcquisitionReadinessCapturingHost();
    $provider = new IncusTopologyProvider(incusAcquisitionReadinessConfig());

    $method = new ReflectionMethod($provider, 'retargetRealWireGuard');
    $method->setAccessible(true);
    $method->invoke($provider, [
        'operator' => new IncusInstance($host, 'clone-operator', commandTransport: true),
        'gateway' => new IncusInstance($host, 'clone-gateway', commandTransport: true),
        'dev' => new IncusInstance($host, 'clone-dev', commandTransport: true),
    ]);

    $installCalls = array_values(array_filter($commands(), function (string $command): bool {
        return str_contains($command, '__orbit_task_timing') && str_contains($command, 'wg-quick up wg-orbit');
    }));

    expect($installCalls)->toHaveCount(1)
        ->and($installCalls[0])
        ->toContain("'clone-operator'")
        ->toContain("'clone-gateway'")
        ->toContain("'clone-dev'")
        ->toContain('& PID_TASK_');
});

it('runs acquisition retarget bakes for downstream roles in one parallel gateway call', function (): void {
    [$host, $commands] = incusAcquisitionReadinessCapturingHost();
    $provider = new IncusTopologyProvider(incusAcquisitionReadinessConfig());

    $method = new ReflectionMethod($provider, 'retargetTopology');
    $method->setAccessible(true);
    $method->invoke($provider, [
        'operator' => new IncusInstance($host, 'clone-operator', commandTransport: true),
        'gateway' => new IncusInstance($host, 'clone-gateway', commandTransport: true),
        'dev' => new IncusInstance($host, 'clone-dev', commandTransport: true),
        'prod' => new IncusInstance($host, 'clone-prod', commandTransport: true),
        'agent' => new IncusInstance($host, 'clone-agent', commandTransport: true),
    ], incusAcquisitionReadinessConfig(), new SshKeyPair('/tmp/id_ed25519', '/tmp/id_ed25519.pub'), E2ETopologyKind::OperatorGatewayAppdevAppprodAgent, false);

    $joined = implode("\n", $commands());
    $bakeCalls = array_values(array_filter($commands(), function (string $command): bool {
        return str_contains($command, '__orbit_task_timing') && str_contains($command, 'orbit:internal:bake-app-node');
    }));

    expect($bakeCalls)->toHaveCount(1)
        ->and($bakeCalls[0])
        ->toContain('orbit:internal:bake-app-node app-dev-1')
        ->toContain('orbit:internal:bake-app-node app-prod-1')
        ->toContain('orbit:internal:bake-agent-node agent-1')
        ->toContain('ssh-keyscan')
        ->toContain('& PID_TASK_');

    $parallelBake = strpos($joined, 'orbit:internal:bake-app-node app-dev-1');
    $seed = strpos($joined, 'redis-server --appendonly yes');

    expect($parallelBake)->toBeInt()
        ->and($seed)->toBeInt()
        ->and($parallelBake)->toBeLessThan($seed);

    // The prod chain bakes the co-hosted ingress role before the prod app role.
    $prodIngress = strpos($bakeCalls[0], 'orbit:internal:bake-ingress-node app-prod-1');
    $prodApp = strpos($bakeCalls[0], 'orbit:internal:bake-app-node app-prod-1');

    expect($prodIngress)->toBeInt()
        ->and($prodApp)->toBeInt()
        ->and($prodIngress)->toBeLessThan($prodApp);
});

it('clears known hosts on every clone through one parallel host call', function (): void {
    [$host, $commands] = incusAcquisitionReadinessCapturingHost();
    $provider = new IncusTopologyProvider(incusAcquisitionReadinessConfig());

    $method = new ReflectionMethod($provider, 'clearKnownHosts');
    $method->setAccessible(true);
    $method->invoke($provider, [
        'operator' => new IncusInstance($host, 'clone-operator', commandTransport: true),
        'gateway' => new IncusInstance($host, 'clone-gateway', commandTransport: true),
    ]);

    $clearCalls = array_values(array_filter($commands(), function (string $command): bool {
        return str_contains($command, 'known_hosts');
    }));

    expect($clearCalls)->toHaveCount(1)
        ->and($clearCalls[0])
        ->toContain("'clone-operator'")
        ->toContain("'clone-gateway'");
});
