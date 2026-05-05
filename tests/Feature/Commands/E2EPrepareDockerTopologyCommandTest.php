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

    $this->artisan('e2e:prepare-docker-topology', ['kind' => 'control-gateway-dev-prod'])
        ->expectsOutputToContain('orbit-e2e-topology:control-gateway-dev-prod-control-current')
        ->expectsOutputToContain('orbit-e2e-topology:control-gateway-dev-prod-gateway-current')
        ->expectsOutputToContain('orbit-e2e-topology:control-gateway-dev-prod-dev-current')
        ->expectsOutputToContain('orbit-e2e-topology:control-gateway-dev-prod-prod-current')
        ->expectsOutputToContain('Dry run')
        ->assertSuccessful();

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

it('outputs json for dry run with default kind', function (): void {
    $expected = json_encode([
        'success' => [
            'data' => [
                'provider' => 'docker',
                'dry_run' => true,
                'kind' => 'control-gateway-dev-prod',
                'images' => [
                    ['role' => 'control', 'image' => 'orbit-e2e-topology:control-gateway-dev-prod-control-current'],
                    ['role' => 'gateway', 'image' => 'orbit-e2e-topology:control-gateway-dev-prod-gateway-current'],
                    ['role' => 'dev', 'image' => 'orbit-e2e-topology:control-gateway-dev-prod-dev-current'],
                    ['role' => 'prod', 'image' => 'orbit-e2e-topology:control-gateway-dev-prod-prod-current'],
                ],
            ],
        ],
    ], JSON_THROW_ON_ERROR);

    $this->artisan('e2e:prepare-docker-topology', ['--json' => true])
        ->expectsOutput($expected)
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
                'kind' => 'control',
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
