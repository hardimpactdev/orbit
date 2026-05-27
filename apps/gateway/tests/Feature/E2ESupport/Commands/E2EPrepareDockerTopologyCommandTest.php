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
        ->expectsOutputToContain('orbit-e2e-topology:prepared-operator_gateway_app-dev_app-prod-operator-dns-alias-current')
        ->expectsOutputToContain('orbit-e2e-topology:prepared-operator_gateway_app-dev_app-prod-gateway-dns-alias-current')
        ->expectsOutputToContain('orbit-e2e-topology:prepared-operator_gateway_app-dev_app-prod-dev-dns-alias-current')
        ->expectsOutputToContain('orbit-e2e-topology:prepared-operator_gateway_app-dev_app-prod-prod-dns-alias-current')
        ->doesntExpectOutputToContain('orbit-e2e-topology:prepared-operator-operator-dns-alias-current')
        ->doesntExpectOutputToContain('orbit-e2e-topology:prepared-operator_gateway_app-dev_app-prod_agent-agent-dns-alias-current')
        ->expectsOutputToContain('Dry run')
        ->assertSuccessful();

    Process::assertNothingRan();
});

it('documents ingress docker topology image preparation without force', function (): void {
    Process::fake();

    $this->artisan('e2e:prepare-docker-topology', ['kind' => 'operator_gateway_app-prod_ingress'])
        ->expectsOutputToContain('orbit-e2e-topology:prepared-operator_gateway_app-prod_ingress-operator-dns-alias-current')
        ->expectsOutputToContain('orbit-e2e-topology:prepared-operator_gateway_app-prod_ingress-gateway-dns-alias-current')
        ->expectsOutputToContain('orbit-e2e-topology:prepared-operator_gateway_app-prod_ingress-prod-dns-alias-current')
        ->doesntExpectOutputToContain('orbit-e2e-topology:prepared-operator-operator-dns-alias-current')
        ->doesntExpectOutputToContain('orbit-e2e-topology:prepared-operator_gateway_app-dev_app-prod_agent-agent-dns-alias-current')
        ->doesntExpectOutputToContain('orbit-e2e-topology:prepared-operator_gateway_app-prod_ingress-ingress-dns-alias-current')
        ->expectsOutputToContain('Dry run')
        ->assertSuccessful();

    Process::assertNothingRan();
});

it('documents agent docker topology image preparation without force', function (): void {
    Process::fake();

    $this->artisan('e2e:prepare-docker-topology', ['kind' => 'operator_gateway_agent'])
        ->expectsOutputToContain('orbit-e2e-topology:prepared-operator_gateway_agent-operator-dns-alias-current')
        ->expectsOutputToContain('orbit-e2e-topology:prepared-operator_gateway_agent-gateway-dns-alias-current')
        ->expectsOutputToContain('orbit-e2e-topology:prepared-operator_gateway_agent-agent-dns-alias-current')
        ->doesntExpectOutputToContain('orbit-e2e-topology:prepared-operator-operator-dns-alias-current')
        ->doesntExpectOutputToContain('orbit-e2e-topology:prepared-operator_gateway_app-dev_app-prod_agent-dev-dns-alias-current')
        ->expectsOutputToContain('Dry run')
        ->assertSuccessful();

    Process::assertNothingRan();
});

it('documents full docker topology image preparation from the default role images', function (): void {
    Process::fake();

    withE2ETopologyEnvironment([], function (): void {
        $this->artisan('e2e:prepare-docker-topology', ['kind' => 'operator_gateway_app-dev_app-prod_agent'])
            ->expectsOutputToContain('orbit-e2e-topology:prepared-operator_gateway_app-dev_app-prod_agent-operator-dns-alias-current')
            ->expectsOutputToContain('orbit-e2e-topology:prepared-operator_gateway_app-dev_app-prod_agent-gateway-dns-alias-current')
            ->expectsOutputToContain('orbit-e2e-topology:prepared-operator_gateway_app-dev_app-prod_agent-dev-dns-alias-current')
            ->expectsOutputToContain('orbit-e2e-topology:prepared-operator_gateway_app-dev_app-prod_agent-prod-dns-alias-current')
            ->expectsOutputToContain('orbit-e2e-topology:prepared-operator_gateway_app-dev_app-prod_agent-agent-dns-alias-current')
            ->doesntExpectOutputToContain('orbit-e2e-topology:prepared-operator-operator-dns-alias-current')
            ->doesntExpectOutputToContain('orbit-e2e-topology:operator_gateway_app-dev_app-prod_agent-operator-dns-alias-current')
            ->assertSuccessful();
    });

    Process::assertNothingRan();
});

it('documents app production ingress preparation from the requested docker role images', function (): void {
    Process::fake();

    withE2ETopologyEnvironment([], function (): void {
        $this->artisan('e2e:prepare-docker-topology', ['kind' => 'operator_gateway_app-prod_ingress'])
            ->expectsOutputToContain('orbit-e2e-topology:prepared-operator_gateway_app-prod_ingress-operator-dns-alias-current')
            ->expectsOutputToContain('orbit-e2e-topology:prepared-operator_gateway_app-prod_ingress-gateway-dns-alias-current')
            ->expectsOutputToContain('orbit-e2e-topology:prepared-operator_gateway_app-prod_ingress-prod-dns-alias-current')
            ->doesntExpectOutputToContain('orbit-e2e-topology:prepared-operator-operator-dns-alias-current')
            ->doesntExpectOutputToContain('orbit-e2e-topology:prepared-operator_gateway_app-prod_ingress-ingress-dns-alias-current')
            ->assertSuccessful();
    });

    Process::assertNothingRan();
});

it('rejects unknown kind', function (): void {
    $this->artisan('e2e:prepare-docker-topology', ['kind' => 'invalid'])
        ->expectsOutputToContain('Invalid topology kind')
        ->assertFailed();
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
        ->expectsOutputToContain('orbit-e2e-topology:prepared-operator_gateway-operator-dns-alias-current')
        ->expectsOutputToContain('orbit-e2e-topology:prepared-operator_gateway-gateway-dns-alias-current')
        ->doesntExpectOutputToContain('orbit-e2e-topology:prepared-operator-operator-dns-alias-current')
        ->assertSuccessful();
});

it('--force uses the docker topology builder and outputs json manifest', function (): void {
    $manifest = [
        ['role' => 'control', 'container' => 'orbit-e2e-build-control-control', 'image' => 'orbit-e2e-topology:control-control-current'],
    ];

    $builder = m::mock();
    $builder->shouldReceive('build')
        ->with(E2ETopologyKind::Control)
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
        'kind' => 'control',
        '--force' => true,
        '--json' => true,
    ])
        ->expectsOutput($expected)
        ->assertSuccessful();
});
