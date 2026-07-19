<?php

declare(strict_types=1);

use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Models\ProxyRoute;
use App\Services\Dns\ProxyDnsmasqRecordsBuilder;
use App\Services\Doctor\ProxyDnsProjectionProbe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->root = sys_get_temp_dir().'/orbit-proxy-dns-probe-'.bin2hex(random_bytes(4));
    File::ensureDirectoryExists($this->root.'/dnsmasq.d');
    $this->probe = new ProxyDnsProjectionProbe(
        recordsBuilder: new ProxyDnsmasqRecordsBuilder,
        rootPath: $this->root,
    );

    $dnsNode = Node::factory()->create([
        'name' => 'dns-runtime',
        'tld' => 'dns-runtime',
        'wireguard_address' => '10.6.0.250',
        'status' => 'active',
    ]);
    NodeRoleAssignment::factory()->create([
        'node_id' => $dnsNode->id,
        'role' => 'vpn',
        'status' => 'active',
    ]);
});

it('reports a missing owner fragment when vpn intent requires dns capability', function (): void {
    $router = Node::factory()->create([
        'name' => 'gateway',
        'tld' => 'gateway',
        'wireguard_address' => '10.6.0.2',
        'status' => 'active',
    ]);
    ProxyRoute::factory()->create([
        'node_id' => $router->id,
        'domain' => 'metrics.orbit',
        'owner_type' => 'router',
        'kind' => 'proxy',
    ]);

    $drift = $this->probe->drift($router);

    expect($drift)
        ->toHaveCount(1)
        ->and($drift[0]->key)
        ->toBe('proxy.dns_mapping_mismatch')
        ->and($drift[0]->kind)
        ->toBe(\App\Enums\DriftKind::Missing);
});

afterEach(function (): void {
    $root = $this->root ?? null;

    if (is_string($root) && is_dir($root)) {
        File::deleteDirectory($root);
    }
});

it('reports proxy-owned private service projection drift on the router', function (): void {
    $router = Node::factory()->create([
        'name' => 'gateway',
        'tld' => 'gateway',
        'wireguard_address' => '10.6.0.2',
        'status' => 'active',
    ]);
    ProxyRoute::factory()->create([
        'node_id' => $router->id,
        'domain' => 'metrics.orbit',
        'owner_type' => 'router',
        'kind' => 'proxy',
    ]);
    File::put($this->root.'/dnsmasq.d/20-proxy-records.conf', "# stale\n");

    $drift = $this->probe->drift($router);

    expect($drift)
        ->toHaveCount(1)
        ->and($drift[0]->family)
        ->toBe('proxy')
        ->and($drift[0]->key)
        ->toBe('proxy.dns_mapping_mismatch')
        ->and($drift[0]->detail['path'] ?? null)
        ->toBe($this->root.'/dnsmasq.d/20-proxy-records.conf');
});

it('does not treat a node fragment mismatch as proxy drift', function (): void {
    $router = Node::factory()->create([
        'name' => 'gateway',
        'tld' => 'gateway',
        'wireguard_address' => '10.6.0.2',
        'status' => 'active',
    ]);
    ProxyRoute::factory()->create([
        'node_id' => $router->id,
        'domain' => 'metrics.orbit',
        'owner_type' => 'router',
        'kind' => 'proxy',
    ]);
    File::put(
        $this->root.'/dnsmasq.d/20-proxy-records.conf',
        new ProxyDnsmasqRecordsBuilder()->buildGatewayState(),
    );
    File::put($this->root.'/dnsmasq.d/10-node-records.conf', "# stale node bytes\n");

    expect($this->probe->drift($router))->toBeEmpty();
});
