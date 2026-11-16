<?php

declare(strict_types=1);

use App\Domain\Shared\ResourceOperationException;
use App\Domain\WireGuard\VpnSettings;
use App\Domain\WireGuard\WireGuardAddressAllocator;
use App\Models\Node;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

it('never hands a Node an address of the reserved range, which no setting moves', function (): void {
    app(VpnSettings::class)->configure('10.44.0.0/24');
    foreach (range(1, 126) as $host) {
        Node::withoutEvents(fn (): Node => Node::query()->create(['name' => "node-{$host}", 'status' => 'active', 'platform' => 'linux',
            'wireguard_ip' => "10.44.0.{$host}", 'public_ssh_host' => "192.0.2.{$host}", 'user' => 'orbit']));
    }

    expect(WireGuardAddressAllocator::ReservedRange)->toBe('10.44.0.128/25')
        ->and(app(WireGuardAddressAllocator::class)->next())->toBe('10.44.0.127');
    Node::withoutEvents(fn (): Node => Node::query()->create(['name' => 'node-127', 'status' => 'active', 'platform' => 'linux',
        'wireguard_ip' => '10.44.0.127', 'public_ssh_host' => '192.0.2.127', 'user' => 'orbit']));
    expect(fn () => app(WireGuardAddressAllocator::class)->next())
        ->toThrow(fn (ResourceOperationException $error) => expect($error->errorCode)->toBe('vpn.peer_address_exhausted'));
});
