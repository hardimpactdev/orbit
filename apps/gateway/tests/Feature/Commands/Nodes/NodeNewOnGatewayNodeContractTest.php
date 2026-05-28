<?php

declare(strict_types=1);

use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Models\WireGuardPeer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
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

    NodeRoleAssignment::factory()->for($gateway)->create([
        'role' => 'gateway',
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
        '--operator' => true,
        '--json' => true,
    ]);

    $node = Node::query()->where('name', 'control-2')->first();

    expect($exitCode)->toBe(0)
        ->and($node)->not->toBeNull()
        ->and($node?->status)->toBe(Node::STATUS_ACTIVE)
        ->and(NodeRoleAssignment::query()->where('node_id', $node?->id)->where('status', 'active')->exists())->toBeFalse()
        ->and(WireGuardPeer::query()->where('node_id', $node?->id)->exists())->toBeTrue();

    Process::assertRanTimes(fn ($process): bool => str_contains($process->command, 'ssh '), 0);
});
