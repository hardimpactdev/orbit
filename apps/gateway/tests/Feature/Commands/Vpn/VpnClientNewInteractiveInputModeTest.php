<?php

declare(strict_types=1);

use App\Models\Node;
use App\Services\Vpn\ArrayVpnBackend;
use App\Services\Vpn\VpnBackend;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Node::factory()->create([
        'name' => 'local-gateway',

        'host' => '10.6.0.2',
        'wireguard_address' => '10.6.0.2',
        'status' => 'active',
    ]);
    app()->instance(VpnBackend::class, new ArrayVpnBackend);
});

it('prompts for name in interactive mode when name is missing', function (): void {
    $this->artisan('vpn-client:new')
        ->expectsQuestion('VPN client name', 'laptop')
        ->expectsOutputToContain('laptop')
        ->assertSuccessful();
});

it('does not prompt when name argument is supplied in interactive mode', function (): void {
    $this->artisan('vpn-client:new', ['name' => 'laptop'])
        ->doesntExpectOutput('VPN client name')
        ->assertSuccessful();
});

it('returns validation_failed in non-interactive mode when name is missing', function (): void {
    $exitCode = Artisan::call('vpn-client:new', ['--json' => true]);
    $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(1)
        ->and($payload['error']['code'])->toBe('validation_failed')
        ->and($payload['error']['meta']['field'])->toBe('name');
});
