<?php

declare(strict_types=1);

namespace App\Services\Proxy;

use App\Enums\Nodes\NodeStatus;
use App\Models\Instance;
use App\Models\Node;
use App\Models\ProxyRoute;
use App\Services\Nodes\Roles\NodeRoleAssignments;
use App\Services\Workspaces\WorkspacePlacement;
use DomainException;

/** @mago-expect lint:cyclomatic-complexity */
final readonly class PublicBindingProxyRouteOwnership
{
    public function __construct(
        private InstanceProxyRouteOwnershipResolver $routeOwnership,
        private WorkspacePlacement $placement,
        private IngressResolver $ingressResolver,
        private NodeRoleAssignments $nodeRoleAssignments,
    ) {}

    public function matches(ProxyRoute $route): bool
    {
        if (! InstanceProxyRouteOwnershipResolver::isPublicBindingOwner($route->owner_type)) {
            return false;
        }

        $instance = $this->routeOwnership->resolve($route);

        if (! $instance instanceof Instance) {
            return false;
        }

        $appNode = $this->placement->nodeForInstance($instance);

        if (! $appNode instanceof Node) {
            return false;
        }

        try {
            $ingress = $this->ingressResolver->forAppNode($appNode);
            $router = $this->ingressResolver->router();
            $routerUrl = $this->ingressResolver->routerUrl($router);
        } catch (DomainException) {
            return false;
        }

        $definition = PublicBindingProxyRouteDefinition::forOwnerType($route->owner_type);
        $backendPool = $this->backendPool($definition->protocol);

        if ($backendPool === []) {
            return false;
        }

        $expectedConfig = $definition->config(
            $ingress,
            $router,
            $route->domain,
            $routerUrl,
            $backendPool,
        );
        $expectedNodeId = ! $route->exists && $route->node_id === $router->id
            ? $router->id
            : $ingress->id;
        $expected = $definition->route($instance, $route->domain, $expectedNodeId, $expectedConfig);

        if (! ProxyRouteOwnershipCompatibility::matches($route, $expected, array_keys($expectedConfig))) {
            return false;
        }

        return ! $route->exists || $this->matchesRouterArtifact($route, $router);
    }

    /**
     * @return list<array{node_id: int, node: string, url: string}>
     */
    private function backendPool(string $protocol): array
    {
        $role = $protocol === 'analytics' ? 'analytics' : 'websocket';
        $scheme = $protocol === 'analytics' ? 'http' : 'https';
        $port = $protocol === 'analytics' ? 8000 : 8080;

        $nodes = [];

        foreach ($this->nodeRoleAssignments->activeNodeIdsForRole($role) as $nodeId) {
            $node = Node::query()->find($nodeId);

            if (! $node instanceof Node) {
                return [];
            }

            if ($node->status !== NodeStatus::Active) {
                return [];
            }

            $nodes[] = $node;
        }

        if (count($nodes) !== 1) {
            return [];
        }

        usort($nodes, static fn (Node $left, Node $right): int => $left->name <=> $right->name);

        $backends = [];

        foreach ($nodes as $node) {
            $wireGuardAddress = is_string($node->wireguard_address)
                ? trim($node->wireguard_address)
                : '';

            if ($wireGuardAddress === '') {
                return [];
            }

            $backends[] = [
                'node_id' => $node->id,
                'node' => $node->name,
                'url' => "{$scheme}://{$wireGuardAddress}:{$port}",
            ];
        }

        return $backends;
    }

    private function matchesRouterArtifact(ProxyRoute $route, Node $router): bool
    {
        $config = is_array($route->config) ? $route->config : [];
        $artifact = is_array($config['router_artifact'] ?? null) ? $config['router_artifact'] : [];
        $sourceHash = $artifact['source_hash'] ?? null;

        return (
            ($artifact['node_id'] ?? null) === $router->id
            && ($artifact['node'] ?? null) === $router->name
            && is_string($sourceHash)
            && mb_strlen($sourceHash) === 64
        );
    }
}
