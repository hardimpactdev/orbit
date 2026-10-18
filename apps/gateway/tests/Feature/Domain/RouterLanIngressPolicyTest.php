<?php

declare(strict_types=1);

use App\Domain\Clusters\ClusterState;
use App\Domain\Firewall\RouterLanIngressPolicy;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\Firewall\NodeFirewallRuleCatalog;
use App\Infrastructure\Firewall\UfwRuleShape;
use App\Models\Node;

it('admits only active LAN-configured WireGuard members of the same active Cluster', function (): void {
    [$router, $eligible] = router_lan_topology();
    $denied = router_lan_denied_sources($router->cluster);

    $sources = array_map(
        static fn (Node $node): int => $node->id,
        new RouterLanIngressPolicy()->eligibleSources($router),
    );

    expect($sources)
        ->toBe([$eligible->id])
        ->and($sources)
        ->not->toContain($router->id)
        ->and($sources)
        ->not->toContain(...array_map(static fn (Node $node): int => $node->id, $denied));
});

it('renders exact Router-owned HTTPS and DNS rules for a roleless eligible member', function (): void {
    [$router, $eligible] = router_lan_topology();
    $catalog = new NodeFirewallRuleCatalog;
    $first = $catalog->routerLanIngress($router);
    $second = $catalog->forRole($router, RoleName::Router);

    expect($eligible->roles()->exists())->toBeFalse();

    foreach ([['https', 'tcp', '443'], ['dns-udp', 'udp', '53'], ['dns-tcp', 'tcp', '53']] as $index => [$family, $protocol, $port]) {
        expect($first[$index]->shape)->toEqual(new UfwRuleShape(
            comment: 'orbit:router-lan-'.$family.':'.$eligible->id,
            action: 'allow',
            direction: 'in',
            source: '10.20.0.11',
            destination: '10.20.0.10',
            port: $port,
            protocol: $protocol,
            inInterface: null,
            outInterface: null,
            family: 'v4',
        ));
        expect($first[$index]->arguments)->toBe([
            'sudo', 'ufw', 'allow', 'in', 'proto', $protocol,
            'from', '10.20.0.11', 'to', '10.20.0.10', 'port', $port,
            'comment', 'orbit:router-lan-'.$family.':'.$eligible->id,
        ]);
    }

    expect($first)
        ->toHaveCount(3)
        ->and($second)
        ->toEqual($first)
        ->and($first[0]->shape)
        ->toEqual(new UfwRuleShape(
            comment: 'orbit:router-lan-https:'.$eligible->id,
            action: 'allow',
            direction: 'in',
            source: '10.20.0.11',
            destination: '10.20.0.10',
            port: '443',
            protocol: 'tcp',
            inInterface: null,
            outInterface: null,
            family: 'v4',
        ))
        ->and($first[0]->arguments)
        ->toBe([
            'sudo',
            'ufw',
            'allow',
            'in',
            'proto',
            'tcp',
            'from',
            '10.20.0.11',
            'to',
            '10.20.0.10',
            'port',
            '443',
            'comment',
            'orbit:router-lan-https:'.$eligible->id,
        ])
        ->and(array_map(static fn ($rule) => $rule->shape->comment, $catalog->forNode($router)))
        ->toBe(['orbit:public-ssh-recovery', 'orbit:wireguard-members'])
        ->and($catalog->forRole($router, RoleName::AppProd))
        ->toBe([])
        ->and($catalog->metricsGrafanaUpstream($router, '10.44.0.1')->shape->comment)
        ->toBe('orbit:metrics-grafana-upstream');
});

it('uses proposed membership addresses and activation for every family without committing them', function (): void {
    [$router, $member] = router_lan_topology();
    $candidate = router_lan_node('candidate', '10.44.0.21', '10.20.0.21', status: LifecycleStatus::Provisioning);
    $catalog = new NodeFirewallRuleCatalog;
    $rules = $catalog->routerLanIngress($router, [
        $member->id => ['cluster_id' => null],
        $candidate->id => ['cluster_id' => $router->cluster_id, 'status' => LifecycleStatus::Active, 'lan_ip' => '10.20.0.31'],
        $router->id => ['lan_ip' => '10.20.0.30'],
    ]);

    expect(array_map(static fn ($rule): string => $rule->shape->comment, $rules))->toBe([
        'orbit:router-lan-https:'.$candidate->id,
        'orbit:router-lan-dns-udp:'.$candidate->id,
        'orbit:router-lan-dns-tcp:'.$candidate->id,
    ]);
    foreach ($rules as $rule) {
        expect($rule->shape->source)->toBe('10.20.0.31');
        expect($rule->shape->destination)->toBe('10.20.0.30');
    }
    expect($candidate->fresh()?->cluster_id)->toBeNull();
    expect($member->fresh()?->cluster_id)->toBe($router->cluster_id);
    expect($router->fresh()?->lan_ip)->toBe('10.20.0.10');
    expect($catalog->routerLanIngress($router, clusterOverrides: [$router->cluster_id => ['state' => ClusterState::Inactive]]))->toBe([]);
});

it('excludes invalid or incomplete source identity from every family', function (array $override): void {
    [$router, $member] = router_lan_topology();

    expect(new NodeFirewallRuleCatalog()->routerLanIngress($router, [$member->id => $override]))->toBe([]);
})->with([
    'missing LAN' => [['lan_ip' => null]],
    'IPv6 LAN' => [['lan_ip' => '2001:db8::11']],
    'invalid LAN' => [['lan_ip' => 'not-an-ip']],
    'missing WireGuard' => [['wireguard_ip' => null]],
    'IPv6 WireGuard' => [['wireguard_ip' => '2001:db8::11']],
    'missing key' => [['wireguard_public_key' => null]],
    'empty key' => [['wireguard_public_key' => '']],
    'inactive source' => [['status' => LifecycleStatus::Failed]],
    'detached source' => [['cluster_id' => null]],
]);

it('excludes a Router without complete IPv4 identity from every family', function (array $override): void {
    [$router] = router_lan_topology();

    expect(new NodeFirewallRuleCatalog()->routerLanIngress($router, [$router->id => $override]))->toBe([]);
})->with([
    'missing LAN' => [['lan_ip' => null]],
    'missing WireGuard' => [['wireguard_ip' => null]],
    'IPv6 WireGuard' => [['wireguard_ip' => '2001:db8::10']],
    'empty key' => [['wireguard_public_key' => '']],
    'no Cluster' => [['cluster_id' => null]],
]);

it('drops LAN ingress when the Cluster is inactive or the Router has no LAN', function (): void {
    [$router] = router_lan_topology();
    $router->cluster->update(['state' => ClusterState::Inactive]);

    expect(new NodeFirewallRuleCatalog()->routerLanIngress($router->refresh()->load('cluster')))
        ->toBe([]);

    $router->cluster->update(['state' => ClusterState::Active]);
    $router->update(['lan_ip' => null]);

    expect(new NodeFirewallRuleCatalog()->routerLanIngress($router->refresh()))
        ->toBe([]);
});
