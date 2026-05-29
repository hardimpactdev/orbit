<?php

declare(strict_types=1);

namespace App\Services\S3;

use App\Models\Node;
use App\Models\NodeTool;
use App\Models\ProxyRoute;
use App\Services\Nodes\Access\NodeAccessAuthorizer;
use App\Services\Nodes\Roles\NodeRoleAssignments;

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
     * @return array{
     *     success: array{
     *         data: array{s3: array{node: string, private_endpoint: string, public_endpoints: list<string>, backend_pool: list<string>, credentials_ref: array{tool: string, node: string}}},
     *         meta: array{host: string, action: string, already_published: bool}
     *     }
     * }|array{
     *     error: array{code: string, message: string, meta: array<string, mixed>}
     * }
     */
    public function publish(Node $caller, string $nodeName, string $host): array
    {
        // Resolve the selected active s3 node.
        $s3Node = $this->resolveS3Node($nodeName);

        if ($s3Node === null) {
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
                return $this->error(
                    'authorization_failed',
                    'This node is not authorized to reconfigure the selected s3 node.',
                    [],
                    403,
                );
            }
        }

        // Validate that an active router exists.
        $router = $this->nodeRoleAssignments->activeRouterNodeQuery()->orderBy('id')->first();

        if (! $router instanceof Node) {
            return $this->error(
                'validation_failed',
                'An active router role is required for S3 routing.',
                ['field' => 'router', 'required_role' => 'router'],
                422,
            );
        }

        // Validate that an active ingress exists.
        $ingressIds = $this->nodeRoleAssignments->activeIngressNodeIds();

        if ($ingressIds === []) {
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
            $isS3Tool = $existing->owner_type === 'tool'
                && isset($existing->config['owner_name'])
                && $existing->config['owner_name'] === 'rustfs'
                && isset($existing->config['protocol'])
                && $existing->config['protocol'] === 's3';

            if (! $isS3Tool) {
                return $this->error(
                    'proxy.domain_conflict',
                    "The host '{$host}' is owned by a non-S3 proxy route.",
                    ['field' => 'host', 'owner_type' => $existing->owner_type],
                    409,
                );
            }
        }

        // Ensure the selected s3 node has a rustfs tool row.
        $rustfs = NodeTool::query()
            ->where('node_id', $s3Node->id)
            ->where('name', 'rustfs')
            ->first();

        if (! $rustfs instanceof NodeTool) {
            return $this->error(
                'validation_failed',
                'The selected s3 node does not have a rustfs tool row with service-level credentials.',
                ['field' => 'node'],
                422,
            );
        }

        // Determine whether the host was already published.
        $config = is_array($rustfs->config) ? $rustfs->config : [];
        $publicHosts = is_array($config['public_hosts'] ?? null) ? $config['public_hosts'] : [];

        /** @var list<string> $publicHosts */
        $publicHosts = array_values(array_filter($publicHosts, is_string(...)));
        $alreadyPublished = in_array($host, $publicHosts, true);
        $action = $alreadyPublished ? 'published' : 'published';

        if (! $alreadyPublished) {
            // Record the host on the rustfs tool row.
            $publicHosts[] = $host;
            $rustfs->config = array_merge($config, ['public_hosts' => $publicHosts]);
            $rustfs->save();
            $action = 'published';
        }

        // Converge route intent via S3RouteRegistrar.
        try {
            $this->routeRegistrar->syncServiceRoute();
            $rustfs->refresh();
            $this->routeRegistrar->syncPublicHosts($rustfs);
        } catch (\RuntimeException $e) {
            return $this->error(
                's3.publish_failed',
                $e->getMessage(),
                [],
                500,
            );
        }

        // Build the backend pool from the router-owned s3.orbit route.
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

        // Collect all public endpoints for this node's rustfs tool.
        $rustfs->refresh();
        $refreshedConfig = is_array($rustfs->config) ? $rustfs->config : [];
        $allPublicHosts = is_array($refreshedConfig['public_hosts'] ?? null) ? $refreshedConfig['public_hosts'] : [];

        /** @var list<string> $allPublicHosts */
        $allPublicHosts = array_values(array_filter($allPublicHosts, is_string(...)));
        $publicEndpoints = array_map(fn (string $h): string => "https://{$h}", $allPublicHosts);

        return [
            'success' => [
                'data' => [
                    's3' => [
                        'node' => $s3Node->name,
                        'private_endpoint' => S3RouteRegistrar::ServiceEndpoint,
                        'public_endpoints' => $publicEndpoints,
                        'backend_pool' => $backendPool,
                        'credentials_ref' => [
                            'tool' => 'rustfs',
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
            ->where('status', 'active')
            ->whereIn('id', $s3NodeIds)
            ->first();
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
