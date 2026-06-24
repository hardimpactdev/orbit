<?php

declare(strict_types=1);

use App\Console\Commands\E2EPrepareTopologyCommand;
use App\E2E\Support\E2EPhaseTimer;
use App\E2E\Support\E2EPreparedTopology;
use App\E2E\Support\E2ETopologyKind;
use App\E2E\Support\IncusHost;
use App\E2E\Support\IncusTopologyBuilder;
use App\E2E\Support\IncusTopologyTemplate;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Process;
use Mockery as m;

beforeEach(function (): void {
    Process::preventStrayProcesses();
});

afterEach(function (): void {
    m::close();
});

function fakeBundleProcessing(): void
{
    Process::fake([
        // Local source archive build (default tar path).
        'COPYFILE_DISABLE=1 tar *' => Process::result(),
        'tar *' => Process::result(),
        // git archive (when --branch is given).
        'git -C *archive*' => Process::result(),
        // Composer cache copy.
        'cp -R *' => Process::result(),
        // Local bundle cleanup.
        'rm -rf *' => Process::result(),
        // Remote ops (SSH-wrapped). pushBundle creates a stage dir and an
        // orbit-e2e-bundle subdir, then scps into it.
        'ssh *mktemp -d /tmp/orbit-e2e-cli-binary*' => Process::result(output: "/tmp/orbit-e2e-cli-binary-remote\n"),
        'ssh *mktemp -d /tmp/orbit-e2e-gateway-artifacts*' => Process::result(
            output: "/tmp/orbit-e2e-gateway-artifacts-remote\n",
        ),
        'ssh *mktemp -d /tmp/orbit-e2e-stage*' => Process::result(output: "/tmp/orbit-e2e-stage-remote\n"),
        'ssh *' => Process::result(),
        'scp *' => Process::result(),
        'rsync *' => Process::result(),
    ]);
}

it('defaults to the websocket-capable prepared full Incus source kind', function (): void {
    $this
        ->artisan('e2e:prepare-topology')
        ->expectsOutputToContain(
            'planned: orbit-template-operator-base (snapshot: clean-operator_gateway_app-dev_app-prod_agent_websocket-base)',
        )
        ->expectsOutputToContain(
            'planned: orbit-template-gateway-base (snapshot: clean-operator_gateway_app-dev_app-prod_agent_websocket-base)',
        )
        ->expectsOutputToContain(
            'planned: orbit-template-app-dev-base (snapshot: clean-operator_gateway_app-dev_app-prod_agent_websocket-base)',
        )
        ->expectsOutputToContain(
            'planned: orbit-template-app-prod-base (snapshot: clean-operator_gateway_app-dev_app-prod_agent_websocket-base)',
        )
        ->expectsOutputToContain(
            'planned: orbit-template-agent-base (snapshot: clean-operator_gateway_app-dev_app-prod_agent_websocket-base)',
        )
        ->assertSuccessful();
});

it('plans Incus warm topology stateful snapshot slots', function (): void {
    withE2EEnvironment(
        [
            'ORBIT_E2E_INCUS_HOSTS',
        ],
        [
            'ORBIT_E2E_INCUS_HOSTS' => 'beast',
            'ORBIT_E2E_INCUS_HOST_VM_CAPS' => 'beast:4',
        ],
        function (): void {
            $this
                ->artisan('e2e:prepare-warm-topology', [
                    'kind' => 'operator_gateway',
                    '--slots' => '1',
                ])
                ->expectsOutputToContain('Dry run. Pass --force to create Incus warm stateful snapshots.')
                ->expectsOutputToContain('requested topology: operator_gateway')
                ->expectsOutputToContain('planned: slot 1 (snapshot: warm-ready)')
                ->expectsOutputToContain('instance: orbit-e2e-warm-')
                ->assertSuccessful();
        },
    );
});

it('renders Incus warm topology dry-run output as json', function (): void {
    withE2EEnvironment(
        [
            'ORBIT_E2E_INCUS_HOSTS',
        ],
        [
            'ORBIT_E2E_INCUS_HOSTS' => 'beast',
            'ORBIT_E2E_INCUS_HOST_VM_CAPS' => 'beast:4',
        ],
        function (): void {
            $exitCode = Artisan::call('e2e:prepare-warm-topology', [
                'kind' => 'operator_gateway_agent',
                '--slots' => '1',
                '--json' => true,
            ]);

            $payload = json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR);

            expect($exitCode)
                ->toBe(0)
                ->and($payload['success']['data']['dry_run'])
                ->toBeTrue()
                ->and($payload['success']['data']['kind'])
                ->toBe('operator_gateway_agent')
                ->and($payload['success']['data']['slots'][0]['snapshot'])
                ->toBe('warm-ready')
                ->and($payload['success']['data']['slots'][0]['instances'])
                ->toHaveCount(3);
        },
    );
});

it('rejects warm topology slots that exceed the host VM capacity', function (): void {
    withE2EEnvironment(
        [
            'ORBIT_E2E_INCUS_HOSTS',
        ],
        [
            'ORBIT_E2E_INCUS_HOSTS' => 'beast',
            'ORBIT_E2E_INCUS_HOST_VM_CAPS' => 'beast:2',
        ],
        function (): void {
            $this
                ->artisan('e2e:prepare-warm-topology', [
                    'kind' => 'operator_gateway',
                    '--slots' => '2',
                ])
                ->expectsOutputToContain('requested 2 slots, but beast can fit 1 warm slot')
                ->assertFailed();
        },
    );
});

it('supports operator kind', function (): void {
    $this
        ->artisan('e2e:prepare-topology', ['kind' => 'operator'])
        ->expectsOutputToContain('requested roles: operator')
        ->expectsOutputToContain('source topology: operator_gateway_app-dev_app-prod_agent_websocket')
        ->expectsOutputToContain('source roles: operator, gateway, dev, prod, agent')
        ->expectsOutputToContain(
            'planned: orbit-template-operator-base (snapshot: clean-operator_gateway_app-dev_app-prod_agent_websocket-base)',
        )
        ->expectsOutputToContain(
            'planned: orbit-template-gateway-base (snapshot: clean-operator_gateway_app-dev_app-prod_agent_websocket-base)',
        )
        ->expectsOutputToContain(
            'planned: orbit-template-app-dev-base (snapshot: clean-operator_gateway_app-dev_app-prod_agent_websocket-base)',
        )
        ->expectsOutputToContain(
            'planned: orbit-template-app-prod-base (snapshot: clean-operator_gateway_app-dev_app-prod_agent_websocket-base)',
        )
        ->expectsOutputToContain(
            'planned: orbit-template-agent-base (snapshot: clean-operator_gateway_app-dev_app-prod_agent_websocket-base)',
        )
        ->assertSuccessful();
});

it('supports operator_gateway kind', function (): void {
    $this
        ->artisan('e2e:prepare-topology', ['kind' => 'operator_gateway'])
        ->expectsOutputToContain('requested roles: operator, gateway')
        ->expectsOutputToContain('source topology: operator_gateway_app-dev_app-prod_agent_websocket')
        ->expectsOutputToContain('source roles: operator, gateway, dev, prod, agent')
        ->expectsOutputToContain(
            'planned: orbit-template-operator-base (snapshot: clean-operator_gateway_app-dev_app-prod_agent_websocket-base)',
        )
        ->expectsOutputToContain(
            'planned: orbit-template-gateway-base (snapshot: clean-operator_gateway_app-dev_app-prod_agent_websocket-base)',
        )
        ->expectsOutputToContain(
            'planned: orbit-template-app-dev-base (snapshot: clean-operator_gateway_app-dev_app-prod_agent_websocket-base)',
        )
        ->expectsOutputToContain(
            'planned: orbit-template-app-prod-base (snapshot: clean-operator_gateway_app-dev_app-prod_agent_websocket-base)',
        )
        ->expectsOutputToContain(
            'planned: orbit-template-agent-base (snapshot: clean-operator_gateway_app-dev_app-prod_agent_websocket-base)',
        )
        ->assertSuccessful();
});

it('supports operator_gateway_app-dev kind', function (): void {
    $this
        ->artisan('e2e:prepare-topology', ['kind' => 'operator_gateway_app-dev'])
        ->expectsOutputToContain(
            'planned: orbit-template-operator-base (snapshot: clean-operator_gateway_app-dev_app-prod_agent_websocket-base)',
        )
        ->expectsOutputToContain(
            'planned: orbit-template-gateway-base (snapshot: clean-operator_gateway_app-dev_app-prod_agent_websocket-base)',
        )
        ->expectsOutputToContain(
            'planned: orbit-template-app-dev-base (snapshot: clean-operator_gateway_app-dev_app-prod_agent_websocket-base)',
        )
        ->expectsOutputToContain(
            'planned: orbit-template-app-prod-base (snapshot: clean-operator_gateway_app-dev_app-prod_agent_websocket-base)',
        )
        ->expectsOutputToContain(
            'planned: orbit-template-agent-base (snapshot: clean-operator_gateway_app-dev_app-prod_agent_websocket-base)',
        )
        ->assertSuccessful();
});

it('supports operator_gateway_agent kind', function (): void {
    $this
        ->artisan('e2e:prepare-topology', ['kind' => 'operator_gateway_agent'])
        ->expectsOutputToContain('requested roles: operator, gateway, agent')
        ->expectsOutputToContain('source topology: operator_gateway_app-dev_app-prod_agent_websocket')
        ->expectsOutputToContain('source roles: operator, gateway, dev, prod, agent')
        ->expectsOutputToContain(
            'planned: orbit-template-operator-base (snapshot: clean-operator_gateway_app-dev_app-prod_agent_websocket-base)',
        )
        ->expectsOutputToContain(
            'planned: orbit-template-gateway-base (snapshot: clean-operator_gateway_app-dev_app-prod_agent_websocket-base)',
        )
        ->expectsOutputToContain(
            'planned: orbit-template-app-dev-base (snapshot: clean-operator_gateway_app-dev_app-prod_agent_websocket-base)',
        )
        ->expectsOutputToContain(
            'planned: orbit-template-app-prod-base (snapshot: clean-operator_gateway_app-dev_app-prod_agent_websocket-base)',
        )
        ->expectsOutputToContain(
            'planned: orbit-template-agent-base (snapshot: clean-operator_gateway_app-dev_app-prod_agent_websocket-base)',
        )
        ->assertSuccessful();
});

it('supports operator_gateway_app-prod_ingress kind', function (): void {
    $this
        ->artisan('e2e:prepare-topology', ['kind' => 'operator_gateway_app-prod_ingress'])
        ->expectsOutputToContain('requested roles: operator, gateway, prod')
        ->expectsOutputToContain('source topology: operator_gateway_app-dev_app-prod_agent_websocket')
        ->expectsOutputToContain('source roles: operator, gateway, dev, prod, agent')
        ->expectsOutputToContain(
            'planned: orbit-template-operator-base (snapshot: clean-operator_gateway_app-dev_app-prod_agent_websocket-base)',
        )
        ->expectsOutputToContain(
            'planned: orbit-template-gateway-base (snapshot: clean-operator_gateway_app-dev_app-prod_agent_websocket-base)',
        )
        ->expectsOutputToContain(
            'planned: orbit-template-app-dev-base (snapshot: clean-operator_gateway_app-dev_app-prod_agent_websocket-base)',
        )
        ->expectsOutputToContain(
            'planned: orbit-template-app-prod-base (snapshot: clean-operator_gateway_app-dev_app-prod_agent_websocket-base)',
        )
        ->expectsOutputToContain(
            'planned: orbit-template-agent-base (snapshot: clean-operator_gateway_app-dev_app-prod_agent_websocket-base)',
        )
        ->doesntExpectOutputToContain('planned: orbit-template-ingress-base')
        ->assertSuccessful();
});

it('supports operator_gateway_app-dev_app-prod_ingress kind with a dedicated ingress template', function (): void {
    $this
        ->artisan('e2e:prepare-topology', ['kind' => 'operator_gateway_app-dev_app-prod_ingress'])
        ->expectsOutputToContain('requested roles: operator, gateway, dev, prod, ingress')
        ->expectsOutputToContain(
            'planned: orbit-template-operator-base (snapshot: clean-operator_gateway_app-dev_app-prod_ingress-base)',
        )
        ->expectsOutputToContain(
            'planned: orbit-template-gateway-base (snapshot: clean-operator_gateway_app-dev_app-prod_ingress-base)',
        )
        ->expectsOutputToContain(
            'planned: orbit-template-app-dev-base (snapshot: clean-operator_gateway_app-dev_app-prod_ingress-base)',
        )
        ->expectsOutputToContain(
            'planned: orbit-template-app-prod-base (snapshot: clean-operator_gateway_app-dev_app-prod_ingress-base)',
        )
        ->expectsOutputToContain(
            'planned: orbit-template-ingress-base (snapshot: clean-operator_gateway_app-dev_app-prod_ingress-base)',
        )
        ->assertSuccessful();
});

it('documents Incus topology templates in a separate namespace', function (): void {
    withE2ETopologyEnvironment([], function (): void {
        $this
            ->artisan('e2e:prepare-topology', ['kind' => 'operator_gateway_app-dev_app-prod_agent'])
            ->expectsOutputToContain(
                'planned: orbit-template-operator-base (snapshot: clean-operator_gateway_app-dev_app-prod_agent_websocket-base)',
            )
            ->expectsOutputToContain(
                'planned: orbit-template-gateway-base (snapshot: clean-operator_gateway_app-dev_app-prod_agent_websocket-base)',
            )
            ->expectsOutputToContain(
                'planned: orbit-template-app-dev-base (snapshot: clean-operator_gateway_app-dev_app-prod_agent_websocket-base)',
            )
            ->expectsOutputToContain(
                'planned: orbit-template-app-prod-base (snapshot: clean-operator_gateway_app-dev_app-prod_agent_websocket-base)',
            )
            ->expectsOutputToContain(
                'planned: orbit-template-agent-base (snapshot: clean-operator_gateway_app-dev_app-prod_agent_websocket-base)',
            )
            ->doesntExpectOutputToContain(
                'planned: orbit-template-operator (snapshot: clean-operator_gateway_app-dev_app-prod_agent)',
            )
            ->assertSuccessful();
    });
});

it('rejects custom Incus artifact namespace preparation without explicit roles', function (): void {
    Process::fake();

    withE2ETopologyEnvironment([
        'ORBIT_E2E_TOPOLOGY_ARTIFACT_NAMESPACE' => 'Agent isolation',
    ], function (): void {
        $this
            ->artisan('e2e:prepare-topology', ['kind' => 'operator_gateway_agent'])
            ->expectsOutputToContain('Set --roles or --all-roles when ORBIT_E2E_TOPOLOGY_ARTIFACT_NAMESPACE is set')
            ->assertFailed();
    });

    Process::assertNothingRan();
});

it('rejects selected Incus role preparation without a custom namespace', function (): void {
    Process::fake();

    withE2ETopologyEnvironment([], function (): void {
        $this
            ->artisan('e2e:prepare-topology', [
                'kind' => 'operator_gateway_agent',
                '--roles' => 'agent',
            ])
            ->expectsOutputToContain(
                'Set ORBIT_E2E_TOPOLOGY_ARTIFACT_NAMESPACE when using --roles for Incus selected-role rebakes',
            )
            ->assertFailed();
    });

    Process::assertNothingRan();
});

it('plans only selected branch Incus role templates', function (): void {
    Process::fake();

    withE2ETopologyEnvironment([
        'ORBIT_E2E_TOPOLOGY_ARTIFACT_NAMESPACE' => 'Agent isolation',
    ], function (): void {
        $this
            ->artisan('e2e:prepare-topology', [
                'kind' => 'operator_gateway_agent',
                '--roles' => 'agent',
            ])
            ->expectsOutputToContain(
                'planned: orbit-template-agent-agent-isolation (snapshot: clean-operator_gateway_app-dev_app-prod_agent_websocket-agent-isolation)',
            )
            ->doesntExpectOutputToContain('orbit-template-operator-agent-isolation')
            ->doesntExpectOutputToContain('orbit-template-gateway-agent-isolation')
            ->doesntExpectOutputToContain('orbit-template-app-dev-agent-isolation')
            ->doesntExpectOutputToContain('orbit-template-app-prod-agent-isolation')
            ->assertSuccessful();
    });

    Process::assertNothingRan();
});

it('accepts targeted Incus role preparation when forced with a custom namespace', function (): void {
    fakeBundleProcessing();

    $manifest = [
        [
            'role' => 'agent',
            'name' => 'orbit-template-agent-agent-isolation',
            'snapshot' => 'clean-operator_gateway_app-dev_app-prod_agent_websocket-agent-isolation',
        ],
    ];

    $builder = m::mock(IncusTopologyBuilder::class);
    $builder->shouldReceive('useBundle')->once();
    $builder
        ->shouldReceive('buildSelectedRoles')
        ->with(E2ETopologyKind::OperatorGatewayAppdevAppprodAgentWebsocket, ['agent'], true)
        ->andReturn($manifest);

    $command = app(E2EPrepareTopologyCommand::class);
    $command->setBuilderFactory(fn () => $builder);
    $this->app->instance(E2EPrepareTopologyCommand::class, $command);

    withE2ETopologyEnvironment([
        'ORBIT_E2E_TOPOLOGY_ARTIFACT_NAMESPACE' => 'Agent isolation',
    ], function (): void {
        $this
            ->artisan('e2e:prepare-topology', [
                'kind' => 'operator_gateway_agent',
                '--force' => true,
                '--use-build-artifacts' => true,
                '--roles' => 'agent',
            ])
            ->doesntExpectOutputToContain('not implemented')
            ->assertSuccessful();
    });
});

it('rejects invalid kind', function (): void {
    $this
        ->artisan('e2e:prepare-topology', ['kind' => 'invalid'])
        ->expectsOutputToContain('Invalid topology kind')
        ->assertFailed();
});

it('defaults to dry run', function (): void {
    $this
        ->artisan('e2e:prepare-topology')
        ->expectsOutputToContain('Dry run. Pass --force to create Incus topology templates.')
        ->assertSuccessful();
});

it('outputs json for dry run with default kind', function (): void {
    $kind = E2EPreparedTopology::incusSourceKindFor(E2ETopologyKind::OperatorGatewayAppdevAppprodAgent);
    $templates = [];

    foreach (IncusTopologyTemplate::rolesFor($kind) as $role) {
        $templates[] = [
            'role' => $role,
            'name' => IncusTopologyTemplate::templateName($kind, $role),
            'snapshot' => IncusTopologyTemplate::snapshotName($kind),
        ];
    }

    $expected = json_encode([
        'success' => [
            'data' => [
                'provider' => 'incus',
                'dry_run' => true,
                'kind' => 'operator_gateway_app-dev_app-prod_agent',
                'source_kind' => $kind->value,
                'requested_roles' => IncusTopologyTemplate::rolesFor(E2ETopologyKind::OperatorGatewayAppdevAppprodAgent),
                'source_roles' => IncusTopologyTemplate::rolesFor($kind),
                'templates' => $templates,
            ],
        ],
    ], JSON_THROW_ON_ERROR);

    $this
        ->artisan('e2e:prepare-topology', ['--json' => true])
        ->expectsOutput($expected)
        ->assertSuccessful();
});

it('outputs json for each supported kind', function (string $kindValue): void {
    $kind = E2ETopologyKind::tryFromInput($kindValue);
    expect($kind)->not->toBeNull();

    $buildKind = E2EPreparedTopology::incusSourceKindFor($kind);
    $templates = [];

    foreach (IncusTopologyTemplate::rolesFor($buildKind) as $role) {
        $templates[] = [
            'role' => $role,
            'name' => IncusTopologyTemplate::templateName($buildKind, $role),
            'snapshot' => IncusTopologyTemplate::snapshotName($buildKind),
        ];
    }

    $expected = json_encode([
        'success' => [
            'data' => [
                'provider' => 'incus',
                'dry_run' => true,
                'kind' => $kind->value,
                'source_kind' => $buildKind->value,
                'requested_roles' => IncusTopologyTemplate::rolesFor($kind),
                'source_roles' => IncusTopologyTemplate::rolesFor($buildKind),
                'templates' => $templates,
            ],
        ],
    ], JSON_THROW_ON_ERROR);

    $this
        ->artisan('e2e:prepare-topology', [
            'kind' => $kindValue,
            '--json' => true,
        ])
        ->expectsOutput($expected)
        ->assertSuccessful();
})->with([
    ['operator'],
    ['operator_gateway'],
    ['operator_gateway_app-dev'],
    ['operator_gateway_app-dev_app-prod'],
    ['operator_gateway_agent'],
    ['operator_gateway_app-dev_app-prod_agent'],
    ['operator_gateway_app-prod_ingress'],
    ['operator_gateway_app-dev_app-prod_ingress'],
    ['operator'],
    ['operator-gateway'],
    ['operator-gateway-dev'],
    ['operator-gateway-dev-prod'],
    ['operator-gateway-dev-prod-ingress'],
]);

it('outputs json error for invalid kind', function (): void {
    $expected = json_encode([
        'error' => [
            'code' => 'validation_failed',
            'message' => 'Invalid topology kind [invalid]. Supported: operator, operator_gateway, operator_gateway_app-dev, operator_gateway_app-dev_app-prod, operator_gateway_app-dev_app-prod_ingress, operator_gateway_agent, operator_gateway_app-dev_app-prod_agent, operator_gateway_app-prod_ingress, operator_gateway_app-dev_websocket, operator_gateway_app-dev_app-prod_websocket, and operator_gateway_app-dev_app-prod_agent_websocket.',
        ],
    ], JSON_THROW_ON_ERROR);

    $this
        ->artisan('e2e:prepare-topology', [
            'kind' => 'invalid',
            '--json' => true,
        ])
        ->expectsOutput($expected)
        ->assertFailed();
});

it('is hidden', function (): void {
    $command = app(E2EPrepareTopologyCommand::class);

    expect($command->isHidden())->toBeTrue();
});

it('--force uses the default Incus host when host environment is unset', function (): void {
    fakeBundleProcessing();

    $previousHosts = getenv('ORBIT_E2E_INCUS_HOSTS');
    $previousHost = getenv('ORBIT_E2E_HOST');
    $previousProvider = getenv('ORBIT_E2E_PROVIDER');
    $manifest = [
        [
            'role' => 'operator',
            'name' => 'orbit-template-operator-base',
            'snapshot' => 'clean-operator_gateway_app-dev_app-prod_agent_websocket-base',
        ],
        [
            'role' => 'gateway',
            'name' => 'orbit-template-gateway-base',
            'snapshot' => 'clean-operator_gateway_app-dev_app-prod_agent_websocket-base',
        ],
        [
            'role' => 'dev',
            'name' => 'orbit-template-app-dev-base',
            'snapshot' => 'clean-operator_gateway_app-dev_app-prod_agent_websocket-base',
        ],
        [
            'role' => 'prod',
            'name' => 'orbit-template-app-prod-base',
            'snapshot' => 'clean-operator_gateway_app-dev_app-prod_agent_websocket-base',
        ],
        [
            'role' => 'agent',
            'name' => 'orbit-template-agent-base',
            'snapshot' => 'clean-operator_gateway_app-dev_app-prod_agent_websocket-base',
        ],
    ];
    $selectedHost = null;

    $builder = m::mock(IncusTopologyBuilder::class);
    $builder
        ->shouldReceive('useGatewayArtifactBundle')
        ->once()
        ->with('/tmp/orbit-e2e-gateway-artifacts-remote');
    $builder
        ->shouldReceive('build')
        ->with(E2ETopologyKind::OperatorGatewayAppdevAppprodAgentWebsocket, true)
        ->andReturn($manifest);

    $command = app(E2EPrepareTopologyCommand::class);
    $command->setBuilderFactory(function (IncusHost $host) use ($builder, &$selectedHost): IncusTopologyBuilder {
        $selectedHost = $host->config->host;

        return $builder;
    });
    $this->app->instance(E2EPrepareTopologyCommand::class, $command);

    putenv('ORBIT_E2E_INCUS_HOSTS=');
    putenv('ORBIT_E2E_HOST=');
    putenv('ORBIT_E2E_PROVIDER=incus');

    try {
        $this->artisan('e2e:prepare-topology', [
            'kind' => 'operator',
            '--force' => true,
        ])
            ->assertSuccessful();

        expect($selectedHost)->toBe('beast');
    } finally {
        $previousHosts === false ? putenv('ORBIT_E2E_INCUS_HOSTS') : putenv("ORBIT_E2E_INCUS_HOSTS={$previousHosts}");
        $previousHost === false ? putenv('ORBIT_E2E_HOST') : putenv("ORBIT_E2E_HOST={$previousHost}");
        $previousProvider === false ? putenv('ORBIT_E2E_PROVIDER') : putenv("ORBIT_E2E_PROVIDER={$previousProvider}");
    }
});

it('--force builds the source archive and forwards the bundle path to the builder', function (): void {
    fakeBundleProcessing();

    $manifest = [
        ['role' => 'operator', 'name' => 'orbit-template-operator', 'snapshot' => 'clean-operator'],
    ];
    $forwardedBundle = null;

    $builder = m::mock(IncusTopologyBuilder::class);
    $builder
        ->shouldReceive('useBundle')
        ->once()
        ->andReturnUsing(function (string $path) use (&$forwardedBundle): void {
            $forwardedBundle = $path;
        });
    $builder
        ->shouldReceive('build')
        ->with(E2ETopologyKind::OperatorGatewayAppdevAppprodAgentWebsocket, true)
        ->andReturn($manifest);

    $command = app(E2EPrepareTopologyCommand::class);
    $command->setBuilderFactory(fn () => $builder);
    $this->app->instance(E2EPrepareTopologyCommand::class, $command);

    $this->artisan('e2e:prepare-topology', [
        'kind' => 'operator',
        '--force' => true,
        '--use-build-artifacts' => true,
    ])->assertSuccessful();

    expect($forwardedBundle)->toBe('/tmp/orbit-e2e-stage-remote/orbit-e2e-bundle');
    Process::assertRan(
        fn (PendingProcess $p): bool => (
            str_contains((string) $p->command, 'tar ') && str_contains((string) $p->command, '-czf')
        ),
    );
});

it('--force excludes persisted orbit gateway state from the source archive', function (): void {
    $tarCommand = null;

    Process::fake(function ($process) use (&$tarCommand) {
        $command = (string) $process->command;

        if (str_starts_with($command, 'COPYFILE_DISABLE=1 tar ')) {
            $tarCommand = $command;
        }

        if (str_starts_with($command, 'ssh ') && str_contains($command, 'mktemp -d /tmp/orbit-e2e-stage')) {
            return Process::result(output: "/tmp/orbit-e2e-stage-remote\n");
        }

        return Process::result();
    });

    $builder = m::mock(IncusTopologyBuilder::class);
    $builder->shouldReceive('useBundle')->once();
    $builder
        ->shouldReceive('build')
        ->with(E2ETopologyKind::OperatorGatewayAppdevAppprodAgentWebsocket, true)
        ->andReturn([]);

    $command = app(E2EPrepareTopologyCommand::class);
    $command->setBuilderFactory(fn () => $builder);
    $this->app->instance(E2EPrepareTopologyCommand::class, $command);

    $this->artisan('e2e:prepare-topology', [
        'kind' => 'operator',
        '--force' => true,
        '--use-build-artifacts' => true,
    ])->assertSuccessful();

    expect($tarCommand)
        ->toContain("--exclude='./apps/gateway/storage/app/orbit/*'")
        ->toContain("--exclude='./apps/gateway/bootstrap/cache/*.php'");
});

it('--force records prepare topology phase timings', function (): void {
    fakeBundleProcessing();

    $manifest = [
        ['role' => 'operator', 'name' => 'orbit-template-operator', 'snapshot' => 'clean-operator'],
    ];
    $capturedTimer = null;

    $builder = m::mock(IncusTopologyBuilder::class);
    $builder
        ->shouldReceive('useGatewayArtifactBundle')
        ->once()
        ->with('/tmp/orbit-e2e-gateway-artifacts-remote');
    $builder
        ->shouldReceive('build')
        ->with(E2ETopologyKind::OperatorGatewayAppdevAppprodAgentWebsocket, true)
        ->andReturn($manifest);

    $command = app(E2EPrepareTopologyCommand::class);
    $command->setBuilderFactory(function (IncusHost $host, E2EPhaseTimer $timer) use (
        $builder,
        &$capturedTimer,
    ): IncusTopologyBuilder {
        $capturedTimer = $timer;

        return $builder;
    });
    $this->app->instance(E2EPrepareTopologyCommand::class, $command);

    $this->artisan('e2e:prepare-topology', [
        'kind' => 'operator',
        '--force' => true,
    ])->assertSuccessful();

    $eventNames = array_column($capturedTimer?->events() ?? [], 'name');

    expect($capturedTimer)
        ->toBeInstanceOf(E2EPhaseTimer::class)
        ->and($capturedTimer->streamsCheckpoints())
        ->toBeFalse()
        ->and($eventNames)
        ->toContain('gateway-artifacts.local')
        ->and($eventNames)
        ->toContain('gateway-artifacts.push')
        ->and($eventNames)
        ->toContain('builder.build')
        ->and($eventNames)
        ->not->toContain('gateway-artifacts.fingerprint')->and($eventNames)
        ->not->toContain('builder.reuse-check');
});

it('builds the gateway artifact image on the Incus host', function (): void {
    $command = file_get_contents(app_path('Console/Commands/E2EPrepareTopologyCommand.php'));

    expect($command)
        ->toContain('gateway-build-context')
        ->toContain('rsync -a --delete')
        ->toContain("'apps/gateway', 'packages/core', 'packages/sdk', 'docker/orbit-gateway'")
        ->toContain('docker build -f docker/orbit-gateway/Dockerfile')
        ->toContain('docker save')
        ->toContain('docker/orbit-gateway/Dockerfile')
        ->toContain('bin/install-orbit')
        ->toContain('VERSION');
});

it('refreshes SDK dependencies in Incus selected-role source overlays', function (): void {
    $builder = file_get_contents(app_path('E2E/Support/IncusTopologyBuilder.php'));

    expect($builder)
        ->toContain('for app in apps/gateway apps/cli apps/e2e packages/core packages/sdk apps/docs; do');
});

it('--branch uses git archive instead of tar', function (): void {
    fakeBundleProcessing();

    $manifest = [
        ['role' => 'operator', 'name' => 'orbit-template-operator', 'snapshot' => 'clean-operator'],
    ];

    $builder = m::mock(IncusTopologyBuilder::class);
    $builder->shouldReceive('useBundle')->once();
    $builder->shouldReceive('build')->andReturn($manifest);

    $command = app(E2EPrepareTopologyCommand::class);
    $command->setBuilderFactory(fn () => $builder);
    $this->app->instance(E2EPrepareTopologyCommand::class, $command);

    $this->artisan('e2e:prepare-topology', [
        'kind' => 'operator',
        '--force' => true,
        '--use-build-artifacts' => true,
        '--branch' => 'main',
    ])->assertSuccessful();

    Process::assertRan(
        fn (PendingProcess $p): bool => (
            str_contains((string) $p->command, 'git -C')
            && str_contains((string) $p->command, 'archive')
            && str_contains((string) $p->command, "'main'")
        ),
    );
});

it('--source-archive forwards the provided archive', function (): void {
    fakeBundleProcessing();

    $tempArchive = tempnam(sys_get_temp_dir(), 'orbit-archive-').'.tar.gz';
    file_put_contents($tempArchive, 'fake');

    try {
        $manifest = [
            ['role' => 'operator', 'name' => 'orbit-template-operator', 'snapshot' => 'clean-operator'],
        ];

        $builder = m::mock(IncusTopologyBuilder::class);
        $builder->shouldReceive('useBundle')->once();
        $builder->shouldReceive('build')->andReturn($manifest);

        $command = app(E2EPrepareTopologyCommand::class);
        $command->setBuilderFactory(fn () => $builder);
        $this->app->instance(E2EPrepareTopologyCommand::class, $command);

        $this->artisan('e2e:prepare-topology', [
            'kind' => 'operator',
            '--force' => true,
            '--use-build-artifacts' => true,
            '--source-archive' => $tempArchive,
        ])->assertSuccessful();

        Process::assertNotRan(
            fn (PendingProcess $p): bool => (
                str_contains((string) $p->command, 'tar ') && str_contains((string) $p->command, '-czf')
            ),
        );
        Process::assertNotRan(
            fn (PendingProcess $p): bool => (
                str_contains((string) $p->command, 'git -C') && str_contains((string) $p->command, 'archive')
            ),
        );
    } finally {
        @unlink($tempArchive);
    }
});

it('--source-archive fails clearly when archive is missing', function (): void {
    fakeBundleProcessing();

    $command = app(E2EPrepareTopologyCommand::class);
    $command->setBuilderFactory(fn () => m::mock(IncusTopologyBuilder::class));
    $this->app->instance(E2EPrepareTopologyCommand::class, $command);

    $this
        ->artisan('e2e:prepare-topology', [
            'kind' => 'operator',
            '--force' => true,
            '--use-build-artifacts' => true,
            '--source-archive' => '/tmp/orbit-source-does-not-exist.tar.gz',
        ])
        ->expectsOutputToContain('--source-archive not found')
        ->assertFailed();
});

it('--composer-cache fails clearly when an explicit cache directory is missing', function (): void {
    fakeBundleProcessing();

    $command = app(E2EPrepareTopologyCommand::class);
    $command->setBuilderFactory(fn () => m::mock(IncusTopologyBuilder::class));
    $this->app->instance(E2EPrepareTopologyCommand::class, $command);

    $this
        ->artisan('e2e:prepare-topology', [
            'kind' => 'operator',
            '--force' => true,
            '--use-build-artifacts' => true,
            '--composer-cache' => '/tmp/orbit-composer-cache-does-not-exist',
        ])
        ->expectsOutputToContain('--composer-cache directory not found')
        ->assertFailed();
});

it('--force outputs JSON success envelope when builder returns a manifest', function (): void {
    fakeBundleProcessing();

    $manifest = [
        ['role' => 'operator', 'name' => 'orbit-template-operator', 'snapshot' => 'clean-operator'],
    ];

    $builder = m::mock(IncusTopologyBuilder::class);
    $builder->shouldReceive('useGatewayArtifactBundle')->once();
    $builder
        ->shouldReceive('build')
        ->with(E2ETopologyKind::OperatorGatewayAppdevAppprodAgentWebsocket, true)
        ->andReturn($manifest);

    $command = app(E2EPrepareTopologyCommand::class);
    $command->setBuilderFactory(fn () => $builder);
    $this->app->instance(E2EPrepareTopologyCommand::class, $command);

    $expected = json_encode([
        'success' => [
            'data' => [
                'provider' => 'incus',
                'dry_run' => false,
                'kind' => 'operator',
                'source_kind' => 'operator_gateway_app-dev_app-prod_agent_websocket',
                'requested_roles' => ['operator'],
                'source_roles' => ['operator', 'gateway', 'dev', 'prod', 'agent'],
                'templates' => $manifest,
            ],
        ],
    ], JSON_THROW_ON_ERROR);

    $this
        ->artisan('e2e:prepare-topology', [
            'kind' => 'operator',
            '--force' => true,
            '--json' => true,
        ])
        ->expectsOutput($expected)
        ->assertSuccessful();
});

it('--force surfaces builder failure as command failure', function (): void {
    fakeBundleProcessing();

    $builder = m::mock(IncusTopologyBuilder::class);
    $builder->shouldReceive('useGatewayArtifactBundle');
    $builder
        ->shouldReceive('build')
        ->andThrow(new RuntimeException('Required base image [orbit-base-ubuntu-26.04-runtime] not found.'));

    $command = app(E2EPrepareTopologyCommand::class);
    $command->setBuilderFactory(fn () => $builder);
    $this->app->instance(E2EPrepareTopologyCommand::class, $command);

    $this
        ->artisan('e2e:prepare-topology', [
            'kind' => 'operator',
            '--force' => true,
        ])
        ->expectsOutputToContain('Required base image [orbit-base-ubuntu-26.04-runtime] not found.')
        ->assertFailed();
});
