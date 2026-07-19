<?php

declare(strict_types=1);

use App\E2E\Support\E2EConfig;
use App\E2E\Support\E2EPhaseTimer;
use App\E2E\Support\E2ETopologyAcquisitionOptions;
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

function incusAcquisitionReadinessResult(
    string $output = '',
    bool $successful = true,
    string $errorOutput = '',
): ProcessResult {
    $result = m::mock(ProcessResult::class);
    $result->shouldReceive('successful')->andReturn($successful);
    $result->shouldReceive('output')->andReturn($output);
    $result->shouldReceive('errorOutput')->andReturn($errorOutput);

    return $result;
}

/**
 * @return array{0: IncusHost, 1: Closure(): list<string>}
 */
function incusAcquisitionReadinessCapturingHost(?string $failureNeedle = null): array
{
    $commands = [];
    $host = new class(incusAcquisitionReadinessConfig(), $commands, $failureNeedle) extends IncusHost {
        /**
         * @param  array<int, string>  $commands
         */
        public function __construct(
            E2EConfig $config,
            public array &$commands,
            private readonly ?string $failureNeedle,
        ) {
            parent::__construct($config);
        }

        #[Override]
        public function run(string $command, ?int $timeoutSeconds = null): ProcessResult
        {
            return $this->capture($command);
        }

        #[Override]
        public function runWithInput(string $command, string $input, ?int $timeoutSeconds = null): ProcessResult
        {
            return $this->capture($command."\n".$input);
        }

        private function capture(string $command): ProcessResult
        {
            $this->commands[] = $command;

            if ($this->failureNeedle !== null && str_contains($command, $this->failureNeedle)) {
                return incusAcquisitionReadinessResult(
                    successful: false,
                    errorOutput: 'injected handoff cleanup failure',
                );
            }

            if (str_contains($command, 'ip -j -4 address show scope global')) {
                return incusAcquisitionReadinessResult("10.231.7.84\n");
            }

            if (str_contains($command, 'wg show wg0 public-key')) {
                return incusAcquisitionReadinessResult("wg-easy-public-key\n");
            }

            if (str_contains($command, 'cat ~/.ssh/id_ed25519.pub')) {
                return incusAcquisitionReadinessResult("ssh-ed25519 gateway-public-key\n");
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

    return [
        $host,
        function () use ($host): array {
            return $host->commands;
        },
    ];
}

/**
 * @return array<string, IncusInstance>
 */
function incusAcquisitionReadinessSourceMountedInstances(IncusHost $host): array
{
    return [
        'operator' => new IncusInstance(
            $host,
            'clone-operator',
            commandTransport: true,
            sourceMountedCheckout: true,
            hostSourcePath: '/home/orbit/orbit',
        ),
        'gateway' => new IncusInstance(
            $host,
            'clone-gateway',
            commandTransport: true,
            sourceMountedCheckout: true,
            hostSourcePath: '/home/orbit/orbit',
        ),
        'dev' => new IncusInstance(
            $host,
            'clone-dev',
            commandTransport: true,
            sourceMountedCheckout: true,
            hostSourcePath: '/home/orbit/orbit',
        ),
    ];
}

it('awaits clone agents and refreshes network identity through one parallel host call', function (): void {
    [$host, $commands] = incusAcquisitionReadinessCapturingHost();

    IncusTopologyTemplate::clone($host, E2ETopologyKind::OperatorGateway, 'runReady');

    $readinessCalls = array_values(array_filter($commands(), function (string $command): bool {
        return str_contains($command, '__orbit_task_timing') && str_contains($command, 'systemd-machine-id-setup');
    }));

    $agentProbeCalls = array_values(array_filter($commands(), function (string $command): bool {
        return str_contains($command, "incus exec 'orbit-e2e-runReady-operator' -- true");
    }));

    expect($readinessCalls)
        ->toHaveCount(1)
        ->and($readinessCalls[0])
        ->toContain("'orbit-e2e-runReady-operator'")
        ->toContain("'orbit-e2e-runReady-gateway'")
        ->toContain('& PID_TASK_')
        ->and($agentProbeCalls)
        ->toHaveCount(1)
        ->and($agentProbeCalls[0])
        ->toBe($readinessCalls[0]);
});

it('deletes started clones when readiness never completes', function (): void {
    $commands = [];
    $host = new class(incusAcquisitionReadinessConfig(), $commands) extends IncusHost {
        /**
         * @param  array<int, string>  $commands
         */
        public function __construct(
            E2EConfig $config,
            public array &$commands,
        ) {
            parent::__construct($config);
        }

        #[Override]
        public function run(string $command, ?int $timeoutSeconds = null): ProcessResult
        {
            $this->commands[] = $command;

            if (str_contains($command, 'Incus agent never became ready')) {
                return incusAcquisitionReadinessResult(
                    output: '__orbit_task_status operator 1',
                    successful: false,
                    errorOutput: 'task operator failed',
                );
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

    expect(fn () => IncusTopologyTemplate::clone($host, E2ETopologyKind::OperatorGatewayAppdev, 'runFailed'))
        ->toThrow(RuntimeException::class, 'Prepared topology clones never became ready');

    $deleteCalls = array_values(array_filter($commands, function (string $command): bool {
        return str_contains($command, 'incus delete --force');
    }));

    expect($deleteCalls)
        ->toHaveCount(1)
        ->and($deleteCalls[0])
        ->toContain("'orbit-e2e-runFailed-operator'")
        ->toContain("'orbit-e2e-runFailed-gateway'")
        ->toContain("'orbit-e2e-runFailed-dev'");
});

it('deletes partially created clones when batch copy fails', function (): void {
    $commands = [];
    $host = new class(incusAcquisitionReadinessConfig(), $commands) extends IncusHost {
        /**
         * @param  array<int, string>  $commands
         */
        public function __construct(
            E2EConfig $config,
            public array &$commands,
        ) {
            parent::__construct($config);
        }

        #[Override]
        public function run(string $command, ?int $timeoutSeconds = null): ProcessResult
        {
            $this->commands[] = $command;

            return incusAcquisitionReadinessResult();
        }

        #[Override]
        public function runWithoutMultiplexing(string $command, ?int $timeoutSeconds = null): ProcessResult
        {
            $this->commands[] = $command;

            return incusAcquisitionReadinessResult(successful: false, errorOutput: 'copy failed');
        }
    };

    expect(fn () => IncusTopologyTemplate::clone($host, E2ETopologyKind::OperatorGatewayAppdev, 'runBatchFailed'))
        ->toThrow(RuntimeException::class, 'Topology batch failed');

    $deleteCalls = array_values(array_filter($commands, function (string $command): bool {
        return str_contains($command, 'incus delete --force');
    }));

    expect($deleteCalls)
        ->toHaveCount(1)
        ->and($deleteCalls[0])
        ->toContain("'orbit-e2e-runBatchFailed-operator'")
        ->toContain("'orbit-e2e-runBatchFailed-gateway'")
        ->toContain("'orbit-e2e-runBatchFailed-dev'");
});

it('keeps the original readiness failure visible when clone cleanup fails', function (): void {
    $commands = [];
    $host = new class(incusAcquisitionReadinessConfig(), $commands) extends IncusHost {
        /**
         * @param  array<int, string>  $commands
         */
        public function __construct(
            E2EConfig $config,
            public array &$commands,
        ) {
            parent::__construct($config);
        }

        #[Override]
        public function run(string $command, ?int $timeoutSeconds = null): ProcessResult
        {
            $this->commands[] = $command;

            if (str_contains($command, 'Incus agent never became ready')) {
                return incusAcquisitionReadinessResult(
                    output: '__orbit_task_status operator 1',
                    successful: false,
                    errorOutput: 'task operator failed',
                );
            }

            return incusAcquisitionReadinessResult();
        }

        #[Override]
        public function runWithoutMultiplexing(string $command, ?int $timeoutSeconds = null): ProcessResult
        {
            $this->commands[] = $command;

            return incusAcquisitionReadinessResult();
        }

        /**
         * @param  list<string>  $names
         */
        #[Override]
        public function deleteInstancesIfPresent(array $names): ProcessResult
        {
            $this->commands[] = 'delete failed: '.implode(',', $names);

            throw new RuntimeException('delete failed');
        }
    };

    expect(fn () => IncusTopologyTemplate::clone($host, E2ETopologyKind::OperatorGateway, 'runCleanupFailed'))
        ->toThrow(RuntimeException::class, 'Prepared topology clones never became ready');

    expect($commands)->toContain(
        'delete failed: orbit-e2e-runCleanupFailed-operator,orbit-e2e-runCleanupFailed-gateway',
    );
});

it('waits for peer routes through one parallel host call chaining gateway probe before operator scan', function (): void {
    [$host, $commands] = incusAcquisitionReadinessCapturingHost();
    $provider = new IncusTopologyProvider(incusAcquisitionReadinessConfig());

    $method = new ReflectionMethod($provider, 'waitForPeerRoutes');
    $method->setAccessible(true);
    $method->invoke(
        $provider,
        [
            'operator' => new IncusInstance($host, 'clone-operator', commandTransport: true),
            'gateway' => new IncusInstance($host, 'clone-gateway', commandTransport: true),
            'dev' => new IncusInstance($host, 'clone-dev', commandTransport: true),
            'prod' => new IncusInstance($host, 'clone-prod', commandTransport: true),
        ],
        incusAcquisitionReadinessConfig(),
    );

    $peerCalls = array_values(array_filter($commands(), function (string $command): bool {
        return str_contains($command, '__orbit_task_timing') && str_contains($command, '10.6.0.4');
    }));

    expect($peerCalls)
        ->toHaveCount(1)
        ->and($peerCalls[0])
        ->toContain('10.6.0.4')
        ->toContain('10.6.0.5')
        ->toContain('& PID_TASK_')
        ->toContain('ssh-keyscan');

    $devGatewayProbe = strpos($peerCalls[0], 'ServerAliveInterval=30');
    $devOperatorScan = strpos($peerCalls[0], 'ssh-keyscan');

    expect($devGatewayProbe)
        ->toBeInt()
        ->and($devOperatorScan)
        ->toBeInt()
        ->and($devGatewayProbe)
        ->toBeLessThan($devOperatorScan);
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

    expect($installCalls)
        ->toHaveCount(1)
        ->and($installCalls[0])
        ->toContain("'clone-operator'")
        ->toContain("'clone-gateway'")
        ->toContain("'clone-dev'")
        ->toContain('& PID_TASK_')
        ->and(implode("\n", $commands()))
        ->toContain('label=com.docker.swarm.service.name=orbit_orbit-vpn');
});

it('runs acquisition retarget bakes for downstream roles in one parallel gateway call', function (): void {
    [$host, $commands] = incusAcquisitionReadinessCapturingHost();
    $provider = new IncusTopologyProvider(incusAcquisitionReadinessConfig());

    $method = new ReflectionMethod($provider, 'retargetTopology');
    $method->setAccessible(true);
    $method->invoke(
        $provider,
        [
            'operator' => new IncusInstance($host, 'clone-operator', commandTransport: true),
            'gateway' => new IncusInstance($host, 'clone-gateway', commandTransport: true),
            'dev' => new IncusInstance($host, 'clone-dev', commandTransport: true),
            'prod' => new IncusInstance($host, 'clone-prod', commandTransport: true),
            'agent' => new IncusInstance($host, 'clone-agent', commandTransport: true),
        ],
        incusAcquisitionReadinessConfig(),
        new SshKeyPair('/tmp/id_ed25519', '/tmp/id_ed25519.pub'),
        E2ETopologyKind::OperatorGatewayAppdevAppprodAgent,
        false,
    );

    $joined = implode("\n", $commands());
    $bakeCalls = array_values(array_filter($commands(), function (string $command): bool {
        return str_contains($command, '__orbit_task_timing') && str_contains($command, 'orbit:internal:bake-app-node');
    }));

    expect($bakeCalls)
        ->toHaveCount(1)
        ->and($bakeCalls[0])
        ->toContain('orbit:internal:bake-app-node app-dev-1')
        ->toContain('orbit:internal:bake-app-node app-prod-1')
        ->toContain('orbit:internal:bake-agent-node agent-1')
        ->toContain('ssh-keyscan')
        ->toContain('& PID_TASK_');

    $parallelBake = strpos($joined, 'orbit:internal:bake-app-node app-dev-1');
    $seed = strpos($joined, 'ProcessServiceCatalog::class');

    expect($parallelBake)
        ->toBeInt()
        ->and($seed)
        ->toBeInt()
        ->and($parallelBake)
        ->toBeLessThan($seed);

    // The prod chain bakes the co-hosted ingress role before the prod app role.
    $prodIngress = strpos($bakeCalls[0], 'orbit:internal:bake-ingress-node app-prod-1');
    $prodApp = strpos($bakeCalls[0], 'orbit:internal:bake-app-node app-prod-1');

    expect($prodIngress)
        ->toBeInt()
        ->and($bakeCalls[0])
        ->toContain('orbit:internal:bake-ingress-node app-prod-1 --tld=prod')
        ->toContain('orbit:internal:bake-app-node app-prod-1 --role=app-prod --tld=prod')
        ->and($prodApp)
        ->toBeInt()
        ->and($prodIngress)
        ->toBeLessThan($prodApp);
});

it('starts the gateway api before acquisition retarget bakes downstream roles', function (): void {
    [$host, $commands] = incusAcquisitionReadinessCapturingHost();
    $provider = new IncusTopologyProvider(incusAcquisitionReadinessConfig());
    $instances = incusAcquisitionReadinessSourceMountedInstances($host);

    $method = new ReflectionMethod($provider, 'prepareInstances');
    $method->setAccessible(true);
    $method->invoke(
        $provider,
        $instances,
        incusAcquisitionReadinessConfig(),
        new SshKeyPair('/tmp/id_ed25519', '/tmp/id_ed25519.pub'),
        new E2EPhaseTimer,
        new E2ETopologyAcquisitionOptions(startGatewayApi: true, sourceMountedCheckout: true),
        E2ETopologyKind::OperatorGatewayAppdev,
    );

    $joined = implode("\n", $commands());
    $gatewayApiStart = strpos($joined, 'orbit-gateway-e2e-topology-lease-http');
    $sourceMountedLauncher = strpos($joined, '/home/orbit/.local/bin/orbit');
    $dnsLayoutConvergence = strpos(
        haystack: $joined,
        needle: 'orbit:internal:converge-vpn-dns-runtime gateway',
    );
    $legacyVpnStop = strpos(haystack: $joined, needle: 'docker stop wg-easy');
    $legacyVpnRemoval = strpos(haystack: $joined, needle: 'docker rm wg-easy');
    $downstreamBake = strpos($joined, 'orbit:internal:bake-app-node app-dev-1');

    expect($gatewayApiStart)
        ->toBeInt()
        ->and($sourceMountedLauncher)
        ->toBeInt()
        ->and($dnsLayoutConvergence)
        ->toBeInt()
        ->and($legacyVpnStop)
        ->toBeInt()
        ->and($legacyVpnRemoval)
        ->toBeInt()
        ->and($downstreamBake)
        ->toBeInt()
        ->and($gatewayApiStart)
        ->toBeLessThan($downstreamBake)
        ->and($sourceMountedLauncher)
        ->toBeLessThan($downstreamBake)
        ->and($legacyVpnStop)
        ->toBeLessThan($dnsLayoutConvergence)
        ->and($dnsLayoutConvergence)
        ->toBeLessThan($legacyVpnRemoval)
        ->and($legacyVpnRemoval)
        ->toBeLessThan($downstreamBake)
        ->and($joined)
        ->toContain('/home/orbit/orbit/bin/orbit');
});

it('restores standalone wg-easy when post-convergence handoff cleanup fails', function (): void {
    [$host, $commands] = incusAcquisitionReadinessCapturingHost('docker rm wg-easy');
    $provider = new IncusTopologyProvider(incusAcquisitionReadinessConfig());
    $instances = incusAcquisitionReadinessSourceMountedInstances($host);

    try {
        $method = new ReflectionMethod($provider, 'prepareInstances');
        $method->setAccessible(true);
        $method->invoke(
            $provider,
            $instances,
            incusAcquisitionReadinessConfig(),
            new SshKeyPair('/tmp/id_ed25519', '/tmp/id_ed25519.pub'),
            new E2EPhaseTimer,
            new E2ETopologyAcquisitionOptions(startGatewayApi: true, sourceMountedCheckout: true),
            E2ETopologyKind::OperatorGatewayAppdev,
        );

        test()->fail('Expected handoff cleanup to fail.');
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())->toContain('injected handoff cleanup failure');
    }

    $joined = implode("\n", $commands());
    $cleanup = strpos(haystack: $joined, needle: 'docker rm wg-easy');
    $rollback = strpos(haystack: $joined, needle: 'docker service rm orbit_orbit-vpn orbit_orbit-dns');

    expect($cleanup)
        ->toBeInt()
        ->and($rollback)
        ->toBeInt()
        ->and($cleanup)
        ->toBeLessThan($rollback)
        ->and($joined)
        ->toContain('sudo mv /home/orbit/.config/orbit/wg-easy /home/orbit/.wg-easy')
        ->not->toContain('orbit:internal:bake-app-node app-dev-1');
});

it('starts the gateway api before snapshot reset retarget bakes downstream roles', function (): void {
    [$host, $commands] = incusAcquisitionReadinessCapturingHost();
    $provider = new IncusTopologyProvider(incusAcquisitionReadinessConfig());
    $instances = incusAcquisitionReadinessSourceMountedInstances($host);

    $method = new ReflectionMethod($provider, 'snapshotResetFor');
    $method->setAccessible(true);
    $reset = $method->invoke(
        $provider,
        $instances,
        [
            'operator' => 'operator',
            'gateway' => 'orbit',
            'dev' => 'orbit',
        ],
        new SshKeyPair('/tmp/id_ed25519', '/tmp/id_ed25519.pub'),
        true,
        E2ETopologyKind::OperatorGatewayAppdev,
        true,
    );
    $reset(new E2EPhaseTimer);

    $joined = implode("\n", $commands());
    $gatewayApiStart = strpos($joined, 'orbit-gateway-e2e-topology-reset-http');
    $sourceMountedLauncher = strpos($joined, '/home/orbit/.local/bin/orbit');
    $downstreamBake = strpos($joined, 'orbit:internal:bake-app-node app-dev-1');

    expect($gatewayApiStart)
        ->toBeInt()
        ->and($sourceMountedLauncher)
        ->toBeInt()
        ->and($downstreamBake)
        ->toBeInt()
        ->and($gatewayApiStart)
        ->toBeLessThan($downstreamBake)
        ->and($sourceMountedLauncher)
        ->toBeLessThan($downstreamBake)
        ->and($joined)
        ->toContain('/home/orbit/orbit/bin/orbit');
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

    expect($clearCalls)
        ->toHaveCount(1)
        ->and($clearCalls[0])
        ->toContain("'clone-operator'")
        ->toContain("'clone-gateway'");
});
