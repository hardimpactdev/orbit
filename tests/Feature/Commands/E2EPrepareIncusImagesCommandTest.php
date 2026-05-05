<?php

declare(strict_types=1);

use App\Console\Commands\E2EPrepareIncusImagesCommand;
use App\Services\E2E\IncusE2EImagePreparationResult;
use App\Services\E2E\IncusE2EImagePreparer;
use Illuminate\Support\Facades\Process;
use Mockery as m;

beforeEach(function (): void {
    Process::preventStrayProcesses();
});

afterEach(function (): void {
    m::close();
});

it('is hidden', function (): void {
    $command = app(E2EPrepareIncusImagesCommand::class);

    expect($command->isHidden())->toBeTrue();
});

it('defaults to blank-only dry-run output', function (): void {
    $this->artisan('e2e:prepare-incus-images')
        ->expectsOutputToContain('Dry run. Pass --force to build Incus images.')
        ->expectsOutputToContain('planned: blank -> orbit-blank-ubuntu-26.04 (source: images:ubuntu/26.04/cloud)')
        ->assertSuccessful();
});

it('rejects retired role-specific roles', function (): void {
    foreach (['control', 'gateway', 'devapp', 'prodapp', 'all'] as $role) {
        $this->artisan('e2e:prepare-incus-images', ['--role' => $role])
            ->expectsOutputToContain('--role must be one of: blank.')
            ->assertFailed();
    }
});

it('rejects invalid role', function (): void {
    $this->artisan('e2e:prepare-incus-images', ['--role' => 'invalid'])
        ->expectsOutputToContain('--role must be one of: blank')
        ->assertFailed();
});

it('outputs json for dry run with blank role', function (): void {
    $expected = json_encode([
        'success' => [
            'data' => [
                'provider' => 'incus',
                'dry_run' => true,
                'roles' => [
                    ['role' => 'blank', 'image_alias' => 'orbit-blank-ubuntu-26.04', 'source' => 'images:ubuntu/26.04/cloud'],
                ],
            ],
        ],
    ], JSON_THROW_ON_ERROR);

    $this->artisan('e2e:prepare-incus-images', ['--json' => true])
        ->expectsOutput($expected)
        ->assertSuccessful();
});

it('outputs json error for invalid role', function (): void {
    $expected = json_encode([
        'error' => [
            'code' => 'validation_failed',
            'message' => '--role must be one of: blank.',
        ],
    ], JSON_THROW_ON_ERROR);

    $this->artisan('e2e:prepare-incus-images', ['--role' => 'invalid', '--json' => true])
        ->expectsOutput($expected)
        ->assertFailed();
});

it('--force --role=blank invokes preparer and returns success envelope', function (): void {
    $preparer = m::mock(IncusE2EImagePreparer::class);
    $preparer->shouldReceive('prepare')->andReturn(new IncusE2EImagePreparationResult([
        ['role' => 'blank', 'alias' => 'orbit-blank-ubuntu-26.04', 'action' => 'built'],
    ]));

    $command = app(E2EPrepareIncusImagesCommand::class);
    $command->setPreparerFactory(fn () => $preparer);
    $this->app->instance(E2EPrepareIncusImagesCommand::class, $command);

    $expected = json_encode([
        'success' => [
            'data' => [
                'provider' => 'incus',
                'dry_run' => false,
                'images' => [
                    ['role' => 'blank', 'alias' => 'orbit-blank-ubuntu-26.04', 'action' => 'built'],
                ],
            ],
        ],
    ], JSON_THROW_ON_ERROR);

    $this->artisan('e2e:prepare-incus-images', ['--force' => true, '--role' => 'blank', '--json' => true])
        ->expectsOutput($expected)
        ->assertSuccessful();
});

it('--force --role=blank without configured Incus host fails clearly', function (): void {
    $previousHosts = getenv('ORBIT_E2E_INCUS_HOSTS');
    $previousHost = getenv('ORBIT_E2E_HOST');
    $previousProvider = getenv('ORBIT_E2E_PROVIDER');

    putenv('ORBIT_E2E_INCUS_HOSTS=');
    putenv('ORBIT_E2E_HOST=');
    putenv('ORBIT_E2E_PROVIDER=incus');

    try {
        $this->artisan('e2e:prepare-incus-images', [
            '--force' => true,
            '--role' => 'blank',
        ])
            ->assertFailed();
    } finally {
        $previousHosts === false ? putenv('ORBIT_E2E_INCUS_HOSTS') : putenv("ORBIT_E2E_INCUS_HOSTS={$previousHosts}");
        $previousHost === false ? putenv('ORBIT_E2E_HOST') : putenv("ORBIT_E2E_HOST={$previousHost}");
        $previousProvider === false ? putenv('ORBIT_E2E_PROVIDER') : putenv("ORBIT_E2E_PROVIDER={$previousProvider}");
    }
});

it('--force surfaces preparer failure as command failure', function (): void {
    $preparer = m::mock(IncusE2EImagePreparer::class);
    $preparer->shouldReceive('prepare')
        ->andThrow(new RuntimeException('Source image [missing] is not available.'));

    $command = app(E2EPrepareIncusImagesCommand::class);
    $command->setPreparerFactory(fn () => $preparer);
    $this->app->instance(E2EPrepareIncusImagesCommand::class, $command);

    $this->artisan('e2e:prepare-incus-images', [
        '--force' => true,
        '--role' => 'blank',
    ])
        ->expectsOutputToContain('Source image [missing] is not available.')
        ->assertFailed();
});
