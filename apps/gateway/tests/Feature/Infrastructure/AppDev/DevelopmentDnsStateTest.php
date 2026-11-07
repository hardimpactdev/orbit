<?php

declare(strict_types=1);

use App\Domain\Clusters\ClusterState;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\AppDev\DevelopmentDnsConfigRenderer;
use App\Models\Cluster;
use App\Models\Node;
use App\Models\NodeRole;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

function dnsNode(string $name, string $address, RoleName $role, ?int $clusterId = null, ?string $tld = null): Node
{
    $node = Node::withoutEvents(fn (): Node => Node::query()->create(['name' => $name, 'status' => 'active', 'platform' => 'linux', 'architecture' => 'x86_64',
        'public_ssh_host' => $address, 'wireguard_ip' => $address, 'user' => 'orbit', 'cluster_id' => $clusterId, 'tld' => $tld,
        'wireguard_public_key' => $name.'-key']));
    NodeRole::query()->create(['node_id' => $node->id, 'role' => $role, 'status' => LifecycleStatus::Active, 'cluster_id' => $role === RoleName::Router ? $clusterId : null]);

    return $node;
}

describe('DevelopmentDnsConfigRenderer state', function (): void {
    it('renders the whole private DNS from stored rows', function (): void {
        $cluster = Cluster::query()->create(['name' => 'dev', 'tld' => 'test']);
        $cluster->forceFill(['state' => ClusterState::Active])->save();
        dnsNode('vpn', '10.44.0.1', RoleName::Vpn);
        dnsNode('gateway', '10.44.0.2', RoleName::Gateway);
        dnsNode('beast', '10.44.0.7', RoleName::Router, $cluster->id, 'test');
        dnsNode('app-prod', '10.44.0.4', RoleName::AppDev, null, 'prod');

        $state = app(DevelopmentDnsConfigRenderer::class)->state()->toIntent();

        expect($state['listen'])->toBe(['10.44.0.1'])
            ->and($state['tlds'])->toBe([['name' => 'prod', 'address' => '10.44.0.4'], ['name' => 'test', 'address' => '10.44.0.7']])
            ->and($state['records'])->toContain(['name' => 'gateway.orbit', 'address' => '10.44.0.2', 'pair' => true]);
    });

    it('lets a Cluster TLD win over a Node that owns the same TLD', function (): void {
        $cluster = Cluster::query()->create(['name' => 'dev', 'tld' => 'test']);
        $cluster->forceFill(['state' => ClusterState::Active])->save();
        dnsNode('vpn', '10.44.0.1', RoleName::Vpn);
        dnsNode('router', '10.44.0.7', RoleName::Router, $cluster->id);
        dnsNode('dev-node', '10.44.0.9', RoleName::AppDev, null, 'test');

        $tlds = app(DevelopmentDnsConfigRenderer::class)->state()->toIntent()['tlds'];

        expect($tlds)->toBe([['name' => 'test', 'address' => '10.44.0.7']]);
    });
});
