<?php

declare(strict_types=1);

use App\Models\Node;
use App\Models\WireGuardPeer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;

uses(RefreshDatabase::class);

it('enrolls operator nodes locally and writes gateway-owned node state', function (): void {
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

    expect($exitCode)->toBe(0)
        ->and(DB::table('nodes')->where('name', 'control-2')->where('role', 'control')->exists())->toBeTrue()
        ->and(DB::table('wireguard_peers')->where('node_id', DB::table('nodes')->where('name', 'control-2')->value('id'))->exists())->toBeTrue();

    Process::assertRanTimes(fn ($process): bool => str_contains($process->command, 'ssh '), 0);
});
