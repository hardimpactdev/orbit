<?php

declare(strict_types=1);

namespace App\Services\S3;

use App\Enums\Nodes\NodeRoleName;
use App\Models\Node;
use App\Models\NodeTool;
use App\Models\ProxyRoute;
use App\Services\Nodes\Roles\NodeRoleAssignments;
use App\Services\Proxy\ProxyRouteRenderer;
use Illuminate\Database\Eloquent\Collection;
use RuntimeException;

final readonly class S3RouteRegistrar
{
    public const string ServiceDomain = 's3.orbit';

    public const string ServiceEndpoint = 'https://s3.orbit';

    public function __construct(
        private NodeRoleAssignments $nodeRoleAssignments,
        private ProxyRouteRenderer $proxyRouteRenderer,
    ) {}

    /**
     * Register or update the router-owned private s3.orbit service route.
     *
     * Resolves the active router node and all active rustfs tool rows to build
     * the S3 backend pool. The pool stores concrete RustFS backend URLs in the
     * form http://<node>.s3.orbit:9000 (WireGuard:9000).
     */
    public function syncServiceRoute(): ProxyRoute
    {
        $router = $this->routerNode();
        $rustfsTools = $this->activeRustfsTools();
        $config = $this->serviceRouteConfig($rustfsTools);

        $sourceHash = $this->proxyRouteRenderer->sourceHash(new ProxyRoute([
            'node_id' => $router->id,
            'domain' => self::ServiceDomain,
            'owner_type' => 'tool',
            'kind' => 'proxy',
            'config' => $config,
        ]));

        $route = ProxyRoute::query()->updateOrCreate(
            ['domain' => self::ServiceDomain],
            [
                'node_id' => $router->id,
                'app_id' => null,
                'workspace_id' => null,
                'owner_type' => 'tool',
                'kind' => 'proxy',
                'config' => $config,
                'source_hash' => $sourceHash,
            ],
        );

        return $route->refresh();
    }

    /**
     * Register or update ingress routes for all public hosts listed on the
     * given rustfs tool row. Each public host gets a separate ingress route
     * that forwards to the stable https://s3.orbit service endpoint.
     */
    public function syncPublicHosts(NodeTool $rustfs): void
    {
        $publicHosts = $this->readPublicHosts($rustfs);

        if ($publicHosts === []) {
            return;
        }

        $ingress = $this->ingressNode();

        foreach ($publicHosts as $host) {
            $this->syncPublicHost($ingress, $rustfs, $host);
        }
    }

    /**
     * Remove the ingress route for a single public host when it is owned by
     * the rustfs tool.
     */
    public function removePublicHost(NodeTool $rustfs, string $host): void
    {
        ProxyRoute::query()
            ->where('domain', $host)
            ->where('owner_type', 'tool')
            ->whereJsonContains('config->owner_name', 'rustfs')
            ->delete();
    }

    private function routerNode(): Node
    {
        $router = $this->nodeRoleAssignments->activeRouterNodeQuery()
            ->orderBy('id')
            ->first();

        if (! $router instanceof Node) {
            throw new RuntimeException('The S3 service route requires an active router node.');
        }

        return $router;
    }

    private function ingressNode(): Node
    {
        $ingress = $this->nodeRoleAssignments->activeIngressNodeQuery()
            ->orderBy('id')
            ->first();

        if (! $ingress instanceof Node) {
            throw new RuntimeException('The S3 public host route requires an active ingress node.');
        }

        return $ingress;
    }

    /**
     * @return Collection<int, NodeTool>
     */
    private function activeRustfsTools(): Collection
    {
        $s3NodeIds = $this->nodeRoleAssignments->activeNodeIdsForRole(NodeRoleName::S3->value);

        if ($s3NodeIds === []) {
            throw new RuntimeException('The S3 service route requires at least one active s3 backend.');
        }

        /** @var Collection<int, NodeTool> */
        $tools = NodeTool::query()
            ->where('name', 'rustfs')
            ->whereIn('node_id', $s3NodeIds)
            ->orderBy('id')
            ->get();

        if ($tools->isEmpty()) {
            throw new RuntimeException('The S3 service route requires at least one active rustfs tool row.');
        }

        return $tools;
    }

    /**
     * Build the service route config from the active rustfs tool rows.
     *
     * The pool stores concrete RustFS backend URLs using the backend_host from
     * the tool config (e.g. storage-1.s3.orbit) on WireGuard port 9000.
     *
     * V1 supports one backend; the pool shape is stored so multi-backend
     * support can be added without a schema change.
     *
     * @param  Collection<int, NodeTool>  $rustfsTools
     * @return array<string, mixed>
     */
    private function serviceRouteConfig(Collection $rustfsTools): array
    {
        $upstreams = $rustfsTools
            ->map(function (NodeTool $tool): array {
                $backendHost = $this->backendHost($tool);

                return [
                    'scheme' => S3BackendName::BackendScheme,
                    'host' => $backendHost,
                    'port' => S3BackendName::BackendPort,
                ];
            })
            ->values()
            ->all();

        $firstUpstream = $upstreams[0];
        $targetUrl = "{$firstUpstream['scheme']}://{$firstUpstream['host']}:{$firstUpstream['port']}";

        return [
            'owner_name' => 'rustfs',
            'protocol' => 's3',
            'target' => [
                'type' => 'upstream',
                'value' => $targetUrl,
            ],
            'upstreams' => $upstreams,
        ];
    }

    private function backendHost(NodeTool $tool): string
    {
        $config = is_array($tool->config) ? $tool->config : [];
        $backendHost = $config['backend_host'] ?? null;

        if (! is_string($backendHost) || $backendHost === '') {
            throw new RuntimeException("The rustfs tool row (id={$tool->id}) is missing a backend_host in config.");
        }

        return $backendHost;
    }

    private function syncPublicHost(Node $ingress, NodeTool $rustfs, string $host): void
    {
        $config = $this->publicRouteConfig();

        $sourceHash = $this->proxyRouteRenderer->sourceHash(new ProxyRoute([
            'node_id' => $ingress->id,
            'domain' => $host,
            'owner_type' => 'tool',
            'kind' => 'proxy',
            'config' => $config,
        ]));

        ProxyRoute::query()->updateOrCreate(
            ['domain' => $host],
            [
                'node_id' => $ingress->id,
                'app_id' => null,
                'workspace_id' => null,
                'owner_type' => 'tool',
                'kind' => 'proxy',
                'config' => $config,
                'source_hash' => $sourceHash,
            ],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function publicRouteConfig(): array
    {
        return [
            'owner_name' => 'rustfs',
            'protocol' => 's3',
            'target' => [
                'type' => 'upstream',
                'value' => self::ServiceEndpoint,
            ],
        ];
    }

    /**
     * @return list<string>
     */
    private function readPublicHosts(NodeTool $rustfs): array
    {
        $config = is_array($rustfs->config) ? $rustfs->config : [];
        $hosts = $config['public_hosts'] ?? [];

        if (! is_array($hosts)) {
            return [];
        }

        /** @var list<string> */
        return array_values(array_filter($hosts, is_string(...)));
    }
}
