<?php

declare(strict_types=1);

use App\Enums\DriftKind;
use App\Enums\Nodes\NodeRoleStatus;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Services\Dns\NodeDnsmasqRecordsBuilder;
use App\Services\Doctor\NodeDnsProjectionProbe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->root = sys_get_temp_dir().'/orbit-node-dns-probe-'.bin2hex(random_bytes(4));
    File::ensureDirectoryExists($this->root.'/dnsmasq.d');
    $this->probe = new NodeDnsProjectionProbe(
        recordsBuilder: new NodeDnsmasqRecordsBuilder,
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
        'status' => NodeRoleStatus::Active,
    ]);
});

it('reports a missing owner fragment when vpn intent requires dns capability', function (): void {
    $node = Node::factory()->create([
        'name' => 'database-1',
        'tld' => 'database',
        'wireguard_address' => '10.6.0.9',
        'status' => 'active',
    ]);

    $drift = $this->probe->drift($node);

    expect($drift)
        ->not
        ->toBeEmpty()
        ->and($drift[0]->key)
        ->toBe('node.dns_mapping_mismatch')
        ->and($drift[0]->kind)
        ->toBe(DriftKind::Missing);
});

afterEach(function (): void {
    $root = $this->root ?? null;

    if (is_string($root) && is_dir($root)) {
        File::deleteDirectory($root);
    }
});

it('reports node-owned concrete record drift on the semantic source node', function (): void {
    $node = Node::factory()->create([
        'name' => 'database-1',
        'tld' => 'database',
        'wireguard_address' => '10.6.0.9',
        'status' => 'active',
    ]);
    File::put($this->root.'/dnsmasq.d/10-node-records.conf', "# stale\n");

    $drift = $this->probe->drift($node);

    expect($drift)
        ->toHaveCount(1)
        ->and($drift[0]->family)
        ->toBe('node')
        ->and($drift[0]->key)
        ->toBe('node.dns_mapping_mismatch')
        ->and($drift[0]->detail['record_kind'] ?? null)
        ->toBe('node_host')
        ->and($drift[0]->detail['node'] ?? null)
        ->toBe('database-1');
});

it('reports wildcard drift only when the node has an active wildcard role', function (): void {
    $node = Node::factory()->create([
        'name' => 'app-1',
        'tld' => 'test',
        'wireguard_address' => '10.6.0.7',
        'status' => 'active',
    ]);
    NodeRoleAssignment::factory()->create([
        'node_id' => $node->id,
        'role' => 'app-dev',
        'status' => NodeRoleStatus::Active,
    ]);
    File::put(
        $this->root.'/dnsmasq.d/10-node-records.conf',
        "address=/orbit.test/10.6.0.7\n",
    );

    $drift = $this->probe->drift($node->fresh('roleAssignments'));

    expect($drift)
        ->toHaveCount(1)
        ->and($drift[0]->key)
        ->toBe('node.dns_mapping_mismatch')
        ->and($drift[0]->detail['record_kind'] ?? null)
        ->toBe('wildcard');
});

it('does not treat a proxy fragment mismatch as node drift', function (): void {
    $node = Node::factory()->create([
        'name' => 'gateway',
        'tld' => 'gateway',
        'wireguard_address' => '10.6.0.2',
        'status' => 'active',
    ]);
    File::put(
        $this->root.'/dnsmasq.d/10-node-records.conf',
        new NodeDnsmasqRecordsBuilder()->buildGatewayState(),
    );
    File::put($this->root.'/dnsmasq.d/20-proxy-records.conf', "# stale proxy bytes\n");

    expect($this->probe->drift($node->fresh('roleAssignments')))->toBeEmpty();
});

it('reports orphaned directives once on the active gateway projection anchor', function (): void {
    $gateway = Node::factory()->create([
        'name' => 'gateway',
        'tld' => 'gateway',
        'wireguard_address' => '10.6.0.2',
        'status' => 'active',
    ]);
    NodeRoleAssignment::factory()->create([
        'node_id' => $gateway->id,
        'role' => 'gateway',
        'status' => NodeRoleStatus::Active,
    ]);
    $workload = Node::factory()->create([
        'name' => 'database-1',
        'tld' => 'database',
        'wireguard_address' => '10.6.0.9',
        'status' => 'active',
    ]);
    File::put(
        $this->root.'/dnsmasq.d/10-node-records.conf',
        new NodeDnsmasqRecordsBuilder()->buildGatewayState()."address=/orbit.deleted/10.6.0.99\n",
    );

    $gatewayDrift = $this->probe->drift($gateway->fresh('roleAssignments'));
    $workloadDrift = $this->probe->drift($workload->fresh('roleAssignments'));

    expect($gatewayDrift)
        ->toHaveCount(1)
        ->and($gatewayDrift[0]->detail['record_kind'] ?? null)
        ->toBe('orphan')
        ->and($gatewayDrift[0]->detail['actual'] ?? null)
        ->toBe(['address=/orbit.deleted/10.6.0.99'])
        ->and($workloadDrift)
        ->toBeEmpty();
});
