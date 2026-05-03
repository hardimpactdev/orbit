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

it('defaults to all roles in dry-run output', function (): void {
    $this->artisan('e2e:prepare-incus-images')
        ->expectsOutputToContain('Dry run. Pass --force to build Incus images.')
        ->expectsOutputToContain('planned: blank -> orbit-blank-ubuntu-26.04 (source: images:ubuntu/26.04/cloud)')
        ->expectsOutputToContain('planned: control -> orbit-ready-control (source: orbit-blank-ubuntu-26.04)')
        ->expectsOutputToContain('planned: gateway -> orbit-ready-gateway (source: orbit-blank-ubuntu-26.04)')
        ->expectsOutputToContain('planned: devapp -> orbit-ready-devapp (source: orbit-blank-ubuntu-26.04)')
        ->expectsOutputToContain('planned: prodapp -> orbit-ready-prodapp (source: orbit-blank-ubuntu-26.04)')
        ->assertSuccessful();
});

it('can list only blank role', function (): void {
    $this->artisan('e2e:prepare-incus-images', ['--role' => 'blank'])
        ->expectsOutputToContain('planned: blank -> orbit-blank-ubuntu-26.04')
        ->assertSuccessful();
});

it('can list only control role', function (): void {
    $this->artisan('e2e:prepare-incus-images', ['--role' => 'control'])
        ->expectsOutputToContain('planned: control -> orbit-ready-control')
        ->assertSuccessful();
});

it('can list only gateway role', function (): void {
    $this->artisan('e2e:prepare-incus-images', ['--role' => 'gateway'])
        ->expectsOutputToContain('planned: gateway -> orbit-ready-gateway')
        ->assertSuccessful();
});

it('can list only devapp role', function (): void {
    $this->artisan('e2e:prepare-incus-images', ['--role' => 'devapp'])
        ->expectsOutputToContain('planned: devapp -> orbit-ready-devapp')
        ->assertSuccessful();
});

it('can list only prodapp role', function (): void {
    $this->artisan('e2e:prepare-incus-images', ['--role' => 'prodapp'])
        ->expectsOutputToContain('planned: prodapp -> orbit-ready-prodapp')
        ->assertSuccessful();
});

it('rejects invalid role', function (): void {
    $this->artisan('e2e:prepare-incus-images', ['--role' => 'invalid'])
        ->expectsOutputToContain('--role must be one of: all, blank, control, gateway, devapp, prodapp')
        ->assertFailed();
});

it('outputs json for dry run with all roles', function (): void {
    $expected = json_encode([
        'success' => [
            'data' => [
                'provider' => 'incus',
                'dry_run' => true,
                'roles' => [
                    ['role' => 'blank', 'image_alias' => 'orbit-blank-ubuntu-26.04', 'source' => 'images:ubuntu/26.04/cloud'],
                    ['role' => 'control', 'image_alias' => 'orbit-ready-control', 'source' => 'orbit-blank-ubuntu-26.04'],
                    ['role' => 'gateway', 'image_alias' => 'orbit-ready-gateway', 'source' => 'orbit-blank-ubuntu-26.04'],
                    ['role' => 'devapp', 'image_alias' => 'orbit-ready-devapp', 'source' => 'orbit-blank-ubuntu-26.04'],
                    ['role' => 'prodapp', 'image_alias' => 'orbit-ready-prodapp', 'source' => 'orbit-blank-ubuntu-26.04'],
                ],
            ],
        ],
    ], JSON_THROW_ON_ERROR);

    $this->artisan('e2e:prepare-incus-images', ['--json' => true])
        ->expectsOutput($expected)
        ->assertSuccessful();
});

it('outputs json for single role', function (): void {
    $expected = json_encode([
        'success' => [
            'data' => [
                'provider' => 'incus',
                'dry_run' => true,
                'roles' => [
                    ['role' => 'control', 'image_alias' => 'orbit-ready-control', 'source' => 'orbit-blank-ubuntu-26.04'],
                ],
            ],
        ],
    ], JSON_THROW_ON_ERROR);

    $this->artisan('e2e:prepare-incus-images', ['--role' => 'control', '--json' => true])
        ->expectsOutput($expected)
        ->assertSuccessful();
});

it('outputs json error for invalid role', function (): void {
    $expected = json_encode([
        'error' => [
            'code' => 'validation_failed',
            'message' => '--role must be one of: all, blank, control, gateway, devapp, prodapp.',
        ],
    ], JSON_THROW_ON_ERROR);

    $this->artisan('e2e:prepare-incus-images', ['--role' => 'invalid', '--json' => true])
        ->expectsOutput($expected)
        ->assertFailed();
});

it('--force fails with not yet implemented message for non-blank roles', function (): void {
    $this->artisan('e2e:prepare-incus-images', ['--force' => true, '--role' => 'control'])
        ->expectsOutputToContain('Role [control] image build is not yet implemented.')
        ->assertFailed();
});

it('--force --json fails with error envelope for non-blank roles', function (): void {
    $expected = json_encode([
        'error' => [
            'code' => 'incus_e2e_image_prepare_failed',
            'message' => 'Role [control] image build is not yet implemented.',
        ],
    ], JSON_THROW_ON_ERROR);

    $this->artisan('e2e:prepare-incus-images', ['--force' => true, '--role' => 'control', '--json' => true])
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
