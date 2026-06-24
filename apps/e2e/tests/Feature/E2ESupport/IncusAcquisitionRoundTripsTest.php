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
use Mockery as m;

afterEach(function (): void {
    m::close();
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
