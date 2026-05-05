<?php

declare(strict_types=1);

use App\Console\Commands\E2EPrepareBaseImageCommand;
use App\Services\E2E\IncusBaseImagePreparer;
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
        ->expectsOutputToContain('planned: base -> orbit-base-ubuntu-26.04 (source: images:ubuntu/26.04/cloud)')
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
                    'alias' => 'orbit-base-ubuntu-26.04',
                    'source' => 'images:ubuntu/26.04/cloud',
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
        'alias' => 'orbit-base-ubuntu-26.04',
        'action' => 'built',
    ]);

    $command = app(E2EPrepareBaseImageCommand::class);
    $command->setPreparerFactory(fn () => $preparer);
    $this->app->instance(E2EPrepareBaseImageCommand::class, $command);

    $expected = json_encode([
        'success' => [
            'data' => [
                'provider' => 'incus',
                'dry_run' => false,
                'image' => [
                    'role' => 'base',
                    'alias' => 'orbit-base-ubuntu-26.04',
                    'action' => 'built',
                ],
            ],
        ],
    ], JSON_THROW_ON_ERROR);

    $this->artisan('e2e:prepare-base-image', ['--force' => true, '--json' => true])
        ->expectsOutput($expected)
        ->assertSuccessful();
});

it('--force without an Incus provider fails clearly', function (): void {
    $previousProvider = getenv('ORBIT_E2E_PROVIDER');

    putenv('ORBIT_E2E_PROVIDER=docker');

    try {
        $this->artisan('e2e:prepare-base-image', ['--force' => true])
            ->expectsOutputToContain('No Incus provider configured')
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
    $command->setPreparerFactory(fn () => $preparer);
    $this->app->instance(E2EPrepareBaseImageCommand::class, $command);

    $this->artisan('e2e:prepare-base-image', ['--force' => true])
        ->expectsOutputToContain('Source image [orbit-missing] is not available.')
        ->assertFailed();
});
