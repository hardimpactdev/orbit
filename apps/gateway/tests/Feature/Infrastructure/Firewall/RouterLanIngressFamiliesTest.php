<?php

declare(strict_types=1);

use App\Domain\Clusters\ClusterState;
use App\Domain\Firewall\RouterLanIngressPolicy;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\Firewall\NodeFirewallRuleCatalog;
use App\Models\Cluster;
use App\Models\Node;
use App\Models\NodeRole;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

function lanNode(Cluster $cluster, string $name, int $host): Node
{
    return Node::withoutEvents(fn (): Node => Node::query()->create(['name' => $name, 'status' => 'active', 'platform' => 'linux', 'architecture' => 'x86_64',
        'public_ssh_host' => '10.44.0.'.$host, 'wireguard_ip' => '10.44.0.'.$host, 'lan_ip' => '192.168.1.'.$host, 'user' => 'orbit', 'cluster_id' => $cluster->id,
        'wireguard_public_key' => $name.'-key']));
}

describe('Router LAN rules', function (): void {
    it('admit an eligible member over HTTPS only: nothing on a Router LAN listens for DNS (ADR 0248)', function (): void {
        $cluster = Cluster::query()->create(['name' => 'dev', 'tld' => 'test']);
        $cluster->forceFill(['state' => ClusterState::Active])->save();
        $router = lanNode($cluster, 'beast', 10);
        NodeRole::query()->create(['node_id' => $router->id, 'role' => RoleName::Router, 'status' => LifecycleStatus::Active, 'cluster_id' => $cluster->id]);
        lanNode($cluster, 'sabre', 11);

        $rules = app(NodeFirewallRuleCatalog::class)->routerLanIngress($router);

        expect(array_keys(RouterLanIngressPolicy::Families))->toBe(['orbit:router-lan-https'])
            ->and(array_map(static fn ($rule): string => $rule->shape->comment, $rules))->toBe(['orbit:router-lan-https:'.Node::query()->where('name', 'sabre')->value('id')])
            ->and($rules[0]->shape->port)->toBe('443');
    });
});
