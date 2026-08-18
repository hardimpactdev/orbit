<?php

declare(strict_types=1);

namespace App\Services\S3;

use App\Enums\Nodes\NodeStatus;
use App\Models\Node;
use App\Models\NodeTool;
use App\Models\ProxyRoute;
use App\Services\Nodes\Access\NodeAccessAuthorizer;
use App\Services\Nodes\Roles\NodeRoleAssignments;
use App\Support\Streaming\ProgressEventStreamEmitter;

final readonly class S3PublishAction
{
    public function __construct(
        private NodeRoleAssignments $nodeRoleAssignments,
        private NodeAccessAuthorizer $nodeAccessAuthorizer,
        private S3RouteRegistrar $routeRegistrar,
    ) {}

    /**
     * Publish a public HTTPS hostname for the fleet S3 service.
     *
     * Emits SSE progress steps when an emitter is provided.
     *
     * @return array{
     *     success: array{
     *         data: array{s3: array{node: string, private_endpoint: string, public_endpoints: list<string>, backend_pool: list<string>, credentials_ref: array{tool: string, node: string}}},
     *         meta: array{host: string, action: string, already_published: bool}
     *     }
     * }|array{
     *     error: array{code: string, message: string, meta: array<string, mixed>, status: int}
     * }
     */
    public function publishWithProgress(
        Node $caller,
        string $nodeName,
        string $host,
        ?ProgressEventStreamEmitter $emitter = null,
    ): array {
        // Step 1: Resolve S3 node.
        $emitter?->stepEvent('resolve_node', 'running', "Resolving s3 node '{$nodeName}'");
        $s3Node = $this->resolveS3Node($nodeName);

        if ($s3Node === null) {
            $emitter?->stepEvent('resolve_node', 'failed', 'No active s3 role node found.');

            return $this->error(
                'validation_failed',
                'An active s3 role node is required to publish an S3 host.',
                ['field' => 'node', 'required_role' => 's3'],
                422,
            );
        }

        // Authorize the caller for tool:reconfigure on the selected s3 node.
        if (! $this->nodeRoleAssignments->nodeIsGateway($caller)) {
            if (! $this->nodeAccessAuthorizer->allows($caller, $s3Node, 'tool:reconfigure')) {
                $emitter?->stepEvent('resolve_node', 'failed', 'Authorization denied.');

                return $this->error(
                    'authorization_failed',
                    'This node is not authorized to reconfigure the selected s3 node.',
                    [],
                    403,
                );
            }
        }

        $emitter?->stepEvent('resolve_node', 'done', "Resolved s3 node '{$s3Node->name}'");

        // Step 2: Check router and ingress.
        $emitter?->stepEvent('check_router_ingress', 'running', 'Checking router and ingress nodes');

        $router = $this->nodeRoleAssignments->activeRouterNodeQuery()->orderBy('id')->first();

        if (! $router instanceof Node) {
            $emitter?->stepEvent('check_router_ingress', 'failed', 'No active router node found.');

            return $this->error(
                'validation_failed',
                'An active router role is required for S3 routing.',
                ['field' => 'router', 'required_role' => 'router'],
                422,
            );
        }

        $ingressIds = $this->nodeRoleAssignments->activeIngressNodeIds();

        if ($ingressIds === []) {
            $emitter?->stepEvent('check_router_ingress', 'failed', 'No active ingress node found.');

            return $this->error(
                'validation_failed',
                'An active ingress role is required to publish an S3 host.',
                ['field' => 'ingress', 'required_role' => 'ingress'],
                422,
            );
        }

        // Check domain conflict: is the host owned by a non-S3 proxy route?
        $existing = ProxyRoute::query()->where('domain', $host)->first();

        if ($existing instanceof ProxyRoute) {
            if (! $this->routeRegistrar->ownsPublicRoute($existing, $host)) {
                $emitter?->stepEvent('check_router_ingress', 'failed', "Host '{$host}' is owned by a non-S3 route.");

                return $this->error(
                    'proxy.domain_conflict',
                    "The host '{$host}' is owned by a non-S3 proxy route.",
                    ['field' => 'host', 'owner_type' => $existing->owner_type],
                    409,
                );
            }
        }

        $emitter?->stepEvent('check_router_ingress', 'done', 'Router and ingress nodes verified');

        // Step 3: Ensure SeaweedFS credentials.
        $emitter?->stepEvent('ensure_credentials', 'running', 'Checking SeaweedFS credentials on node');

        $seaweedfs = NodeTool::query()
            ->where('node_id', $s3Node->id)
            ->where('name', 'seaweedfs')
            ->first();

        if (! $seaweedfs instanceof NodeTool) {
            $emitter?->stepEvent('ensure_credentials', 'failed', 'No seaweedfs tool row found on node.');

            return $this->error(
                'validation_failed',
                'The selected s3 node does not have a seaweedfs tool row with service-level credentials.',
                ['field' => 'node'],
                422,
            );
        }

        $emitter?->stepEvent('ensure_credentials', 'done', 'SeaweedFS credentials found');

        $config = is_array($seaweedfs->config) ? $seaweedfs->config : [];
        $storedPublicHosts = $this->storedPublicHosts($config);
        $publicHosts = $this->intendedPublicHosts($config, $host);
        $alreadyPublished = in_array($host, $storedPublicHosts, true);

        // Step 4: Ensure private s3.orbit route.
        $emitter?->stepEvent('ensure_private_route', 'running', 'Ensuring private s3.orbit service route');

        try {
            $this->routeRegistrar->assertPublishAvailable($publicHosts);
        } catch (\RuntimeException $e) {
            $emitter?->stepEvent('ensure_private_route', 'failed', $e->getMessage());

            return $this->error('s3.publish_failed', $e->getMessage(), [], 500);
        }

        // Determine whether the host was already published.
        $action = 'published';

        if (! $alreadyPublished) {
            $seaweedfs->config = array_merge($config, ['public_hosts' => $publicHosts]);
            $seaweedfs->save();
        }

        try {
            $this->routeRegistrar->syncServiceRoute();
        } catch (\RuntimeException $e) {
            $emitter?->stepEvent('ensure_private_route', 'failed', $e->getMessage());

            return $this->error('s3.publish_failed', $e->getMessage(), [], 500);
        }

        $emitter?->stepEvent('ensure_private_route', 'done', 'Private s3.orbit route ensured');

        // Step 5: Ensure S3 backend pool.
        $emitter?->stepEvent('ensure_backend_pool', 'running', 'Ensuring S3 backend pool');

        $serviceRoute = ProxyRoute::query()->where('domain', S3RouteRegistrar::ServiceDomain)->first();
        $backendPool = [];

        if ($serviceRoute instanceof ProxyRoute) {
            $routeConfig = is_array($serviceRoute->config) ? $serviceRoute->config : [];
            $upstreams = is_array($routeConfig['upstreams'] ?? null) ? $routeConfig['upstreams'] : [];

            foreach ($upstreams as $upstream) {
                if (is_array($upstream) && isset($upstream['scheme'], $upstream['host'], $upstream['port'])) {
                    $backendPool[] = "{$upstream['scheme']}://{$upstream['host']}:{$upstream['port']}";
                }
            }
        }

        $emitter?->stepEvent('ensure_backend_pool', 'done', 'S3 backend pool ready');

        // Step 6: Publish ingress host.
        $emitter?->stepEvent('publish_ingress', 'running', "Publishing ingress route for '{$host}'");

        $seaweedfs->refresh();

        try {
            $this->routeRegistrar->syncPublicHosts($seaweedfs);
        } catch (\RuntimeException $e) {
            $emitter?->stepEvent('publish_ingress', 'failed', $e->getMessage());

            return $this->error('s3.publish_failed', $e->getMessage(), [], 500);
        }

        $emitter?->stepEvent('publish_ingress', 'done', 'Ingress host published');

        // Step 7: Verify route intent.
        $emitter?->stepEvent('verify_intent', 'running', 'Verifying route intent');

        $seaweedfs->refresh();
        $refreshedConfig = is_array($seaweedfs->config) ? $seaweedfs->config : [];
        $allPublicHosts = is_array($refreshedConfig['public_hosts'] ?? null) ? $refreshedConfig['public_hosts'] : [];

        /** @var list<string> $allPublicHosts */
        $allPublicHosts = array_values(array_filter($allPublicHosts, is_string(...)));
        $publicEndpoints = array_map(fn (string $h): string => "https://{$h}", $allPublicHosts);

        $emitter?->stepEvent('verify_intent', 'done', 'Route intent verified');

        return [
            'success' => [
                'data' => [
                    's3' => [
                        'node' => $s3Node->name,
                        'private_endpoint' => S3RouteRegistrar::ServiceEndpoint,
                        'public_endpoints' => $publicEndpoints,
                        'backend_pool' => $backendPool,
                        'credentials_ref' => [
                            'tool' => 'seaweedfs',
                            'node' => $s3Node->name,
                        ],
                    ],
                ],
                'meta' => [
                    'host' => $host,
                    'action' => $action,
                    'already_published' => $alreadyPublished,
                ],
            ],
        ];
    }

    /**
     * Resolve a single active s3 node by name.
     */
    private function resolveS3Node(string $nodeName): ?Node
    {
        $s3NodeIds = $this->nodeRoleAssignments->activeNodeIdsForRole('s3');

        if ($s3NodeIds === []) {
            return null;
        }

        return Node::query()
            ->where('name', $nodeName)
            ->where('status', NodeStatus::Active->value)
            ->whereIn('id', $s3NodeIds)
            ->first();
    }

    /**
     * @param  array<string, mixed>  $config
     * @return list<string>
     */
    private function intendedPublicHosts(array $config, string $host): array
    {
        $hosts = $this->storedPublicHosts($config);

        if (! in_array($host, $hosts, true)) {
            $hosts[] = $host;
        }

        return $hosts;
    }

    /**
     * @param  array<string, mixed>  $config
     * @return list<string>
     */
    private function storedPublicHosts(array $config): array
    {
        $hosts = is_array($config['public_hosts'] ?? null) ? $config['public_hosts'] : [];

        return array_values(array_filter($hosts, is_string(...)));
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return array{error: array{code: string, message: string, meta: array<string, mixed>, status: int}}
     */
    private function error(string $code, string $message, array $meta, int $status): array
    {
        return [
            'error' => [
                'code' => $code,
                'message' => $message,
                'meta' => $meta,
                'status' => $status,
            ],
        ];
    }
}
