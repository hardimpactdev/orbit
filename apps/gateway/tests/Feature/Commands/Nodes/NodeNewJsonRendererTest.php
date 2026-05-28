<?php

declare(strict_types=1);

use App\Models\Node;
use App\Models\WireGuardPeer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Process;

uses(RefreshDatabase::class);

it('emits the documented operator-node enrollment next steps', function (): void {
    config(['orbit.is_gateway' => true]);

    $gateway = Node::factory()->create([
        'name' => 'gateway-1',

        'host' => '10.6.0.2',
        'wireguard_address' => '10.6.0.2',
        'gateway_endpoint' => 'gateway.example.com',
        'status' => 'active',
    ]);

    WireGuardPeer::factory()->create([
        'node_id' => $gateway->id,
        'public_key' => 'gateway-public-key',
        'private_key' => 'gateway-private-key',
        'allowed_ips' => '10.6.0.2/32',
    ]);

    Process::fake([
        'wg genkey' => Process::result(output: "control-private-key\n"),
        'wg pubkey' => Process::result(output: "control-public-key\n"),
    ]);
    Process::preventStrayProcesses();

    $exitCode = Artisan::call('node:new', [
        'name' => 'control-2',
        '--role' => 'control',
        '--json' => true,
    ]);

    $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(0)
        ->and($payload['success']['data']['next_steps'])->toBe([
            'Install the WireGuard configuration on the operator node.',
            'Join the Orbit WireGuard network.',
            'Run `orbit gateway:add` on the operator node.',
        ]);
});
