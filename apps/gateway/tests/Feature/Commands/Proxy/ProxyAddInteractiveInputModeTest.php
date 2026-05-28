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
        'host' => '10.6.0.1',
        'wireguard_address' => '10.6.0.1',
    ]);
    createTestAppHostNode(['name' => 'app-1', 'role' => 'app']);
});

it('prompts for domain, node, route type, and upstream in interactive mode when all are missing', function (): void {
    DataTablePrompt::fake([Key::ENTER]);

    $this->artisan('proxy:add')
        ->expectsQuestion('Domain', 'vite.docs.test')
        ->expectsChoice('Route type', 'upstream', ['Upstream', 'Redirect', 'upstream', 'redirect'])
        ->expectsQuestion('Upstream URL', 'http://127.0.0.1:5173')
        ->assertSuccessful();
});

it('does not prompt when all required inputs are supplied', function (): void {
    $this->artisan('proxy:add', [
        'domain' => 'vite.docs.test',
        '--node' => 'app-1',
        '--upstream' => 'http://127.0.0.1:5173',
    ])
        ->doesntExpectOutput('Domain')
        ->doesntExpectOutput('Serving node')
        ->doesntExpectOutput('Route type')
        ->assertSuccessful();
});

it('returns validation_failed for missing domain in non-interactive mode', function (): void {
    $exitCode = Artisan::call('proxy:add', ['--node' => 'app-1', '--upstream' => 'http://127.0.0.1:5173', '--json' => true]);
    $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(1)
        ->and($payload['error']['code'])->toBe('validation_failed')
        ->and($payload['error']['meta']['field'])->toBe('domain');
});

it('returns validation_failed for missing upstream and redirect in non-interactive mode', function (): void {
    $exitCode = Artisan::call('proxy:add', ['domain' => 'vite.docs.test', '--node' => 'app-1', '--json' => true]);
    $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(1)
        ->and($payload['error']['code'])->toBe('validation_failed')
        ->and($payload['error']['meta']['fields'])->toBe(['upstream', 'redirect']);
});
