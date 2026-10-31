<?php

declare(strict_types=1);

namespace App\Domain\Routes;

use App\Actions\Routes\RouteRemovalState;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Infrastructure\AgentCommands\AgentDelivery;
use App\Models\Node;
use App\Models\Route;
use Illuminate\Support\Collection;

/** Stored removal inventory. Swamp owns placement, ordering and all effects.
 * @phpstan-type RemovalSnapshot array{routes:list<array{route_id:int,domain:string,replacement_of:?int}>,nodes:list<RemovalMember>,dns_node_ids:array<mixed>,excluded_effect_node_ids:list<int>,offline?:bool}
 * @phpstan-type RemovalMember array{node_id:int,user:string,home:string,agent_sha256:string,certificate_scopes:list<string>,lan_route_ids:list<int>,shared_php:bool,production_instance_ids:list<int>,url_instance_ids:list<int>,ingress_firewall:bool}
 */
final readonly class NativeRouteRemovalIntent
{
    public function __construct(private ManagedUserAccountResolver $accounts, private AgentDelivery $agents, private RouteRemovalState $removal) {}

    /** @param array<string,mixed> $input
     * @return RemovalSnapshot
     */
    public function forRoute(Route $route, array $input = []): array
    {
        $this->beginWithdrawal($route);

        return [...$this->capture($route), 'offline' => ($input['offline'] ?? false) === true];
    }

    /** Records desired withdrawal only; resolves no Node account and performs no host effect. */
    public function beginWithdrawal(Route $route): void
    {
        if ($route->isUnactivatedReplacement()) {
            $route->update(['status' => RouteStatus::Failed, 'sites_published' => false,
                'failed_step' => $route->failed_step ?? 'withdrawal', 'error_code' => $route->error_code ?? 'route.replacement_withdrawn']);
        } else {
            $this->removal->beginRemoval($route);
        }

    }

    /** @param array<string,mixed> $input
     * @return RemovalSnapshot
     */
    public function capture(Route $route, array $input = []): array
    {
        return [...$this->captureMany(new Collection([$route])), 'offline' => ($input['offline'] ?? false) === true];
    }

    /** Pure capture. The admitting parent records withdrawal before it starts Swamp.
     * @param  Collection<int,Route>  $routes
     * @param  list<int>  $excludedEffectNodeIds  Explicit parent-owned offline/provider-absent effect Nodes only.
     * @param  list<Node>  $alwaysPhpNodes  Include the workload Node even when it has no Route.
     * @return RemovalSnapshot
     */
    public function captureMany(Collection $routes, array $alwaysPhpNodes = [], array $excludedEffectNodeIds = []): array
    {
        $nodes = [];
        $routeRows = [];
        foreach ($routes as $route) {
            $inventory = $this->inventory($route, $excludedEffectNodeIds);
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
            if (in_array($node->id, $excludedEffectNodeIds, true)) {
                continue;
            }
            if (! isset($nodes[$node->id])) {
                $nodes[$node->id] = $this->memberForNode($node);
            }
            $nodes[$node->id]['shared_php'] = true;
        }
        ksort($nodes);
        usort($routeRows, static fn (array $a, array $b): int => $a['route_id'] <=> $b['route_id']);

        return ['routes' => $routeRows, 'nodes' => array_values($nodes),
            'dns_node_ids' => $routes->isEmpty() ? [] : PrivateDnsPublicationNodes::query()->orderBy('id')->pluck('id')->all(),
            'excluded_effect_node_ids' => $excludedEffectNodeIds];
    }

    /** @return RemovalMember */
    private function memberForNode(Node $node): array
    {
        $account = $this->accounts->resolve($node);

        return ['node_id' => $node->id, 'user' => $account->user, 'home' => $account->home,
            'agent_sha256' => $this->agents->pin($node), 'certificate_scopes' => [], 'lan_route_ids' => [],
            'shared_php' => false, 'production_instance_ids' => [], 'url_instance_ids' => [], 'ingress_firewall' => false];
    }

    /** @param list<int> $excludedEffectNodeIds
     * @return array{route_id:int,replacement_of:?int,nodes:list<RemovalMember>} */
    private function inventory(Route $route, array $excludedEffectNodeIds): array
    {
        $route->loadMissing(['targets.instance.node', 'customProxy.node', 'node', 'transitionNode', 'generationBasisNode',
            'cluster.routerAssignment.node', 'cluster.ingressAssignment.node',
            'transitionCluster.routerAssignment.node', 'transitionCluster.ingressAssignment.node']);
        $replacement = $route->isUnactivatedReplacement();
        $nodes = [];
        foreach ($route->targets as $target) {
            $instance = $target->instance;
            if (in_array($instance->node_id, $excludedEffectNodeIds, true)) {
                continue;
            }
            $nodes[$instance->node_id] ??= $this->memberForNode($instance->node);
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
            if ($replacement) {
                $node['url_instance_ids'][] = $instance->id;
            }
            if ($replacement || $route->hasPlacementTransition()) {
                $node['certificate_scopes'][] = "app-instance-{$instance->id}-hostname-change";
            }
            unset($node);
        }
        foreach ([$route->node, $route->customProxy?->node, $route->transitionNode, $route->generationBasisNode] as $node) {
            if ($node !== null && ! in_array($node->id, $excludedEffectNodeIds, true)) {
                $nodes[$node->id] ??= $this->memberForNode($node);
                $nodes[$node->id]['lan_route_ids'][] = $route->id;
                if ($route->isCustomProxy()) {
                    $nodes[$node->id]['certificate_scopes'][] = "route-{$route->id}";
                } elseif ($route->isAnalyticsTracking()) {
                    $nodes[$node->id]['certificate_scopes'][] = "route-{$route->id}-router";
                }
            }
        }
        foreach ([$route->cluster?->routerAssignment?->node, $route->transitionCluster?->routerAssignment?->node] as $node) {
            if ($node !== null && ! in_array($node->id, $excludedEffectNodeIds, true)) {
                $nodes[$node->id] ??= $this->memberForNode($node);
                $nodes[$node->id]['certificate_scopes'] = [...$nodes[$node->id]['certificate_scopes'], "route-{$route->id}-router", "route-{$route->id}-router-hostname-change"];
            }
        }
        if ($route->publication === RoutePublication::Public) {
            foreach ([$route->cluster?->ingressAssignment?->node, $route->transitionCluster?->ingressAssignment?->node] as $node) {
                if ($node !== null && ! in_array($node->id, $excludedEffectNodeIds, true)) {
                    $nodes[$node->id] ??= $this->memberForNode($node);
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
