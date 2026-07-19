<?php

declare(strict_types=1);

use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Services\Vpn\VpnDnsSwarmInstaller;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('converges the existing vpn role runtime through its installer', function (): void {
    $installer = Mockery::mock(VpnDnsSwarmInstaller::class);
    $installer->shouldReceive('install')->once();

    app()->instance(VpnDnsSwarmInstaller::class, $installer);
    config()->set('services.wg_easy.username', 'orbit-test');
    config()->set('services.wg_easy.password', Str::random(32));

    $node = Node::factory()->create([
        'name' => 'gateway',
        'tld' => 'gateway',
        'status' => 'active',
    ]);

    NodeRoleAssignment::factory()->create([
        'node_id' => $node->id,
        'role' => 'vpn',
        'status' => 'active',
        'settings' => [
            'public_endpoint' => '192.0.2.10',
            'wireguard_cidr' => '10.44.0.0/24',
            'wireguard_port' => 51_821,
            'dns_ip' => '10.44.0.1',
        ],
    ]);

    expect(Artisan::all())
        ->toHaveKey('orbit:internal:converge-vpn-dns-runtime')
        ->and(Artisan::call('orbit:internal:converge-vpn-dns-runtime', ['node' => 'gateway']))
        ->toBe(0);
});
