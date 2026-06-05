<?php

declare(strict_types=1);

use App\Console\Commands\E2EPrepareBaseImageCommand;
use App\E2E\Support\IncusHost;
use App\Services\E2E\IncusBaseImagePreparer;
use App\Services\E2E\IncusImageDistributor;
use Illuminate\Support\Facades\Process;
use Mockery as m;

beforeEach(function (): void {
    Process::preventStrayProcesses();
});

afterEach(function (): void {
    m::close();
});

it('is hidden', function (): void {
    $command = app(E2EPrepareBaseImageCommand::class);

    expect($command->isHidden())->toBeTrue();
});

it('defaults to a dry-run plan', function (): void {
    $this->artisan('e2e:prepare-base-image')
        ->expectsOutputToContain('Dry run. Pass --force to build the Incus base image.')
        ->expectsOutputToContain('planned: base -> orbit-base-ubuntu-26.04-runtime (source: images:ubuntu/26.04)')
        ->assertSuccessful();
});

it('outputs json for the dry-run plan', function (): void {
    $expected = json_encode([
        'success' => [
            'data' => [
                'provider' => 'incus',
                'dry_run' => true,
                'image' => [
                    'role' => 'base',
                    'alias' => 'orbit-base-ubuntu-26.04-runtime',
                    'source' => 'images:ubuntu/26.04',
                ],
            ],
        ],
    ], JSON_THROW_ON_ERROR);

    $this->artisan('e2e:prepare-base-image', ['--json' => true])
        ->expectsOutput($expected)
        ->assertSuccessful();
});

it('--force invokes the preparer and emits a JSON success envelope', function (): void {
    $preparer = m::mock(IncusBaseImagePreparer::class);
    $preparer->shouldReceive('build')->andReturn([
        'role' => 'base',
        'alias' => 'orbit-base-ubuntu-26.04-runtime',
        'action' => 'built',
    ]);

    $command = app(E2EPrepareBaseImageCommand::class);
    $command->setPreparerFactory(fn (IncusHost $host): IncusBaseImagePreparer => $preparer);
    $this->app->instance(E2EPrepareBaseImageCommand::class, $command);

    $expected = json_encode([
        'success' => [
            'data' => [
                'provider' => 'incus',
                'dry_run' => false,
                'image' => [
                    'host' => 'beast',
                    'role' => 'base',
                    'alias' => 'orbit-base-ubuntu-26.04-runtime',
                    'action' => 'built',
                ],
                'images' => [
                    [
                        'host' => 'beast',
                        'role' => 'base',
                        'alias' => 'orbit-base-ubuntu-26.04-runtime',
                        'action' => 'built',
                    ],
                ],
            ],
        ],
    ], JSON_THROW_ON_ERROR);

    $this->artisan('e2e:prepare-base-image', ['--force' => true, '--json' => true])
        ->expectsOutput($expected)
        ->assertSuccessful();
});

it('--force builds on the configured image build host and distributes to configured Incus hosts', function (): void {
    $previousBuildHost = getenv('ORBIT_E2E_INCUS_IMAGE_BUILD_HOST');
    $previousHosts = getenv('ORBIT_E2E_INCUS_HOSTS');

    putenv('ORBIT_E2E_INCUS_IMAGE_BUILD_HOST=beast');
    putenv('ORBIT_E2E_INCUS_HOSTS=sidecar1,sidecar2');

    $preparedHosts = [];
    $distributorHost = null;
    $preparer = m::mock(IncusBaseImagePreparer::class);
    $preparer->shouldReceive('build')
        ->andReturn([
            'role' => 'base',
            'alias' => 'orbit-base-ubuntu-26.04-runtime',
            'action' => 'built',
        ]);
    $distributor = m::mock(IncusImageDistributor::class);
    $distributor->shouldReceive('distribute')
        ->once()
        ->andReturn([
            [
                'host' => 'sidecar1',
                'role' => 'base',
                'alias' => 'orbit-base-ubuntu-26.04-runtime',
                'action' => 'imported',
            ],
            [
                'host' => 'sidecar2',
                'role' => 'base',
                'alias' => 'orbit-base-ubuntu-26.04-runtime',
                'action' => 'imported',
            ],
        ]);

    $command = app(E2EPrepareBaseImageCommand::class);
    $command->setPreparerFactory(function (IncusHost $host) use ($preparer, &$preparedHosts): IncusBaseImagePreparer {
        $preparedHosts[] = $host->config->host;

        return $preparer;
    });
    $command->setImageDistributorFactory(function (IncusHost $host) use ($distributor, &$distributorHost): IncusImageDistributor {
        $distributorHost = $host->config->host;

        return $distributor;
    });
    $this->app->instance(E2EPrepareBaseImageCommand::class, $command);

    $expected = json_encode([
        'success' => [
            'data' => [
                'provider' => 'incus',
                'dry_run' => false,
                'image' => [
                    'host' => 'beast',
                    'role' => 'base',
                    'alias' => 'orbit-base-ubuntu-26.04-runtime',
                    'action' => 'built',
                ],
                'images' => [
                    [
                        'host' => 'beast',
                        'role' => 'base',
                        'alias' => 'orbit-base-ubuntu-26.04-runtime',
                        'action' => 'built',
                    ],
                    [
                        'host' => 'sidecar1',
                        'role' => 'base',
                        'alias' => 'orbit-base-ubuntu-26.04-runtime',
                        'action' => 'imported',
                    ],
                    [
                        'host' => 'sidecar2',
                        'role' => 'base',
                        'alias' => 'orbit-base-ubuntu-26.04-runtime',
                        'action' => 'imported',
                    ],
                ],
            ],
        ],
    ], JSON_THROW_ON_ERROR);

    try {
        $this->artisan('e2e:prepare-base-image', ['--force' => true, '--json' => true])
            ->expectsOutput($expected)
            ->assertSuccessful();
    } finally {
        $previousBuildHost === false ? putenv('ORBIT_E2E_INCUS_IMAGE_BUILD_HOST') : putenv("ORBIT_E2E_INCUS_IMAGE_BUILD_HOST={$previousBuildHost}");
        $previousHosts === false ? putenv('ORBIT_E2E_INCUS_HOSTS') : putenv("ORBIT_E2E_INCUS_HOSTS={$previousHosts}");
    }

    expect($preparedHosts)->toBe(['beast']);
    expect($distributorHost)->toBe('beast');
});

it('--force rejects unsupported provisioning provider configuration', function (): void {
    $previousProvider = getenv('ORBIT_E2E_PROVIDER');

    putenv('ORBIT_E2E_PROVIDER=docker');

    $this->expectException(InvalidArgumentException::class);
    $this->expectExceptionMessage('Unsupported E2E provider [docker]. Supported providers: incus.');

    try {
        $this->artisan('e2e:prepare-base-image', ['--force' => true])
            ->assertFailed();
    } finally {
        $previousProvider === false ? putenv('ORBIT_E2E_PROVIDER') : putenv("ORBIT_E2E_PROVIDER={$previousProvider}");
    }
});

it('--force surfaces preparer failure as command failure', function (): void {
    $preparer = m::mock(IncusBaseImagePreparer::class);
    $preparer->shouldReceive('build')
        ->andThrow(new RuntimeException('Source image [orbit-missing] is not available.'));

    $command = app(E2EPrepareBaseImageCommand::class);
    $command->setPreparerFactory(fn (IncusHost $host): IncusBaseImagePreparer => $preparer);
    $this->app->instance(E2EPrepareBaseImageCommand::class, $command);

    $this->artisan('e2e:prepare-base-image', ['--force' => true])
        ->expectsOutputToContain('Source image [orbit-missing] is not available.')
        ->assertFailed();
});
