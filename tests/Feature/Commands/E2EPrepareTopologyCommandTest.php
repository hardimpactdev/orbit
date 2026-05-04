<?php

declare(strict_types=1);

use App\Console\Commands\E2EPrepareTopologyCommand;
use Illuminate\Support\Facades\Process;
use Mockery as m;
use Tests\E2E\Support\E2ETopologyKind;
use Tests\E2E\Support\IncusHost;
use Tests\E2E\Support\IncusTopologyBuilder;
use Tests\E2E\Support\IncusTopologyTemplate;

beforeEach(function (): void {
    Process::preventStrayProcesses();
});

afterEach(function (): void {
    m::close();
});

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
    $previousHosts = getenv('ORBIT_E2E_INCUS_HOSTS');
    $previousHost = getenv('ORBIT_E2E_HOST');
    $previousProvider = getenv('ORBIT_E2E_PROVIDER');
    $manifest = [
        ['role' => 'control', 'name' => 'orbit-template-control-control', 'snapshot' => 'clean'],
    ];
    $selectedHost = null;

    $builder = m::mock(IncusTopologyBuilder::class);
    $builder->shouldReceive('build')
        ->with(E2ETopologyKind::Control)
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

it('--force outputs JSON success envelope when builder returns a manifest', function (): void {
    $manifest = [
        ['role' => 'control', 'name' => 'orbit-template-control-control', 'snapshot' => 'clean'],
    ];

    $builder = m::mock(IncusTopologyBuilder::class);
    $builder->shouldReceive('build')
        ->with(E2ETopologyKind::Control)
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
    $builder = m::mock(IncusTopologyBuilder::class);
    $builder->shouldReceive('build')
        ->andThrow(new RuntimeException('Required source image [orbit-ready-control] not found.'));

    $command = app(E2EPrepareTopologyCommand::class);
    $command->setBuilderFactory(fn () => $builder);
    $this->app->instance(E2EPrepareTopologyCommand::class, $command);

    $this->artisan('e2e:prepare-topology', [
        'kind' => 'control',
        '--force' => true,
    ])
        ->assertFailed();
});
