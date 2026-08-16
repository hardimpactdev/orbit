<?php

declare(strict_types=1);

namespace App\Services\WebSockets;

use App\Enums\Nodes\NodeRoleName;
use App\Enums\Nodes\NodeStatus;
use App\Models\AppWebSocketBinding;
use App\Models\Instance;
use App\Models\Node;
use App\Models\ProxyRoute;
use App\Services\Dns\DnsmasqReconciler;
use App\Services\Nodes\Roles\NodeRoleAssignments;
use App\Services\Proxy\IngressResolver;
use App\Services\Proxy\ProxyRouteRenderer;
use App\Services\Workspaces\WorkspacePlacement;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** @mago-expect lint:excessive-parameter-list */
class WebSocketRouteRegistrar
{
    public const string ServiceDomain = 'websocket.orbit';

    public const string PublicServiceTarget = 'https://websocket.orbit';

    private const int BackendPort = 8080;

    public function __construct(
        private readonly NodeRoleAssignments $nodeRoleAssignments,
        private readonly IngressResolver $ingressResolver,
        private readonly ProxyRouteRenderer $proxyRouteRenderer,
        private readonly WebSocketBackendName $backendName,
        private readonly DnsmasqReconciler $dnsmasqReconciler,
        private readonly WorkspacePlacement $placement,
    ) {}

    public function syncServiceRoute(): ProxyRoute
    {
        $intent = $this->serviceRouteIntent();

        $route = ProxyRoute::query()->updateOrCreate(
            ['domain' => self::ServiceDomain],
            [
                'node_id' => $intent->node_id,
                'app_id' => $intent->app_id,
                'workspace_id' => $intent->workspace_id,
                'instance_id' => $intent->instance_id,
                'owner_type' => $intent->owner_type,
                'kind' => $intent->kind,
                'config' => $intent->config,
                'source_hash' => $intent->source_hash,
            ],
        );

        $this->dnsmasqReconciler->reconcileProxyRecords();

        return $route->refresh();
    }

    public function removeServiceRoute(): void
    {
        ProxyRoute::query()->where('domain', self::ServiceDomain)->delete();
        $this->dnsmasqReconciler->reconcileProxyRecords();
    }

    public function serviceRouteIntent(): ProxyRoute
    {
        $router = $this->routerNode();
        $config = $this->serviceRouteConfig($router, $this->webSocketBackends());

        return new ProxyRoute([
            'node_id' => $router->id,
            'domain' => self::ServiceDomain,
            'app_id' => null,
            'workspace_id' => null,
            'instance_id' => null,
            'owner_type' => 'router',
            'kind' => 'proxy',
            'config' => $config,
            'source_hash' => $this->sourceHash($router, $config),
        ]);
    }

    public function syncPublicHosts(AppWebSocketBinding $binding): void
    {
        $binding->loadMissing('instance.app');

        if (! $binding->instance instanceof Instance) {
            throw new RuntimeException('A websocket public route requires an instance owner.');
        }

        $instance = $binding->instance;
        $node = $this->placement->nodeForInstance($instance);

        if (! $node instanceof Node) {
            throw new RuntimeException('A websocket public route requires an instance with a serving node.');
        }

        $hosts = $this->publicHosts($binding);

        if (! $binding->enabled || $hosts === []) {
            $this->deletePublicRoutes($instance);

            return;
        }

        $ingress = $this->ingressResolver->forAppNode($node);
        $router = $this->ingressResolver->router();

        DB::transaction(function () use ($instance, $hosts, $ingress, $router): void {
            $this->deleteStalePublicRoutes($instance, $hosts);

            foreach ($hosts as $host) {
                $this->syncPublicHost($instance, $ingress, $router, $host);
            }
        });
    }

    /**
     * @return list<string>
     */
    private function publicHosts(AppWebSocketBinding $binding): array
    {
        $hosts = [];

        foreach ($binding->public_hosts as $host) {
            $host = trim($host);

            if ($host === '' || in_array($host, $hosts, true)) {
                continue;
            }

            $hosts[] = $host;
        }

        return $hosts;
    }

    private function deletePublicRoutes(Instance $instance): void
    {
        ProxyRoute::query()
            ->where('instance_id', $instance->id)
            ->where('owner_type', 'app-websocket')
            ->delete();
    }

    /**
     * @param  list<string>  $hosts
     */
    private function deleteStalePublicRoutes(Instance $instance, array $hosts): void
    {
        ProxyRoute::query()
            ->where('instance_id', $instance->id)
            ->where('owner_type', 'app-websocket')
            ->whereNotIn('domain', $hosts)
            ->delete();
    }

    private function syncPublicHost(Instance $instance, Node $ingress, Node $router, string $host): void
    {
        $existingRoute = ProxyRoute::query()
            ->where('domain', $host)
            ->first();

        if (
            $existingRoute instanceof ProxyRoute
            && ($existingRoute->owner_type !== 'app-websocket'
            || $existingRoute->instance_id !== $instance->id)
        ) {
            throw new RuntimeException("WebSocket public host '{$host}' conflicts with an existing proxy route.");
        }

        $intent = $this->publicRouteIntent($instance, $ingress, $router, $host);

        ProxyRoute::query()->updateOrCreate(
            ['domain' => $host],
            [
                'node_id' => $intent->node_id,
                'app_id' => $intent->app_id,
                'workspace_id' => $intent->workspace_id,
                'instance_id' => $intent->instance_id,
                'owner_type' => $intent->owner_type,
                'kind' => $intent->kind,
                'config' => $intent->config,
                'source_hash' => $intent->source_hash,
            ],
        );
    }

    /**
     * @return list<ProxyRoute>
     */
    public function publicRouteIntents(AppWebSocketBinding $binding): array
    {
        $binding->loadMissing('instance.app');

        if (! $binding->instance instanceof Instance) {
            throw new RuntimeException('A websocket public route requires an instance owner.');
        }

        if (! $binding->enabled) {
            return [];
        }

        $hosts = $this->publicHosts($binding);

        if ($hosts === []) {
            return [];
        }

        $instance = $binding->instance;
        $node = $this->placement->nodeForInstance($instance);

        if (! $node instanceof Node) {
            throw new RuntimeException('A websocket public route requires an instance with a serving node.');
        }

        $ingress = $this->ingressResolver->forAppNode($node);
        $router = $this->ingressResolver->router();

        return array_map(
            fn (string $host): ProxyRoute => $this->publicRouteIntent($instance, $ingress, $router, $host),
            $hosts,
        );
    }

    private function publicRouteIntent(Instance $instance, Node $ingress, Node $router, string $host): ProxyRoute
    {
        $config = $this->publicRouteConfig($instance, $ingress, $router, $host);

        return new ProxyRoute([
            'node_id' => $ingress->id,
            'domain' => $host,
            'app_id' => $instance->app_id,
            'workspace_id' => null,
            'instance_id' => $instance->id,
            'owner_type' => 'app-websocket',
            'kind' => 'proxy',
            'config' => $config,
            'source_hash' => $this->publicSourceHash($instance, $ingress, $host, $config),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function publicRouteConfig(Instance $instance, Node $ingress, Node $router, string $host): array
    {
        $certificatePaths = $this->certificatePaths($host);
        $webSocketUpstreams = array_map($this->upstream(...), $this->webSocketBackends());
        $config = [
            'placement' => 'ingress',
            'ingress_node_id' => $ingress->id,
            'protocol' => 'websocket',
            'target' => [
                'type' => 'websocket',
                'value' => self::PublicServiceTarget,
            ],
            'upstream' => self::PublicServiceTarget,
            'router_upstream' => [
                'node_id' => $router->id,
                'node' => $router->name,
                'url' => $this->ingressResolver->routerUrl($router),
            ],
            'router_backend_pool' => [
                ...$this->backendPool($webSocketUpstreams),
            ],
            'router_backend_tls' => $this->trustedBackendTls(),
            'tls' => [
                'cert_path' => $certificatePaths['cert'],
                'key_path' => $certificatePaths['key'],
            ],
        ];

        $routerContent = $this->proxyRouteRenderer->renderRouterRoute(new ProxyRoute([
            'node_id' => $router->id,
            'domain' => $host,
            'app_id' => $instance->app_id,
            'instance_id' => $instance->id,
            'owner_type' => 'app-websocket',
            'kind' => 'proxy',
            'config' => $config,
        ]));

        $config['router_artifact'] = [
            'node_id' => $router->id,
            'node' => $router->name,
            'source_hash' => hash('sha256', $routerContent),
        ];

        return $config;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function publicSourceHash(Instance $instance, Node $ingress, string $host, array $config): string
    {
        return $this->proxyRouteRenderer->sourceHash(new ProxyRoute([
            'node_id' => $ingress->id,
            'domain' => $host,
            'app_id' => $instance->app_id,
            'instance_id' => $instance->id,
            'owner_type' => 'app-websocket',
            'kind' => 'proxy',
            'config' => $config,
        ]));
    }

    private function routerNode(): Node
    {
        $router = $this->nodeRoleAssignments
            ->activeRouterNodeQuery()
            ->orderBy('id')
            ->first();

        if (! $router instanceof Node) {
            throw new RuntimeException('The websocket service route requires an active router node.');
        }

        if ($this->wireGuardAddress($router) === '') {
            throw new RuntimeException(
                'The websocket service route requires the router node to have a WireGuard address.',
            );
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
            ->where('status', NodeStatus::Active->value)
            ->whereIn('id', $this->nodeRoleAssignments->activeNodeIdsForRole(NodeRoleName::WebSocket->value))
            ->orderBy('name')
            ->get()
            ->all();

        if ($nodes === []) {
            throw new RuntimeException('The websocket service route requires at least one active websocket backend.');
        }

        if (count($nodes) > 1) {
            throw new RuntimeException('The websocket service route supports one active websocket backend.');
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
        $certificatePaths = $this->certificatePaths(self::ServiceDomain);

        return [
            'protocol' => 'websocket',
            'router_upstream' => [
                'node_id' => $router->id,
                'node' => $router->name,
                'url' => $this->routerUrl($router),
            ],
            'router_backend_pool' => $this->backendPool($upstreams),
            'router_backend_tls' => $this->trustedBackendTls(),
            'upstreams' => $upstreams,
            'tls' => [
                'managed_by' => 'internal',
                'trusted_by_gateway_ca' => true,
                'cert_path' => $certificatePaths['cert'],
                'key_path' => $certificatePaths['key'],
            ],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $upstreams
     * @return list<array{node_id: int, node: string, url: string}>
     */
    private function backendPool(array $upstreams): array
    {
        return array_map(
            fn (array $upstream): array => [
                'node_id' => $upstream['node_id'],
                'node' => $upstream['node'],
                'url' => $upstream['url'],
            ],
            $upstreams,
        );
    }

    /**
     * @return array{trusted_by_gateway_ca: true, ca_path: string}
     */
    private function trustedBackendTls(): array
    {
        return [
            'trusted_by_gateway_ca' => true,
            'ca_path' => '/etc/orbit/ca/root.crt',
        ];
    }

    /**
     * @return array{cert: string, key: string}
     */
    private function certificatePaths(string $host): array
    {
        return [
            'cert' => "/etc/orbit/certs/{$host}.crt",
            'key' => "/etc/orbit/certs/{$host}.key",
        ];
    }

    /**
     * @return array{
     *     node_id: int,
     *     node: string,
     *     scheme: string,
     *     host: string,
     *     backend_name: string,
     *     port: int,
     *     url: string,
     * }
     */
    private function upstream(Node $node): array
    {
        $backendName = $this->backendName->forNode($node);
        $host = $this->requiredWireGuardAddress($node);

        return [
            'node_id' => $node->id,
            'node' => $node->name,
            'scheme' => 'https',
            'host' => $host,
            'backend_name' => $backendName,
            'port' => self::BackendPort,
            'url' => "https://{$host}:".self::BackendPort,
        ];
    }

    private function requiredWireGuardAddress(Node $node): string
    {
        $wireGuardAddress = $this->wireGuardAddress($node);

        if ($wireGuardAddress === '') {
            throw new RuntimeException('The websocket backend requires a WireGuard address.');
        }

        return $wireGuardAddress;
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
            'owner_type' => 'router',
            'kind' => 'proxy',
            'config' => $config,
        ]));
    }
}
