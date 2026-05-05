<?php

declare(strict_types=1);

use App\Console\Commands\E2EPrepareTopologyCommand;
use App\E2E\Support\E2EPhaseTimer;
use App\E2E\Support\E2ETopologyKind;
use App\E2E\Support\IncusHost;
use App\E2E\Support\IncusTopologyBuilder;
use App\E2E\Support\IncusTopologyTemplate;
use Illuminate\Process\PendingProcess;
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
        'ssh *mktemp -d /tmp/orbit-e2e-stage*' => Process::result(output: "/tmp/orbit-e2e-stage-remote\n"),
        'ssh *' => Process::result(),
        'scp *' => Process::result(),
    ]);
}

it('defaults to control-gateway-dev-prod kind', function (): void {
    $this->artisan('e2e:prepare-topology')
        ->expectsOutputToContain('orbit-template-control-gateway-dev-prod-control')
        ->expectsOutputToContain('orbit-template-control-gateway-dev-prod-gateway')
        ->expectsOutputToContain('orbit-template-control-gateway-dev-prod-dev')
        ->expectsOutputToContain('orbit-template-control-gateway-dev-prod-prod')
        ->assertSuccessful();
});

it('supports control kind', function (): void {
    $this->artisan('e2e:prepare-topology', ['kind' => 'control'])
        ->expectsOutputToContain('orbit-template-control-control')
        ->assertSuccessful();
});

it('supports control-gateway kind', function (): void {
    $this->artisan('e2e:prepare-topology', ['kind' => 'control-gateway'])
        ->expectsOutputToContain('orbit-template-control-gateway-control')
        ->expectsOutputToContain('orbit-template-control-gateway-gateway')
        ->assertSuccessful();
});

it('supports control-gateway-dev kind', function (): void {
    $this->artisan('e2e:prepare-topology', ['kind' => 'control-gateway-dev'])
        ->expectsOutputToContain('orbit-template-control-gateway-dev-control')
        ->expectsOutputToContain('orbit-template-control-gateway-dev-gateway')
        ->expectsOutputToContain('orbit-template-control-gateway-dev-dev')
        ->assertSuccessful();
});

it('rejects invalid kind', function (): void {
    $this->artisan('e2e:prepare-topology', ['kind' => 'invalid'])
        ->expectsOutputToContain('Invalid topology kind')
        ->assertFailed();
});

it('defaults to dry run', function (): void {
    $this->artisan('e2e:prepare-topology')
        ->expectsOutputToContain('Dry run. Pass --force to create Incus topology templates.')
        ->assertSuccessful();
});

it('outputs json for dry run with default kind', function (): void {
    $kind = E2ETopologyKind::ControlGatewayDevProd;
    $templates = [];

    foreach (IncusTopologyTemplate::rolesFor($kind) as $role) {
        $templates[] = [
            'role' => $role,
            'name' => IncusTopologyTemplate::templateName($kind, $role),
            'snapshot' => 'clean',
        ];
    }

    $expected = json_encode([
        'success' => [
            'data' => [
                'provider' => 'incus',
                'dry_run' => true,
                'kind' => 'control-gateway-dev-prod',
                'templates' => $templates,
            ],
        ],
    ], JSON_THROW_ON_ERROR);

    $this->artisan('e2e:prepare-topology', ['--json' => true])
        ->expectsOutput($expected)
        ->assertSuccessful();
});

it('outputs json for each supported kind', function (string $kindValue, int $expectedRoleCount): void {
    $kind = E2ETopologyKind::from($kindValue);
    $templates = [];

    foreach (IncusTopologyTemplate::rolesFor($kind) as $role) {
        $templates[] = [
            'role' => $role,
            'name' => IncusTopologyTemplate::templateName($kind, $role),
            'snapshot' => 'clean',
        ];
    }

    $expected = json_encode([
        'success' => [
            'data' => [
                'provider' => 'incus',
                'dry_run' => true,
                'kind' => $kindValue,
                'templates' => $templates,
            ],
        ],
    ], JSON_THROW_ON_ERROR);

    $this->artisan('e2e:prepare-topology', [
        'kind' => $kindValue,
        '--json' => true,
    ])
        ->expectsOutput($expected)
        ->assertSuccessful();
})->with([
    ['control', 1],
    ['control-gateway', 2],
    ['control-gateway-dev', 3],
    ['control-gateway-dev-prod', 4],
]);

it('outputs json error for invalid kind', function (): void {
    $expected = json_encode([
        'error' => [
            'code' => 'validation_failed',
            'message' => 'Invalid topology kind [invalid]. Supported: control, control-gateway, control-gateway-dev, control-gateway-dev-prod.',
        ],
    ], JSON_THROW_ON_ERROR);

    $this->artisan('e2e:prepare-topology', [
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
        ['role' => 'control', 'name' => 'orbit-template-control-control', 'snapshot' => 'clean'],
    ];
    $selectedHost = null;

    $builder = m::mock(IncusTopologyBuilder::class);
    $builder->shouldReceive('useBundle')->once();
    $builder->shouldReceive('build')
        ->with(E2ETopologyKind::Control, true)
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
            'kind' => 'control',
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
        ['role' => 'control', 'name' => 'orbit-template-control-control', 'snapshot' => 'clean'],
    ];
    $forwardedBundle = null;

    $builder = m::mock(IncusTopologyBuilder::class);
    $builder->shouldReceive('useBundle')
        ->once()
        ->andReturnUsing(function (string $path) use (&$forwardedBundle): void {
            $forwardedBundle = $path;
        });
    $builder->shouldReceive('build')
        ->with(E2ETopologyKind::Control, true)
        ->andReturn($manifest);

    $command = app(E2EPrepareTopologyCommand::class);
    $command->setBuilderFactory(fn () => $builder);
    $this->app->instance(E2EPrepareTopologyCommand::class, $command);

    $this->artisan('e2e:prepare-topology', [
        'kind' => 'control',
        '--force' => true,
    ])->assertSuccessful();

    expect($forwardedBundle)->toBe('/tmp/orbit-e2e-stage-remote/orbit-e2e-bundle');
    Process::assertRan(fn (PendingProcess $p): bool => str_contains((string) $p->command, 'tar ') && str_contains((string) $p->command, '-czf'));
});

it('--force records prepare topology phase timings', function (): void {
    fakeBundleProcessing();

    $manifest = [
        ['role' => 'control', 'name' => 'orbit-template-control-control', 'snapshot' => 'clean'],
    ];
    $capturedTimer = null;

    $builder = m::mock(IncusTopologyBuilder::class);
    $builder->shouldReceive('useBundle')->once();
    $builder->shouldReceive('build')
        ->with(E2ETopologyKind::Control, true)
        ->andReturn($manifest);

    $command = app(E2EPrepareTopologyCommand::class);
    $command->setBuilderFactory(function (IncusHost $host, E2EPhaseTimer $timer) use ($builder, &$capturedTimer): IncusTopologyBuilder {
        $capturedTimer = $timer;

        return $builder;
    });
    $this->app->instance(E2EPrepareTopologyCommand::class, $command);

    $this->artisan('e2e:prepare-topology', [
        'kind' => 'control',
        '--force' => true,
    ])->assertSuccessful();

    $eventNames = array_column($capturedTimer?->events() ?? [], 'name');

    expect($capturedTimer)->toBeInstanceOf(E2EPhaseTimer::class)
        ->and($eventNames)->toContain('bundle.local')
        ->and($eventNames)->toContain('bundle.push')
        ->and($eventNames)->toContain('builder.build')
        ->and($eventNames)->toContain('bundle.cleanup.remote')
        ->and($eventNames)->toContain('bundle.cleanup.local');
});

it('--branch uses git archive instead of tar', function (): void {
    fakeBundleProcessing();

    $manifest = [
        ['role' => 'control', 'name' => 'orbit-template-control-control', 'snapshot' => 'clean'],
    ];

    $builder = m::mock(IncusTopologyBuilder::class);
    $builder->shouldReceive('useBundle')->once();
    $builder->shouldReceive('build')->andReturn($manifest);

    $command = app(E2EPrepareTopologyCommand::class);
    $command->setBuilderFactory(fn () => $builder);
    $this->app->instance(E2EPrepareTopologyCommand::class, $command);

    $this->artisan('e2e:prepare-topology', [
        'kind' => 'control',
        '--force' => true,
        '--branch' => 'main',
    ])->assertSuccessful();

    Process::assertRan(fn (PendingProcess $p): bool => str_contains((string) $p->command, 'git -C') && str_contains((string) $p->command, 'archive') && str_contains((string) $p->command, "'main'"));
});

it('--source-archive forwards the provided archive', function (): void {
    fakeBundleProcessing();

    $tempArchive = tempnam(sys_get_temp_dir(), 'orbit-archive-').'.tar.gz';
    file_put_contents($tempArchive, 'fake');

    try {
        $manifest = [
            ['role' => 'control', 'name' => 'orbit-template-control-control', 'snapshot' => 'clean'],
        ];

        $builder = m::mock(IncusTopologyBuilder::class);
        $builder->shouldReceive('useBundle')->once();
        $builder->shouldReceive('build')->andReturn($manifest);

        $command = app(E2EPrepareTopologyCommand::class);
        $command->setBuilderFactory(fn () => $builder);
        $this->app->instance(E2EPrepareTopologyCommand::class, $command);

        $this->artisan('e2e:prepare-topology', [
            'kind' => 'control',
            '--force' => true,
            '--source-archive' => $tempArchive,
        ])->assertSuccessful();

        Process::assertNotRan(fn (PendingProcess $p): bool => str_contains((string) $p->command, 'tar ') && str_contains((string) $p->command, '-czf'));
        Process::assertNotRan(fn (PendingProcess $p): bool => str_contains((string) $p->command, 'git -C') && str_contains((string) $p->command, 'archive'));
    } finally {
        @unlink($tempArchive);
    }
});

it('--source-archive fails clearly when archive is missing', function (): void {
    fakeBundleProcessing();

    $command = app(E2EPrepareTopologyCommand::class);
    $command->setBuilderFactory(fn () => m::mock(IncusTopologyBuilder::class));
    $this->app->instance(E2EPrepareTopologyCommand::class, $command);

    $this->artisan('e2e:prepare-topology', [
        'kind' => 'control',
        '--force' => true,
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

    $this->artisan('e2e:prepare-topology', [
        'kind' => 'control',
        '--force' => true,
        '--composer-cache' => '/tmp/orbit-composer-cache-does-not-exist',
    ])
        ->expectsOutputToContain('--composer-cache directory not found')
        ->assertFailed();
});

it('--force outputs JSON success envelope when builder returns a manifest', function (): void {
    fakeBundleProcessing();

    $manifest = [
        ['role' => 'control', 'name' => 'orbit-template-control-control', 'snapshot' => 'clean'],
    ];

    $builder = m::mock(IncusTopologyBuilder::class);
    $builder->shouldReceive('useBundle')->once();
    $builder->shouldReceive('build')
        ->with(E2ETopologyKind::Control, true)
        ->andReturn($manifest);

    $command = app(E2EPrepareTopologyCommand::class);
    $command->setBuilderFactory(fn () => $builder);
    $this->app->instance(E2EPrepareTopologyCommand::class, $command);

    $expected = json_encode([
        'success' => [
            'data' => [
                'provider' => 'incus',
                'dry_run' => false,
                'kind' => 'control',
                'templates' => $manifest,
            ],
        ],
    ], JSON_THROW_ON_ERROR);

    $this->artisan('e2e:prepare-topology', [
        'kind' => 'control',
        '--force' => true,
        '--json' => true,
    ])
        ->expectsOutput($expected)
        ->assertSuccessful();
});

it('--force surfaces builder failure as command failure', function (): void {
    fakeBundleProcessing();

    $builder = m::mock(IncusTopologyBuilder::class);
    $builder->shouldReceive('useBundle');
    $builder->shouldReceive('build')
        ->andThrow(new RuntimeException('Required source image [orbit-base-ubuntu-26.04] not found.'));

    $command = app(E2EPrepareTopologyCommand::class);
    $command->setBuilderFactory(fn () => $builder);
    $this->app->instance(E2EPrepareTopologyCommand::class, $command);

    $this->artisan('e2e:prepare-topology', [
        'kind' => 'control',
        '--force' => true,
    ])
        ->expectsOutputToContain('Required source image [orbit-base-ubuntu-26.04] not found.')
        ->assertFailed();
});
