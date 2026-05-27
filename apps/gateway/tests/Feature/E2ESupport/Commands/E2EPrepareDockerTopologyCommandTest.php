<?php

declare(strict_types=1);

use App\Console\Commands\E2EPrepareDockerTopologyCommand;
use App\E2E\Support\E2ETopologyKind;
use Illuminate\Support\Facades\Process;
use Mockery as m;

beforeEach(function (): void {
    Process::preventStrayProcesses();
});

afterEach(function (): void {
    m::close();
});

it('documents docker topology image preparation without force', function (): void {
    Process::fake();

    $this->artisan('e2e:prepare-docker-topology', ['kind' => 'operator_gateway_app-dev_app-prod'])
        ->expectsOutputToContain('orbit-e2e:operator_base')
        ->expectsOutputToContain('orbit-e2e:gateway_base')
        ->expectsOutputToContain('orbit-e2e:app-dev_base')
        ->expectsOutputToContain('orbit-e2e:app-prod_base')
        ->expectsOutputToContain('orbit-e2e:agent_base')
        ->doesntExpectOutputToContain('orbit-e2e:operator_operator')
        ->doesntExpectOutputToContain('operator_gateway')
        ->expectsOutputToContain('Dry run')
        ->assertSuccessful();

    Process::assertNothingRan();
});

it('documents ingress docker topology image preparation without force', function (): void {
    Process::fake();

    $this->artisan('e2e:prepare-docker-topology', ['kind' => 'operator_gateway_app-prod_ingress'])
        ->expectsOutputToContain('orbit-e2e:operator_base')
        ->expectsOutputToContain('orbit-e2e:gateway_base')
        ->expectsOutputToContain('orbit-e2e:app-dev_base')
        ->expectsOutputToContain('orbit-e2e:app-prod_base')
        ->expectsOutputToContain('orbit-e2e:agent_base')
        ->doesntExpectOutputToContain('orbit-e2e:operator_operator')
        ->doesntExpectOutputToContain('operator_gateway_app-prod_ingress')
        ->doesntExpectOutputToContain('orbit-e2e:ingress_base')
        ->expectsOutputToContain('Dry run')
        ->assertSuccessful();

    Process::assertNothingRan();
});

it('documents agent docker topology image preparation without force', function (): void {
    Process::fake();

    $this->artisan('e2e:prepare-docker-topology', ['kind' => 'operator_gateway_agent'])
        ->expectsOutputToContain('orbit-e2e:operator_base')
        ->expectsOutputToContain('orbit-e2e:gateway_base')
        ->expectsOutputToContain('orbit-e2e:app-dev_base')
        ->expectsOutputToContain('orbit-e2e:app-prod_base')
        ->expectsOutputToContain('orbit-e2e:agent_base')
        ->doesntExpectOutputToContain('orbit-e2e:operator_operator')
        ->doesntExpectOutputToContain('operator_gateway_agent')
        ->expectsOutputToContain('Dry run')
        ->assertSuccessful();

    Process::assertNothingRan();
});

it('documents full docker topology image preparation from the default role images', function (): void {
    Process::fake();

    withE2ETopologyEnvironment([], function (): void {
        $this->artisan('e2e:prepare-docker-topology', ['kind' => 'operator_gateway_app-dev_app-prod_agent'])
            ->expectsOutputToContain('orbit-e2e:operator_base')
            ->expectsOutputToContain('orbit-e2e:gateway_base')
            ->expectsOutputToContain('orbit-e2e:app-dev_base')
            ->expectsOutputToContain('orbit-e2e:app-prod_base')
            ->expectsOutputToContain('orbit-e2e:agent_base')
            ->doesntExpectOutputToContain('orbit-e2e:operator_operator')
            ->doesntExpectOutputToContain('operator_gateway_app-dev_app-prod_agent')
            ->assertSuccessful();
    });

    Process::assertNothingRan();
});

it('documents app production ingress preparation from composable docker role images', function (): void {
    Process::fake();

    withE2ETopologyEnvironment([], function (): void {
        $this->artisan('e2e:prepare-docker-topology', ['kind' => 'operator_gateway_app-prod_ingress'])
            ->expectsOutputToContain('orbit-e2e:operator_base')
            ->expectsOutputToContain('orbit-e2e:gateway_base')
            ->expectsOutputToContain('orbit-e2e:app-prod_base')
            ->doesntExpectOutputToContain('orbit-e2e:operator_operator')
            ->doesntExpectOutputToContain('operator_gateway_app-prod_ingress')
            ->doesntExpectOutputToContain('orbit-e2e:ingress_base')
            ->assertSuccessful();
    });

    Process::assertNothingRan();
});

it('rejects unknown kind', function (): void {
    $this->artisan('e2e:prepare-docker-topology', ['kind' => 'invalid'])
        ->expectsOutputToContain('Invalid topology kind')
        ->assertFailed();
});

it('rejects custom artifact namespace preparation without explicit roles', function (): void {
    Process::fake();

    withE2ETopologyEnvironment([
        'ORBIT_E2E_TOPOLOGY_ARTIFACT_NAMESPACE' => 'Agent isolation',
    ], function (): void {
        $this->artisan('e2e:prepare-docker-topology', ['kind' => 'operator_gateway_agent'])
            ->expectsOutputToContain('Set --roles or --all-roles when ORBIT_E2E_TOPOLOGY_ARTIFACT_NAMESPACE is set')
            ->assertFailed();
    });

    Process::assertNothingRan();
});

it('plans only selected branch Docker role images', function (): void {
    Process::fake();

    withE2ETopologyEnvironment([
        'ORBIT_E2E_TOPOLOGY_ARTIFACT_NAMESPACE' => 'Agent isolation',
    ], function (): void {
        $this->artisan('e2e:prepare-docker-topology', [
            'kind' => 'operator_gateway_agent',
            '--roles' => 'agent',
        ])
            ->expectsOutputToContain('planned: orbit-e2e:agent_agent-isolation')
            ->doesntExpectOutputToContain('orbit-e2e:operator_agent-isolation')
            ->doesntExpectOutputToContain('orbit-e2e:gateway_agent-isolation')
            ->doesntExpectOutputToContain('orbit-e2e:app-dev_agent-isolation')
            ->doesntExpectOutputToContain('orbit-e2e:app-prod_agent-isolation')
            ->assertSuccessful();
    });

    Process::assertNothingRan();
});

it('is hidden', function (): void {
    $command = app(E2EPrepareDockerTopologyCommand::class);

    expect($command->isHidden())->toBeTrue();
});

it('requires an explicit topology kind', function (): void {
    $argument = app(E2EPrepareDockerTopologyCommand::class)
        ->getDefinition()
        ->getArgument('kind');

    expect($argument->isRequired())->toBeTrue()
        ->and($argument->getDefault())->toBeNull();
});

it('documents dns-alias image names in dry run output', function (): void {
    $this->artisan('e2e:prepare-docker-topology', [
        'kind' => 'operator_gateway',
    ])
        ->expectsOutputToContain('orbit-e2e:operator_base')
        ->expectsOutputToContain('orbit-e2e:gateway_base')
        ->doesntExpectOutputToContain('orbit-e2e:operator_operator')
        ->assertSuccessful();
});

it('--force uses the docker topology builder and outputs json manifest', function (): void {
    $manifest = [
        ['role' => 'operator', 'container' => 'orbit-e2e-build-operator-operator', 'image' => 'orbit-e2e:operator_base'],
    ];

    $builder = m::mock();
    $builder->shouldReceive('build')
        ->with(E2ETopologyKind::OperatorGateway)
        ->once()
        ->andReturn($manifest);

    $command = app(E2EPrepareDockerTopologyCommand::class);
    $command->setBuilderFactory(fn () => $builder);
    $this->app->instance(E2EPrepareDockerTopologyCommand::class, $command);

    $expected = json_encode([
        'success' => [
            'data' => [
                'provider' => 'docker',
                'dry_run' => false,
                'kind' => 'operator',
                'images' => $manifest,
            ],
        ],
    ], JSON_THROW_ON_ERROR);

    $this->artisan('e2e:prepare-docker-topology', [
        'kind' => 'operator',
        '--force' => true,
        '--json' => true,
    ])
        ->expectsOutput($expected)
        ->assertSuccessful();
});

it('--force builds gateway-backed docker topology source images in composable order', function (): void {
    $operatorGatewayManifest = [
        ['role' => 'operator', 'container' => 'build-operator', 'image' => 'orbit-e2e:operator_base'],
        ['role' => 'gateway', 'container' => 'build-gateway', 'image' => 'orbit-e2e:gateway_base'],
    ];
    $downstreamManifest = [
        ['role' => 'dev', 'container' => 'build-dev', 'image' => 'orbit-e2e:app-dev_base'],
        ['role' => 'prod', 'container' => 'build-prod', 'image' => 'orbit-e2e:app-prod_base'],
        ['role' => 'agent', 'container' => 'build-agent', 'image' => 'orbit-e2e:agent_base'],
    ];

    $builder = m::mock();
    $builder->shouldReceive('build')
        ->with(E2ETopologyKind::OperatorGateway)
        ->once()
        ->andReturn($operatorGatewayManifest);
    $builder->shouldReceive('build')
        ->with(E2ETopologyKind::OperatorGatewayAppdevAppprodAgent)
        ->once()
        ->andReturn($downstreamManifest);

    $command = app(E2EPrepareDockerTopologyCommand::class);
    $command->setBuilderFactory(fn () => $builder);
    $this->app->instance(E2EPrepareDockerTopologyCommand::class, $command);

    $this->artisan('e2e:prepare-docker-topology', [
        'kind' => 'operator_gateway_agent',
        '--force' => true,
        '--json' => true,
    ])
        ->expectsOutput(json_encode([
            'success' => [
                'data' => [
                    'provider' => 'docker',
                    'dry_run' => false,
                    'kind' => 'operator_gateway_agent',
                    'images' => [
                        ...$operatorGatewayManifest,
                        ...$downstreamManifest,
                    ],
                ],
            ],
        ], JSON_THROW_ON_ERROR))
        ->assertSuccessful();
});

it('--force builds only selected Docker role artifacts for a custom namespace', function (): void {
    $manifest = [
        ['role' => 'agent', 'container' => 'build-agent', 'image' => 'orbit-e2e:agent_agent-isolation'],
    ];

    $builder = m::mock();
    $builder->shouldReceive('build')
        ->with(E2ETopologyKind::OperatorGatewayAppdevAppprodAgent, 'dns-alias', ['agent'])
        ->once()
        ->andReturn($manifest);
    $builder->shouldNotReceive('build')
        ->with(E2ETopologyKind::OperatorGateway);

    $command = app(E2EPrepareDockerTopologyCommand::class);
    $command->setBuilderFactory(fn () => $builder);
    $this->app->instance(E2EPrepareDockerTopologyCommand::class, $command);

    withE2ETopologyEnvironment([
        'ORBIT_E2E_TOPOLOGY_ARTIFACT_NAMESPACE' => 'Agent isolation',
    ], function () use ($manifest): void {
        $this->artisan('e2e:prepare-docker-topology', [
            'kind' => 'operator_gateway_agent',
            '--force' => true,
            '--json' => true,
            '--roles' => 'agent',
        ])
            ->expectsOutput(json_encode([
                'success' => [
                    'data' => [
                        'provider' => 'docker',
                        'dry_run' => false,
                        'kind' => 'operator_gateway_agent',
                        'images' => $manifest,
                    ],
                ],
            ], JSON_THROW_ON_ERROR))
            ->assertSuccessful();
    });
});
