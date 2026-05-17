<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Laravel\Prompts\DataTablePrompt;
use Laravel\Prompts\Key;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    createTestGatewayNode([
        'name' => 'local-gateway',
        'role' => 'gateway',
        'host' => '10.6.0.1',
        'wireguard_address' => '10.6.0.1',
        'platform' => 'ubuntu',
    ]);
    createTestAppHostNode(['name' => 'app-1', 'role' => 'app', 'platform' => 'ubuntu']);
});

it('prompts for rule name, node, and port in interactive mode when all are missing', function (): void {
    DataTablePrompt::fake([Key::ENTER]);

    $this->artisan('firewall:allow')
        ->expectsQuestion('Rule name', 'my-rule')
        ->expectsQuestion('Port', '8080')
        ->assertSuccessful();
});

it('does not prompt when all required inputs are supplied', function (): void {
    $this->artisan('firewall:allow', ['name' => 'my-rule', '--node' => 'app-1', '--port' => '8080'])
        ->doesntExpectOutput('Rule name')
        ->doesntExpectOutput('Target node')
        ->doesntExpectOutput('Port')
        ->assertSuccessful();
});

it('returns validation_failed for missing name in non-interactive mode', function (): void {
    $exitCode = Artisan::call('firewall:allow', ['--node' => 'app-1', '--port' => '8080', '--json' => true]);
    $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(1)
        ->and($payload['error']['code'])->toBe('validation_failed')
        ->and($payload['error']['meta']['field'])->toBe('name');
});

it('returns validation_failed for missing port in non-interactive mode', function (): void {
    $exitCode = Artisan::call('firewall:allow', ['name' => 'my-rule', '--node' => 'app-1', '--json' => true]);
    $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(1)
        ->and($payload['error']['code'])->toBe('validation_failed')
        ->and($payload['error']['meta']['field'])->toBe('port');
});
