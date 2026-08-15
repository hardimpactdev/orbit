<?php

declare(strict_types=1);

namespace App\Services\Analytics;

use App\Data\Doctor\DriftEntry;
use App\Enums\DriftKind;
use App\Enums\Nodes\NodeRoleName;
use App\Models\AppAnalyticsBinding;
use App\Models\Instance;
use App\Models\Node;
use App\Models\ProxyRoute;
use App\Services\Dns\DnsmasqReconciler;
use App\Services\Nodes\Roles\NodeRoleAssignments;
use App\Services\Proxy\IngressResolver;
use App\Services\Proxy\ProxyRouteFixer;
use App\Services\Proxy\ProxyRouteRenderer;
use App\Services\Workspaces\WorkspacePlacement;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * @mago-expect lint:kan-defect
 * @mago-expect lint:excessive-parameter-list
 */
class AnalyticsRouteRegistrar
{
    public const string ServiceDomain = 'analytics.orbit';

    public const string PublicServiceTarget = 'https://analytics.orbit';

    public const array TrackingPaths = ['/js/*', '/api/event'];

    private const int BackendPort = 8000;

    public function __construct(
        private readonly NodeRoleAssignments $nodeRoleAssignments,
        private readonly IngressResolver $ingressResolver,
        private readonly ProxyRouteRenderer $proxyRouteRenderer,
        private readonly ProxyRouteFixer $proxyRouteFixer,
        private readonly DnsmasqReconciler $dnsmasqReconciler,
        private readonly WorkspacePlacement $placement,
    ) {}

    public function syncServiceRoute(?Node $backend = null): ProxyRoute
    {
        $intent = $this->serviceRouteIntent($backend);

        $route = ProxyRoute::query()->updateOrCreate(
            ['domain' => self::ServiceDomain],
            [
                'node_id' => $intent->node_id,
                'app_id' => $intent->app_id,
                'workspace_id' => $intent->workspace_id,
                'owner_type' => $intent->owner_type,
                'kind' => $intent->kind,
                'config' => $intent->config,
                'source_hash' => $intent->source_hash,
            ],
        );

        $this->dnsmasqReconciler->reconcileProxyRecords();

        return $route->refresh();
    }

    public function convergeServiceRoute(?Node $backend = null): ProxyRoute
    {
        $route = $this->syncServiceRoute($backend);
        $result = $this->proxyRouteFixer->fix($route, new DriftEntry(
            family: 'proxy',
            key: 'proxy.route_missing',
            kind: DriftKind::Missing,
            summary: 'The private analytics route must be enacted.',
            detail: ['domain' => self::ServiceDomain],
        ));

        if (($result['status'] ?? null) !== 'completed') {
            throw new RuntimeException('The private analytics route could not be enacted.');
        }

        return $route->refresh();
    }

    public function requireServiceRoute(): ProxyRoute
    {
        $route = ProxyRoute::query()
            ->where('domain', self::ServiceDomain)
            ->where('owner_type', 'router')
            ->first();

        if (! $route instanceof ProxyRoute) {
            throw new RuntimeException('The analytics role must be deployed before enabling app analytics.');
        }

        return $route;
    }

    public function removeServiceRoute(): void
    {
        $route = ProxyRoute::query()
            ->with('node')
            ->where('domain', self::ServiceDomain)
            ->first();

        if (! $route instanceof ProxyRoute) {
            return;
        }

        if (! $route->node instanceof Node) {
            throw new RuntimeException('The private analytics route has no serving router node.');
        }

        $this->proxyRouteFixer->removeExtra($route->node, self::ServiceDomain);
        $route->delete();
        $this->dnsmasqReconciler->reconcileProxyRecords();
    }

    public function serviceRouteIntent(?Node $backend = null): ProxyRoute
    {
        $router = $this->routerNode();
        $config = $this->serviceRouteConfig($router, $this->analyticsBackends($backend));

        return new ProxyRoute([
            'node_id' => $router->id,
            'domain' => self::ServiceDomain,
            'app_id' => null,
            'workspace_id' => null,
            'owner_type' => 'router',
            'kind' => 'proxy',
            'config' => $config,
            'source_hash' => $this->sourceHash($router, $config),
        ]);
    }

    public function syncPublicHosts(AppAnalyticsBinding $binding): void
    {
        $binding->loadMissing('instance.app');

        if (! $binding->instance instanceof Instance) {
            throw new RuntimeException('An analytics public route requires an instance owner.');
        }

        $instance = $binding->instance;
        $node = $this->placement->nodeForInstance($instance);

        if (! $node instanceof Node) {
            throw new RuntimeException('An analytics public route requires an instance with a serving node.');
        }

        $hosts = $this->publicHosts($binding);

        if (! $binding->enabled || $hosts === []) {
            return;
        }

        $ingress = $this->ingressResolver->forAppNode($node);
        $router = $this->ingressResolver->router();

        DB::transaction(function () use ($instance, $hosts, $ingress, $router): void {
            foreach ($hosts as $host) {
                $this->syncPublicHost($instance, $ingress, $router, $host);
            }
        });
    }

    /**
     * @param  list<string>  $hosts
     */
    public function assertPublicHostsAvailable(Instance $instance, array $hosts): void
    {
        foreach ($hosts as $host) {
            $existingRoute = ProxyRoute::query()
                ->where('domain', $host)
                ->first();

            if (
                $existingRoute instanceof ProxyRoute
                && ($existingRoute->owner_type !== 'app-analytics'
                || $existingRoute->app_id !== $instance->app_id
                || data_get($existingRoute->config, 'instance_id') !== $instance->id)
            ) {
                throw new RuntimeException("Analytics public host '{$host}' conflicts with an existing proxy route.");
            }
        }
    }

    /**
     * @param  list<string>  $desiredHosts
     */
    public function removeObsoletePublicHosts(Instance $instance, array $desiredHosts): void
    {
        $routes = ProxyRoute::all()
            ->filter(
                fn (ProxyRoute $route): bool => (
                    $route->app_id === $instance->app_id
                    && $route->owner_type === 'app-analytics'
                    && data_get($route->config, 'instance_id') === $instance->id
                    && ($desiredHosts === [] || ! in_array($route->domain, $desiredHosts, strict: true))
                ),
            )
            ->sortBy('domain');

        foreach ($routes as $route) {
            /** @var ProxyRoute $route */
            $route->loadMissing('node');

            if (! $route->node instanceof Node) {
                throw new RuntimeException("Analytics public route '{$route->domain}' has no serving ingress node.");
            }

            $ingress = $route->node;
            $domain = $route->domain;
            $router = $this->routerNodeForPublicRoute($route);
            $this->proxyRouteFixer->removeExtra($ingress, $domain);
            $this->proxyRouteFixer->removeExtra($router, $domain);
            $route->delete();
        }
    }

    public function convergePublicHosts(AppAnalyticsBinding $binding): void
    {
        foreach ($this->publicRouteIntents($binding) as $intent) {
            $route = ProxyRoute::query()->where('domain', $intent->domain)->first();

            if (! $route instanceof ProxyRoute) {
                throw new RuntimeException(
                    "Analytics public route '{$intent->domain}' is missing from gateway intent.",
                );
            }

            $routerResult = $this->proxyRouteFixer->fix($route, new DriftEntry(
                family: 'proxy',
                key: 'proxy.router_route_missing',
                kind: DriftKind::Missing,
                summary: "The private analytics router route for {$route->domain} must be enacted.",
                detail: ['domain' => $route->domain],
            ));
            $this->requireCompleted($routerResult, $route, 'router');

            $ingressResult = $this->proxyRouteFixer->fix($route->refresh(), new DriftEntry(
                family: 'proxy',
                key: 'proxy.public_route_missing',
                kind: DriftKind::Missing,
                summary: "The public analytics ingress route for {$route->domain} must be enacted.",
                detail: ['domain' => $route->domain],
            ));
            $this->requireCompleted($ingressResult, $route, 'ingress');
        }
    }

    /**
     * @return list<ProxyRoute>
     */
    public function publicRouteIntents(AppAnalyticsBinding $binding): array
    {
        $binding->loadMissing('instance.app');

        if (! $binding->instance instanceof Instance || ! $binding->enabled) {
            return [];
        }

        $hosts = $this->publicHosts($binding);

        if ($hosts === []) {
            return [];
        }

        $instance = $binding->instance;
        $node = $this->placement->nodeForInstance($instance);

        if (! $node instanceof Node) {
            return [];
        }

        $ingress = $this->ingressResolver->forAppNode($node);
        $router = $this->ingressResolver->router();

        return array_map(
            fn (string $host): ProxyRoute => $this->publicRouteIntent($instance, $ingress, $router, $host),
            $hosts,
        );
    }

    /**
     * @return list<string>
     */
    private function publicHosts(AppAnalyticsBinding $binding): array
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

    private function syncPublicHost(Instance $instance, Node $ingress, Node $router, string $host): void
    {
        $existingRoute = ProxyRoute::query()
            ->where('domain', $host)
            ->first();

        if (
            $existingRoute instanceof ProxyRoute
            && ($existingRoute->owner_type !== 'app-analytics'
            || $existingRoute->app_id !== $instance->app_id
            || data_get($existingRoute->config, 'instance_id') !== $instance->id)
        ) {
            throw new RuntimeException("Analytics public host '{$host}' conflicts with an existing proxy route.");
        }

        $intent = $this->publicRouteIntent($instance, $ingress, $router, $host);

        ProxyRoute::query()->updateOrCreate(
            ['domain' => $host],
            [
                'node_id' => $intent->node_id,
                'app_id' => $intent->app_id,
                'workspace_id' => $intent->workspace_id,
                'owner_type' => $intent->owner_type,
                'kind' => $intent->kind,
                'config' => $intent->config,
                'source_hash' => $intent->source_hash,
            ],
        );
    }

    private function routerNodeForPublicRoute(ProxyRoute $route): Node
    {
        $config = is_array($route->config) ? $route->config : [];
        $artifact = is_array($config['router_artifact'] ?? null) ? $config['router_artifact'] : [];
        $nodeId = $artifact['node_id'] ?? null;
        $router = is_int($nodeId) ? Node::query()->find($nodeId) : null;

        if (! $router instanceof Node) {
            throw new RuntimeException("Analytics public route '{$route->domain}' has no serving router node.");
        }

        return $router;
    }

    /**
     * @param  array<string, mixed>|null  $result
     */
    private function requireCompleted(?array $result, ProxyRoute $route, string $placement): void
    {
        if (($result['status'] ?? null) !== 'completed') {
            throw new RuntimeException(
                "Analytics public route '{$route->domain}' could not be enacted on the {$placement} node.",
            );
        }
    }

    private function publicRouteIntent(Instance $instance, Node $ingress, Node $router, string $host): ProxyRoute
    {
        $config = $this->publicRouteConfig($instance, $ingress, $router, $host);

        return new ProxyRoute([
            'node_id' => $ingress->id,
            'domain' => $host,
            'app_id' => $instance->app_id,
            'workspace_id' => null,
            'owner_type' => 'app-analytics',
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
        $analyticsUpstreams = array_map($this->upstream(...), $this->analyticsBackends());
        $config = [
            'instance_id' => $instance->id,
            'placement' => 'ingress',
            'ingress_node_id' => $ingress->id,
            'protocol' => 'analytics',
            'target' => [
                'type' => 'analytics',
                'value' => self::PublicServiceTarget,
            ],
            'upstream' => self::PublicServiceTarget,
            'tracking_paths' => self::TrackingPaths,
            'router_upstream' => [
                'node_id' => $router->id,
                'node' => $router->name,
                'url' => $this->ingressResolver->routerUrl($router),
            ],
            'router_backend_pool' => $this->backendPool($analyticsUpstreams),
            'tls' => $this->certificatePaths($host),
        ];

        $routerContent = $this->proxyRouteRenderer->renderRouterRoute(new ProxyRoute([
            'node_id' => $router->id,
            'domain' => $host,
            'app_id' => $instance->app_id,
            'owner_type' => 'app-analytics',
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

    private function routerNode(): Node
    {
        $router = $this->nodeRoleAssignments
            ->activeRouterNodeQuery()
            ->orderBy('id')
            ->first();

        if (! $router instanceof Node) {
            throw new RuntimeException('The analytics service route requires an active router node.');
        }

        if ($this->wireGuardAddress($router) === '') {
            throw new RuntimeException(
                'The analytics service route requires the router node to have a WireGuard address.',
            );
        }

        return $router;
    }

    /**
     * @return list<Node>
     */
    private function analyticsBackends(?Node $backend = null): array
    {
        if ($backend instanceof Node) {
            return [$backend];
        }

        /** @var list<Node> $nodes */
        $nodes = Node::query()
            ->whereIn('id', $this->nodeRoleAssignments->activeNodeIdsForRole(NodeRoleName::Analytics->value))
            ->orderBy('name')
            ->get()
            ->all();

        if ($nodes === []) {
            throw new RuntimeException('The analytics service route requires at least one active analytics backend.');
        }

        if (count($nodes) > 1) {
            throw new RuntimeException('The analytics service route supports one active analytics backend.');
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
            'protocol' => 'analytics',
            'router_upstream' => [
                'node_id' => $router->id,
                'node' => $router->name,
                'url' => $this->routerUrl($router),
            ],
            'router_backend_pool' => $this->backendPool($upstreams),
            'upstreams' => $upstreams,
            'tls' => [
                'managed_by' => 'orbit',
                'trusted_by_gateway_ca' => true,
                'cert_path' => $certificatePaths['cert_path'],
                'key_path' => $certificatePaths['key_path'],
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
     * @return array{node_id: int, node: string, scheme: string, host: string, port: int, url: string}
     */
    private function upstream(Node $node): array
    {
        $host = $this->requiredWireGuardAddress($node);

        return [
            'node_id' => $node->id,
            'node' => $node->name,
            'scheme' => 'http',
            'host' => $host,
            'port' => self::BackendPort,
            'url' => "http://{$host}:".self::BackendPort,
        ];
    }

    private function requiredWireGuardAddress(Node $node): string
    {
        $wireGuardAddress = $this->wireGuardAddress($node);

        if ($wireGuardAddress === '') {
            throw new RuntimeException('The analytics backend requires a WireGuard address.');
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
     * @return array{cert_path: string, key_path: string}
     */
    private function certificatePaths(string $host): array
    {
        return [
            'cert_path' => "/etc/orbit/certs/{$host}.crt",
            'key_path' => "/etc/orbit/certs/{$host}.key",
        ];
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

    /**
     * @param  array<string, mixed>  $config
     */
    private function publicSourceHash(Instance $instance, Node $ingress, string $host, array $config): string
    {
        return $this->proxyRouteRenderer->sourceHash(new ProxyRoute([
            'node_id' => $ingress->id,
            'domain' => $host,
            'app_id' => $instance->app_id,
            'owner_type' => 'app-analytics',
            'kind' => 'proxy',
            'config' => $config,
        ]));
    }
}
