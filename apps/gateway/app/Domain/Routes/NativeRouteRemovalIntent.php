<?php

declare(strict_types=1);

namespace App\Domain\Routes;

use App\Actions\Routes\RouteRemovalState;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Infrastructure\AgentCommands\AgentDelivery;
use App\Models\Node;
use App\Models\Route;
use Illuminate\Support\Collection;

/** Stored removal inventory. Swamp owns placement, ordering and all effects. */
final readonly class NativeRouteRemovalIntent
{
    public function __construct(private ManagedUserAccountResolver $accounts, private AgentDelivery $agents, private RouteRemovalState $removal) {}

    /** @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function forRoute(Route $route, array $input = []): array
    {
        if ($route->isUnactivatedReplacement()) {
            $route->update(['status' => RouteStatus::Failed, 'sites_published' => false,
                'failed_step' => $route->failed_step ?? 'withdrawal', 'error_code' => $route->error_code ?? 'route.replacement_withdrawn']);
        } else {
            $this->removal->beginRemoval($route);
        }

        return [...$this->capture($route), 'offline' => ($input['offline'] ?? false) === true];
    }

    /** @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function capture(Route $route, array $input = []): array
    {
        return [...$this->captureMany(new Collection([$route])), 'offline' => ($input['offline'] ?? false) === true];
    }

    /** Pure capture. The admitting parent records withdrawal before it starts Swamp.
     * @param  Collection<int,Route>  $routes
     * @param  list<Node>  $alwaysPhpNodes  Include the workload Node even when it has no Route.
     * @return array<string,mixed>
     */
    public function captureMany(Collection $routes, array $alwaysPhpNodes = []): array
    {
        $nodes = [];
        $routeRows = [];
        foreach ($routes as $route) {
            $inventory = $this->inventory($route);
            $routeRows[] = ['route_id' => $route->id, 'domain' => $route->domain, 'replacement_of' => $inventory['replacement_of']];
            foreach ($inventory['nodes'] as $node) {
                if (! isset($nodes[$node['node_id']])) {
                    $nodes[$node['node_id']] = $node;

                    continue;
                }
                $current = &$nodes[$node['node_id']];
                foreach (['certificate_scopes', 'lan_route_ids', 'production_instance_ids', 'url_instance_ids'] as $key) {
                    $current[$key] = array_values(array_unique([...$current[$key], ...$node[$key]]));
                    sort($current[$key]);
                }
                $current['shared_php'] = $current['shared_php'] || $node['shared_php'];
                $current['ingress_firewall'] = $current['ingress_firewall'] || $node['ingress_firewall'];
                unset($current);
            }
        }
        foreach ($alwaysPhpNodes as $node) {
            if (! isset($nodes[$node->id])) {
                $account = $this->accounts->resolve($node);
                $nodes[$node->id] = ['node_id' => $node->id, 'user' => $account->user, 'home' => $account->home,
                    'agent_sha256' => $this->agents->pin($node), 'certificate_scopes' => [], 'lan_route_ids' => [],
                    'shared_php' => true, 'production_instance_ids' => [], 'url_instance_ids' => [], 'ingress_firewall' => false];
            }
            $nodes[$node->id]['shared_php'] = true;
        }
        ksort($nodes);
        usort($routeRows, static fn (array $a, array $b): int => $a['route_id'] <=> $b['route_id']);

        return ['routes' => $routeRows, 'nodes' => array_values($nodes),
            'dns_node_ids' => $routes->isEmpty() ? [] : PrivateDnsPublicationNodes::query()->orderBy('id')->pluck('id')->all()];
    }

    /** @return array<string,mixed> */
    private function inventory(Route $route): array
    {
        $route->loadMissing(['targets.instance.node', 'node', 'transitionNode', 'generationBasisNode',
            'cluster.routerAssignment.node', 'cluster.ingressAssignment.node',
            'transitionCluster.routerAssignment.node', 'transitionCluster.ingressAssignment.node']);
        $replacement = $route->isUnactivatedReplacement();
        $nodes = [];
        $add = function (?Node $node) use (&$nodes): void {
            if ($node !== null && ! isset($nodes[$node->id])) {
                $account = $this->accounts->resolve($node);
                $nodes[$node->id] = ['node_id' => $node->id, 'user' => $account->user, 'home' => $account->home,
                    'agent_sha256' => $this->agents->pin($node), 'certificate_scopes' => [], 'lan_route_ids' => [],
                    'shared_php' => false, 'production_instance_ids' => [], 'url_instance_ids' => [], 'ingress_firewall' => false];
            }
        };
        foreach ($route->targets as $target) {
            $instance = $target->instance;
            $add($instance->node);
            $node = &$nodes[$instance->node_id];
            $node['shared_php'] = ! $instance->placedOnAppProd() || $node['shared_php'];
            $node['lan_route_ids'][] = $route->id;
            if ($route->hasWebRoot()) {
                $node['certificate_scopes'][] = "route-{$route->id}";
                $node['url_instance_ids'][] = $instance->id;
                if ($instance->placedOnAppProd()) {
                    $node['production_instance_ids'][] = $instance->id;
                }
            }
            if ($replacement || $route->hasPlacementTransition()) {
                $node['certificate_scopes'][] = "app-instance-{$instance->id}-hostname-change";
            }
            unset($node);
        }
        foreach ([$route->node, $route->transitionNode, $route->generationBasisNode] as $node) {
            $add($node);
            if ($node !== null) {
                $nodes[$node->id]['lan_route_ids'][] = $route->id;
                if ($route->isCustomProxy()) {
                    $nodes[$node->id]['certificate_scopes'][] = "route-{$route->id}";
                } elseif ($route->isAnalyticsTracking()) {
                    $nodes[$node->id]['certificate_scopes'][] = "route-{$route->id}-router";
                }
            }
        }
        foreach ([$route->cluster?->routerAssignment?->node, $route->transitionCluster?->routerAssignment?->node] as $node) {
            $add($node);
            if ($node !== null) {
                $nodes[$node->id]['certificate_scopes'] = [...$nodes[$node->id]['certificate_scopes'], "route-{$route->id}-router", "route-{$route->id}-router-hostname-change"];
            }
        }
        if ($route->publication === RoutePublication::Public) {
            foreach ([$route->cluster?->ingressAssignment?->node, $route->transitionCluster?->ingressAssignment?->node] as $node) {
                $add($node);
                if ($node !== null) {
                    $nodes[$node->id]['ingress_firewall'] = true;
                    $nodes[$node->id]['certificate_scopes'][] = "route-{$route->id}-ingress";
                }
            }
        }
        ksort($nodes);
        foreach ($nodes as &$node) {
            foreach (['certificate_scopes', 'lan_route_ids', 'production_instance_ids', 'url_instance_ids'] as $key) {
                $node[$key] = array_values(array_unique($node[$key]));
                sort($node[$key]);
            }
        }
        unset($node);

        return ['route_id' => $route->id, 'replacement_of' => $replacement ? $route->replaces_route_id : null,
            'nodes' => array_values($nodes)];
    }
}
