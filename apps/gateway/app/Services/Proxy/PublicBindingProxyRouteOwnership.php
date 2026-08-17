<?php

declare(strict_types=1);

namespace App\Services\Proxy;

use App\Enums\Nodes\NodeStatus;
use App\Models\Instance;
use App\Models\Node;
use App\Models\ProxyRoute;
use App\Services\Analytics\AnalyticsRouteRegistrar;
use App\Services\Nodes\Roles\NodeRoleAssignments;
use App\Services\WebSockets\WebSocketRouteRegistrar;
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

        $protocol = $route->owner_type === 'app-analytics' ? 'analytics' : 'websocket';
        $serviceTarget = $protocol === 'analytics'
            ? AnalyticsRouteRegistrar::PublicServiceTarget
            : WebSocketRouteRegistrar::PublicServiceTarget;
        $backendPool = $this->backendPool($protocol);

        if ($backendPool === []) {
            return false;
        }

        $expectedConfig = [
            'placement' => 'ingress',
            'ingress_node_id' => $ingress->id,
            'protocol' => $protocol,
            'target' => ['type' => $protocol, 'value' => $serviceTarget],
            'upstream' => $serviceTarget,
            'router_upstream' => [
                'node_id' => $router->id,
                'node' => $router->name,
                'url' => $routerUrl,
            ],
            'router_backend_pool' => $backendPool,
            'tls' => [
                'cert_path' => "/etc/orbit/certs/{$route->domain}.crt",
                'key_path' => "/etc/orbit/certs/{$route->domain}.key",
            ],
        ];

        if ($protocol !== 'analytics') {
            $expectedConfig['router_backend_tls'] = [
                'trusted_by_gateway_ca' => true,
                'ca_path' => '/etc/orbit/ca/root.crt',
            ];
        } else {
            $expectedConfig['tracking_paths'] = AnalyticsRouteRegistrar::TrackingPaths;
        }

        $expectedNodeId = ! $route->exists && $route->node_id === $router->id
            ? $router->id
            : $ingress->id;
        $expected = new ProxyRoute([
            'node_id' => $expectedNodeId,
            'domain' => $route->domain,
            'app_id' => $instance->app_id,
            'workspace_id' => null,
            'instance_id' => $instance->id,
            'owner_type' => $route->owner_type,
            'kind' => 'proxy',
            'config' => $expectedConfig,
        ]);

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

            if ($protocol === 'websocket' && $node->status !== NodeStatus::Active) {
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
