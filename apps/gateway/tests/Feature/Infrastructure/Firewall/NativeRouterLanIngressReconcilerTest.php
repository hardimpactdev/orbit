<?php

declare(strict_types=1);

use App\Domain\Clusters\ClusterState;
use App\Domain\Firewall\RouterLanIngressPolicy;
use App\Domain\Firewall\RouterLanIngressPublisher;
use App\Domain\Nodes\ManagedUserAccount;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\Firewall\NativeRouterLanIngressReconciler;
use App\Infrastructure\Firewall\NodeFirewallRuleCatalog;
use App\Infrastructure\Firewall\UfwManagedRule;
use App\Models\Node;

it('publishes desired Router LAN rules only for contactable Cluster Routers', function (): void {
    [$router, $eligible] = router_lan_topology();
    $publisher = new class implements RouterLanIngressPublisher
    {
        /** @var list<array{phase: string, router: int, comments: list<string>}> */
        public array $events = [];

        public function expandRouterLanIngress(Node $router, array $desired, string $managedUser): void
        {
            $this->record('expand', $router, $desired, $managedUser);
        }

        public function pruneRouterLanIngress(Node $router, array $desired, string $managedUser): void
        {
            $this->record('prune', $router, $desired, $managedUser);
        }

        /** @param list<UfwManagedRule> $desired */
        private function record(string $phase, Node $router, array $desired, string $managedUser): void
        {
            expect($managedUser)->toBe('orbit');

            $this->events[] = [
                'phase' => $phase,
                'router' => $router->id,
                'comments' => array_map(
                    static fn (UfwManagedRule $rule): string => $rule->shape->comment,
                    $desired,
                ),
            ];
        }
    };
    $accounts = new class implements ManagedUserAccountResolver
    {
        public function resolve(Node $node): ManagedUserAccount
        {
            return new ManagedUserAccount(user: 'orbit', group: 'orbit', home: '/home/orbit');
        }
    };
    $reconciler = new NativeRouterLanIngressReconciler(
        new RouterLanIngressPolicy,
        new NodeFirewallRuleCatalog,
        $publisher,
        $accounts,
    );

    $clusterId = $router->cluster_id;
    assert(is_int($clusterId));

    $reconciler->expand(clusterIds: [$clusterId]);
    $reconciler->prune(clusterIds: [$clusterId]);

    expect($publisher->events)->toBe([
        [
            'phase' => 'expand',
            'router' => $router->id,
            'comments' => [
                'orbit:router-lan-https:'.$eligible->id,
                'orbit:router-lan-dns-udp:'.$eligible->id,
                'orbit:router-lan-dns-tcp:'.$eligible->id,
            ],
        ],
        [
            'phase' => 'prune',
            'router' => $router->id,
            'comments' => [
                'orbit:router-lan-https:'.$eligible->id,
                'orbit:router-lan-dns-udp:'.$eligible->id,
                'orbit:router-lan-dns-tcp:'.$eligible->id,
            ],
        ],
    ]);

    $candidate = router_lan_node('candidate', '10.44.0.21', '10.20.0.21');
    $reconciler->expand(nodeOverrides: [
        $eligible->id => ['cluster_id' => null],
        $candidate->id => ['cluster_id' => $clusterId],
    ]);
    expect($publisher->events[2]['comments'])->toBe([
        'orbit:router-lan-https:'.$candidate->id,
        'orbit:router-lan-dns-udp:'.$candidate->id,
        'orbit:router-lan-dns-tcp:'.$candidate->id,
    ]);

    $reconciler->prune(nodeOverrides: [$eligible->id => ['cluster_id' => null]]);
    $reconciler->prune(clusterOverrides: [$clusterId => ['state' => ClusterState::Inactive]]);
    $reconciler->prune(nodeOverrides: [$eligible->id => ['status' => LifecycleStatus::Failed]]);
    $reconciler->prune(nodeOverrides: [$router->id => ['lan_ip' => null]]);
    foreach (array_slice($publisher->events, 3) as $event) {
        expect($event['comments'])->toBe([]);
    }
    expect($publisher->events)->toHaveCount(7);

    $router->update(['lan_ip' => null]);
    $reconciler->prune(clusterIds: [$clusterId]);
    expect($publisher->events)->toHaveCount(7);
});
