<?php

declare(strict_types=1);

use App\E2E\Support\E2EConfig;
use App\E2E\Support\E2EPhaseTimer;
use App\E2E\Support\E2ETopologyKind;
use App\E2E\Support\IncusHost;
use App\E2E\Support\IncusInstance;
use App\E2E\Support\IncusTopologyProvider;
use App\E2E\Support\IncusTopologyTemplate;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Mockery as m;

afterEach(function (): void {
    m::close();
});

beforeEach(function (): void {
    Process::preventStrayProcesses();
    Process::fake(static function (PendingProcess $process): ProcessResult {
        if (str_contains((string) $process->command, 'flock -w 30 9')) {
            return Process::result(implode("\n", [
                '__ORBIT_SOURCE_SYNC_LOCK_READY__',
                '__ORBIT_SOURCE_SYNC_LOCK_RELEASED__',
            ]));
        }

        return Process::result();
    });
});

function incusRoundTripsConfig(): E2EConfig
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

function incusRoundTripsResult(string $output = '', bool $successful = true): ProcessResult
{
    $result = m::mock(ProcessResult::class);
    $result->shouldReceive('successful')->andReturn($successful);
    $result->shouldReceive('output')->andReturn($output);
    $result->shouldReceive('errorOutput')->andReturn('');

    return $result;
}

it('resolves every role snapshot source through one host call', function (): void {
    $config = incusRoundTripsConfig();
    $host = m::mock(IncusHost::class, [$config])->makePartial();
    $resolutionCalls = 0;

    $host->shouldReceive('run')
        ->andReturnUsing(function (string $command) use (&$resolutionCalls): ProcessResult {
            if (str_contains($command, '__orbit_snapshot_source')) {
                $resolutionCalls++;

                return incusRoundTripsResult(implode("\n", [
                    '__orbit_snapshot_source operator orbit-template-operator-base clean-operator_gateway_app-dev_app-prod_agent_websocket-base',
                    '__orbit_snapshot_source gateway orbit-template-gateway-base clean-operator_gateway_app-dev_app-prod_agent_websocket-base',
                    '__orbit_snapshot_source agent orbit-template-agent-base clean-operator_gateway_app-dev_app-prod_agent_websocket-base',
                ]));
            }

            return incusRoundTripsResult();
        });

    $script = IncusTopologyTemplate::buildBatchScript(
        $host,
        E2ETopologyKind::OperatorGatewayAgent,
        'runRt',
        IncusTopologyTemplate::rolesFor(E2ETopologyKind::OperatorGatewayAgent),
    );

    expect($resolutionCalls)
        ->toBe(1)
        ->and($script)
        ->toContain(
            "incus copy 'orbit-template-operator-base/clean-operator_gateway_app-dev_app-prod_agent_websocket-base'",
        )
        ->toContain(
            "incus copy 'orbit-template-agent-base/clean-operator_gateway_app-dev_app-prod_agent_websocket-base'",
        );
});

it('mounts an explicit topology-scoped source path into every clone', function (): void {
    $config = incusRoundTripsConfig();
    $host = m::mock(IncusHost::class, [$config])->makePartial();

    $host->shouldReceive('run')
        ->andReturnUsing(function (string $command): ProcessResult {
            if (str_contains($command, '__orbit_snapshot_source')) {
                return incusRoundTripsResult(implode("\n", [
                    '__orbit_snapshot_source operator orbit-template-operator-base clean-operator_gateway-base',
                    '__orbit_snapshot_source gateway orbit-template-gateway-base clean-operator_gateway-base',
                ]));
            }

            return incusRoundTripsResult();
        });

    $script = IncusTopologyTemplate::buildBatchScript(
        $host,
        E2ETopologyKind::OperatorGateway,
        'dev-aaa111',
        IncusTopologyTemplate::rolesFor(E2ETopologyKind::OperatorGateway),
        sourceMounted: true,
        sourcePath: '/tmp/orbit-source/retained/dev-aaa111',
    );

    expect($script)
        ->toContain("orbit-source source='/tmp/orbit-source/retained/dev-aaa111'")
        ->not->toContain('source=/tmp/orbit-source/retained/dev-bbb222');
});

it('refuses a source-mounted clone plan without an explicit host source path', function (): void {
    $config = incusRoundTripsConfig();
    $host = m::mock(IncusHost::class, [$config])->makePartial();

    expect(fn () => IncusTopologyTemplate::buildBatchScript(
        $host,
        E2ETopologyKind::OperatorGateway,
        'dev-aaa111',
        IncusTopologyTemplate::rolesFor(E2ETopologyKind::OperatorGateway),
        sourceMounted: true,
    ))
        ->toThrow(InvalidArgumentException::class, 'requires an explicit host source path');
});

it('falls back to base snapshot sources per role inside the single resolution call', function (): void {
    withE2ETopologyEnvironment([
        'ORBIT_E2E_TOPOLOGY_ARTIFACT_NAMESPACE' => 'Branch A/B',
    ], function (): void {
        $config = incusRoundTripsConfig();
        $host = m::mock(IncusHost::class, [$config])->makePartial();

        $host->shouldReceive('run')
            ->andReturnUsing(function (string $command): ProcessResult {
                if (str_contains($command, '__orbit_snapshot_source')) {
                    return incusRoundTripsResult(implode("\n", [
                        '__orbit_snapshot_source operator orbit-template-operator-branch-a-b clean-operator_gateway_app-dev_app-prod_agent_websocket-branch-a-b',
                        '__orbit_snapshot_source gateway orbit-template-gateway-branch-a-b clean-operator_gateway_app-dev_app-prod_agent_websocket-branch-a-b',
                        '__orbit_snapshot_source agent orbit-template-agent-base clean-operator_gateway_app-dev_app-prod_agent_websocket-base',
                    ]));
                }

                return incusRoundTripsResult();
            });

        $script = IncusTopologyTemplate::buildBatchScript(
            $host,
            E2ETopologyKind::OperatorGatewayAgent,
            'runBranch',
            IncusTopologyTemplate::rolesFor(E2ETopologyKind::OperatorGatewayAgent),
        );

        expect($script)
            ->toContain(
                "incus copy 'orbit-template-operator-branch-a-b/clean-operator_gateway_app-dev_app-prod_agent_websocket-branch-a-b'",
            )
            ->toContain(
                "incus copy 'orbit-template-agent-base/clean-operator_gateway_app-dev_app-prod_agent_websocket-base'",
            )
            ->not->toContain("incus copy 'orbit-template-operator-base/");
    });
});

it('creates the acquisition ssh key pair in one host call', function (): void {
    $commands = [];
    $host = new class(incusRoundTripsConfig(), $commands) extends IncusHost {
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

            return incusRoundTripsResult();
        }
    };

    $provider = new IncusTopologyProvider(incusRoundTripsConfig());
    $method = new ReflectionMethod($provider, 'createSshKeyPair');
    $method->setAccessible(true);
    $method->invoke($provider, $host, 'runKeys');

    expect($host->commands)
        ->toHaveCount(1)
        ->and($host->commands[0])
        ->toContain('mkdir -p')
        ->toContain('ssh-keygen -t ed25519');
});

it('cleans up acquisition instances through one bulk host call', function (): void {
    $commands = [];
    $host = new class(incusRoundTripsConfig(), $commands) extends IncusHost {
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

            return incusRoundTripsResult();
        }
    };

    $provider = new IncusTopologyProvider(incusRoundTripsConfig());
    $method = new ReflectionMethod($provider, 'bulkCleanupFor');
    $method->setAccessible(true);
    $cleanup = $method->invoke($provider, $host, [
        'operator' => new IncusInstance($host, 'clone-operator', commandTransport: true),
        'gateway' => new IncusInstance($host, 'clone-gateway', commandTransport: true),
    ]);

    $cleanup(new E2EPhaseTimer);

    expect($host->commands)
        ->toHaveCount(1)
        ->and($host->commands[0])
        ->toContain("incus delete --force 'clone-operator'")
        ->toContain("incus delete --force 'clone-gateway'");
});

it('does not require a mutation generation when source cleanup is the live worktree', function (): void {
    $commands = [];
    $host = new class(incusRoundTripsConfig(), $commands) extends IncusHost {
        /** @param array<int, string> $commands */
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

            return incusRoundTripsResult();
        }
    };

    $provider = new IncusTopologyProvider(incusRoundTripsConfig());
    $method = new ReflectionMethod($provider, 'acquisitionFailureAfterCleanup');
    $method->setAccessible(true);
    $original = new \RuntimeException('cd: /home/orbit/orbit: Permission denied');

    $result = $method->invoke(
        $provider,
        $original,
        $host,
        ['clone-operator'],
        repo_path(),
    );

    expect($result)
        ->toBe($original)
        ->and($result->getMessage())
        ->not->toContain('Scoped source cleanup requires an active mutation generation.')->and(implode(
            "\n",
            $host->commands,
        ))->toContain("incus delete --force 'clone-operator'")
        ->not->toContain(escapeshellarg(repo_path()));
});

it('cleans up a scoped source path with its source-mounted lease', function (): void {
    $commands = [];
    $host = new class(incusRoundTripsConfig(), $commands) extends IncusHost {
        /** @param array<int, string> $commands */
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

            return incusRoundTripsResult();
        }
    };

    $provider = new IncusTopologyProvider(incusRoundTripsConfig());
    $method = new ReflectionMethod($provider, 'bulkCleanupFor');
    $method->setAccessible(true);
    $cleanup = $method->invoke(
        $provider,
        $host,
        ['operator' => new IncusInstance($host, 'clone-operator', commandTransport: true)],
        '/tmp/orbit-source/retained/dev-abc123',
    );

    $cleanup(new E2EPhaseTimer);

    expect($host->commands)
        ->toHaveCount(2)
        ->and($host->commands[1])
        ->toContain('mutation_lock=')
        ->toContain('flock -w 1200 -x 8')
        ->toContain("target='/tmp/orbit-source/retained/dev-abc123'")
        ->toContain('find "$target" -mindepth 1 -maxdepth 1');
});

it('does not remove a scoped source when instance absence cannot be verified', function (): void {
    $commands = [];
    $host = new class(incusRoundTripsConfig(), $commands) extends IncusHost {
        /** @param array<int, string> $commands */
        public function __construct(
            E2EConfig $config,
            public array &$commands,
        ) {
            parent::__construct($config);
        }

        #[Override]
        public function deleteInstancesIfPresent(array $names): ProcessResult
        {
            return incusRoundTripsResult(successful: false);
        }

        #[Override]
        public function run(string $command, ?int $timeoutSeconds = null): ProcessResult
        {
            $this->commands[] = $command;

            return incusRoundTripsResult();
        }
    };

    $provider = new IncusTopologyProvider(incusRoundTripsConfig());
    $method = new ReflectionMethod($provider, 'bulkCleanupFor');
    $method->setAccessible(true);
    $cleanup = $method->invoke(
        $provider,
        $host,
        ['operator' => new IncusInstance($host, 'clone-operator', commandTransport: true)],
        '/tmp/orbit-source/retained/dev-abc123',
    );

    expect(fn () => $cleanup(new E2EPhaseTimer))
        ->toThrow(RuntimeException::class, 'Could not verify cleanup of Incus instances')
        ->and($commands)
        ->toBeEmpty();
});

it('does not start scoped cleanup when its lifecycle lock is unavailable', function (): void {
    $commands = [];
    $host = new class(incusRoundTripsConfig(), $commands) extends IncusHost {
        /** @param array<int, string> $commands */
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

            return incusRoundTripsResult();
        }
    };
    Process::fake(static fn (PendingProcess $process): ProcessResult => str_contains(
        (string) $process->command,
        'flock -w 30 9',
    )
            ? Process::result(errorOutput: 'lock timeout', exitCode: 1)
            : Process::result());

    $provider = new IncusTopologyProvider(incusRoundTripsConfig());
    $method = new ReflectionMethod($provider, 'bulkCleanupFor');
    $method->setAccessible(true);
    $cleanup = $method->invoke(
        $provider,
        $host,
        ['operator' => new IncusInstance($host, 'clone-operator', commandTransport: true)],
        '/tmp/orbit-source/retained/dev-abc123',
    );

    expect(fn () => $cleanup(new E2EPhaseTimer))
        ->toThrow(RuntimeException::class, 'lock timeout')
        ->and($commands)
        ->toBeEmpty();
});
