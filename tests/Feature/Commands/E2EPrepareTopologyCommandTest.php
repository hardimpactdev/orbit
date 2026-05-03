<?php

declare(strict_types=1);

use App\Console\Commands\E2EPrepareTopologyCommand;
use Illuminate\Support\Facades\Process;
use Tests\E2E\Support\E2ETopologyKind;
use Tests\E2E\Support\IncusTopologyTemplate;

beforeEach(function (): void {
    Process::preventStrayProcesses();
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

it('can be forced but returns not implemented stub', function (): void {
    Process::fake();

    $this->artisan('e2e:prepare-topology', ['--force' => true])
        ->expectsOutputToContain('not yet implemented')
        ->assertFailed();
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
