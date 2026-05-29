<?php

declare(strict_types=1);

namespace App\Services\WebSockets;

use App\Enums\Nodes\NodeRoleName;
use App\Models\Node;
use App\Models\ProxyRoute;
use App\Services\Nodes\Roles\NodeRoleAssignments;
use App\Services\Proxy\ProxyRouteRenderer;
use RuntimeException;

class WebSocketRouteRegistrar
{
    public const string ServiceDomain = 'websocket.orbit';

    private const int BackendPort = 8080;

    public function __construct(
        private readonly NodeRoleAssignments $nodeRoleAssignments,
        private readonly ProxyRouteRenderer $proxyRouteRenderer,
        private readonly WebSocketBackendName $backendName,
    ) {}

    public function syncServiceRoute(): ProxyRoute
    {
        $router = $this->routerNode();
        $config = $this->serviceRouteConfig($router, $this->webSocketBackends());

        $route = ProxyRoute::query()->updateOrCreate(
            ['domain' => self::ServiceDomain],
            [
                'node_id' => $router->id,
                'app_id' => null,
                'workspace_id' => null,
                'owner_type' => 'websocket',
                'kind' => 'proxy',
                'config' => $config,
                'source_hash' => $this->sourceHash($router, $config),
            ],
        );

        return $route->refresh();
    }

    private function routerNode(): Node
    {
        $router = $this->nodeRoleAssignments->activeRouterNodeQuery()
            ->orderBy('id')
            ->first();

        if (! $router instanceof Node) {
            throw new RuntimeException('The websocket service route requires an active router node.');
        }

        if ($this->wireGuardAddress($router) === '') {
            throw new RuntimeException('The websocket service route requires the router node to have a WireGuard address.');
        }

        return $router;
    }

    /**
     * @return list<Node>
     */
    private function webSocketBackends(): array
    {
        /** @var list<Node> $nodes */
        $nodes = Node::query()
            ->where('status', Node::STATUS_ACTIVE)
            ->whereIn('id', $this->nodeRoleAssignments->activeNodeIdsForRole(NodeRoleName::WebSocket->value))
            ->orderBy('name')
            ->get()
            ->all();

        if ($nodes === []) {
            throw new RuntimeException('The websocket service route requires at least one active websocket node.');
        }

        return $nodes;
    }

    /**
     * @param  list<Node>  $backends
     * @return array<string, mixed>
     */
    private function serviceRouteConfig(Node $router, array $backends): array
    {
        $upstreams = array_map($this->upstream(...), $backends);

        return [
            'protocol' => 'websocket',
            'router_upstream' => [
                'node_id' => $router->id,
                'node' => $router->name,
                'url' => $this->routerUrl($router),
            ],
            'router_backend_pool' => array_map(
                fn (array $upstream): array => [
                    'node_id' => $upstream['node_id'],
                    'node' => $upstream['node'],
                    'url' => $upstream['url'],
                ],
                $upstreams,
            ),
            'upstreams' => $upstreams,
            'tls' => [
                'managed_by' => 'internal',
                'trusted_by_gateway_ca' => true,
            ],
        ];
    }

    /**
     * @return array{
     *     node_id: int,
     *     node: string,
     *     scheme: string,
     *     host: string,
     *     port: int,
     *     url: string,
     * }
     */
    private function upstream(Node $node): array
    {
        $host = $this->backendName->forNode($node);

        return [
            'node_id' => $node->id,
            'node' => $node->name,
            'scheme' => 'https',
            'host' => $host,
            'port' => self::BackendPort,
            'url' => "https://{$host}:".self::BackendPort,
        ];
    }

    private function routerUrl(Node $router): string
    {
        return "http://{$this->wireGuardAddress($router)}:80";
    }

    private function wireGuardAddress(Node $node): string
    {
        return is_string($node->wireguard_address) ? trim($node->wireguard_address) : '';
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function sourceHash(Node $router, array $config): string
    {
        return $this->proxyRouteRenderer->sourceHash(new ProxyRoute([
            'node_id' => $router->id,
            'domain' => self::ServiceDomain,
            'owner_type' => 'websocket',
            'kind' => 'proxy',
            'config' => $config,
        ]));
    }
}
