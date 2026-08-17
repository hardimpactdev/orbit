<?php

declare(strict_types=1);

namespace App\Services\Proxy;

use App\Data\Doctor\DriftEntry;
use App\Enums\DriftKind;
use App\Enums\Nodes\NodeStatus;
use App\Models\Instance;
use App\Models\Node;
use App\Models\NodeTool;
use App\Models\ProxyRoute;
use App\Models\Workspace;
use App\Services\Nodes\Access\NodeAccessAuthorizer;
use App\Services\Nodes\Roles\NodeRoleAssignments;
use Orbit\Sdk\Laravel\GatewayApiException;
use RuntimeException;
use Throwable;

/**
 * @mago-expect lint:kan-defect
 * @mago-expect lint:too-many-methods
 */
class ProxyRouteIntent
{
    public function __construct(
        private readonly ProxyRouteQuery $query,
        private readonly ProxyRouteRenderer $renderer,
        private readonly ProxyRouteFixer $fixer,
    ) {}

    /**
     * @return array{data: array<string, mixed>, meta: array<string, mixed>}
     */
    public function add(
        string $domain,
        string $nodeName,
        ?string $upstream,
        ?string $redirect,
        ?int $code,
        bool $force,
        ?Node $caller = null,
    ): array {
        $node = $this->resolveServingNode($nodeName, $caller, 'proxy:add');
        $this->validateAddTarget($upstream, $redirect, $code);

        $existing = ProxyRoute::query()
            ->with(['node', 'instance.app', 'workspace.instance.app'])
            ->where('domain', $domain)
            ->first();

        if (
            $existing instanceof ProxyRoute
            && $existing->owner_type === 'custom'
            && ! $this->hasValidCustomOwnership($existing)
        ) {
            throw new GatewayApiException(
                "Domain '{$domain}' has invalid custom ownership.",
                'proxy.owner_invalid',
                ['domain' => $domain, 'owner_type' => 'custom'],
            );
        }

        if ($existing instanceof ProxyRoute && $existing->owner_type !== 'custom') {
            $ownerType = $this->publicOwnerType($existing);

            throw new GatewayApiException(
                "Domain '{$domain}' is owned by {$ownerType}.",
                'proxy.domain_conflict',
                [
                    'domain' => $domain,
                    'owner_type' => $ownerType,
                ],
            );
        }

        $kind = $redirect !== null ? 'redirect' : 'proxy';
        $intentConfig = $redirect !== null
            ? ['target' => ['type' => 'redirect', 'value' => $redirect], 'code' => $code ?? 302]
            : ['target' => ['type' => 'upstream', 'value' => $upstream], 'upstream' => $upstream];
        $planned = [
            [
                'layer' => 'tls',
                'node' => $node->name,
                'operation' => 'install-cert',
            ],
            [
                'layer' => 'backend',
                'node' => $node->name,
                'operation' => 'write-site',
            ],
        ];
        $config = ProxyRouteEnactment::pending($intentConfig, $planned);

        if (
            $existing instanceof ProxyRoute
            && ! $this->sameCustomIntent($existing, $node, $kind, $intentConfig)
            && ! $force
        ) {
            throw new GatewayApiException(
                'Existing custom proxy route differs from requested intent. Use --force to replace it.',
                'proxy.replacement_consent_required',
                [
                    'domain' => $domain,
                ],
            );
        }

        $action = $existing instanceof ProxyRoute
            ? ($this->sameCustomIntent($existing, $node, $kind, $intentConfig) ? 'converged' : 'updated')
            : 'created';

        $route = ProxyRoute::query()->updateOrCreate(
            ['domain' => $domain],
            [
                'node_id' => $node->id,
                'app_id' => null,
                'workspace_id' => null,
                'instance_id' => null,
                'owner_type' => 'custom',
                'kind' => $kind,
                'config' => $config,
                'source_hash' => $this->sourceHash($domain, $node->id, $kind, $config),
            ],
        );

        $warnings = $this->hostFirewallWarnings($node->name, $domain, $upstream);
        $warnings = [
            ...$warnings,
            ...$this->enactCustomRoute($route->refresh()),
        ];

        return [
            'data' => [
                'route' => $this->query->toRouteEntity($route->refresh()),
            ],
            'meta' => [
                'action' => $action,
                'warnings' => $warnings,
            ],
        ];
    }

    /**
     * @return array{data: array<string, mixed>, meta: array<string, mixed>}
     */
    public function remove(string $domain, ?Node $caller = null): array
    {
        $route = ProxyRoute::query()
            ->with(['node', 'instance.app', 'workspace.instance.app'])
            ->where('domain', $domain)
            ->first();

        if (! $route instanceof ProxyRoute) {
            throw new GatewayApiException("Proxy route '{$domain}' not found.", 'proxy.not_found', [
                'domain' => $domain,
            ]);
        }

        if ($route->owner_type === 'custom' && ! $this->hasValidCustomOwnership($route)) {
            throw new GatewayApiException(
                "Domain '{$domain}' has invalid custom ownership.",
                'proxy.owner_invalid',
                ['domain' => $domain, 'owner_type' => 'custom'],
            );
        }

        $orphanOwner = false;
        $publicOwnerType = $this->publicOwnerType($route);

        if ($route->owner_type !== 'custom') {
            if (! $this->hasMissingOwner($route)) {
                throw new GatewayApiException(
                    "Domain '{$domain}' is owned by {$publicOwnerType}.",
                    'proxy.owned_route_denied',
                    [
                        'domain' => $domain,
                        'owner_type' => $publicOwnerType,
                    ],
                );
            }

            $orphanOwner = true;
        }

        $node = $route->node;
        $this->authorizeServingNode($node, $caller, 'proxy:remove');

        try {
            $this->fixer->removeExtra($node, $domain);
        } catch (Throwable $exception) {
            throw new GatewayApiException(
                "Proxy route '{$domain}' registry is intact, but backend/TLS cleanup failed: {$exception->getMessage()}",
                'proxy.cleanup_failed',
                [
                    'domain' => $domain,
                    'node' => $node->name,
                    'backend_removed' => false,
                    'tls_removed' => false,
                    'next_command' => "doctor --family=proxy --restore --node={$node->name}",
                ],
            );
        }

        $entity = $this->query->toRouteEntity($route, 'removed');
        $route->delete();

        $meta = [
            'backend_removed' => true,
            'tls_removed' => true,
            'removal_reason' => $orphanOwner ? 'orphan_owner' : 'custom',
            'warnings' => [],
        ];

        if ($orphanOwner) {
            $meta['owner_type'] = $publicOwnerType;
        }

        return [
            'data' => [
                'route' => $entity,
            ],
            'meta' => $meta,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function enactCustomRoute(ProxyRoute $route): array
    {
        $route->loadMissing('node');
        $node = $route->node;

        if (! $node instanceof Node) {
            return [$this->enactmentFailedWarning(
                domain: $route->domain,
                node: 'unknown',
                message: "Proxy route '{$route->domain}' has no serving node for enactment.",
            )];
        }

        try {
            $fixed = $this->fixer->fix(
                $route,
                new DriftEntry(
                    family: 'proxy',
                    key: 'proxy.route_missing',
                    kind: DriftKind::Missing,
                    summary: "Custom proxy route {$route->domain} requires backend/TLS enactment.",
                ),
            );

            if ($fixed === null) {
                throw new RuntimeException('Proxy route fixer declined custom route enactment.');
            }

            $route->refresh();
            $config = is_array($route->config) ? $route->config : [];
            $route->forceFill([
                'config' => ProxyRouteEnactment::converged($config),
            ])->save();

            return [];
        } catch (Throwable) {
            $route->refresh();
            $config = is_array($route->config) ? $route->config : [];
            $route->forceFill([
                'config' => ProxyRouteEnactment::failed($config, [
                    'layer' => 'backend',
                    'node' => $node->name,
                    'operation' => 'write-site',
                ]),
            ])->save();

            return [$this->enactmentFailedWarning(
                domain: $route->domain,
                node: $node->name,
                message: "Proxy route '{$route->domain}' was recorded, but backend/TLS enactment failed. Run doctor to converge proxy artifacts.",
            )];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function enactmentFailedWarning(string $domain, string $node, string $message): array
    {
        return [
            'code' => 'proxy.enactment_failed',
            'family' => 'proxy',
            'message' => $message,
            'domain' => $domain,
            'node' => $node,
            'next_command' => "doctor --family=proxy --restore --node={$node}",
        ];
    }

    private function publicOwnerType(ProxyRoute $route): string
    {
        return $this->query->publicOwnerType($route);
    }

    /**
     * Owner missing proof mirrors proxy doctor `proxy.owner_invalid` for
     * FK-backed owners. Non-FK owners (gateway, tool, s3, router) cannot be
     * proven missing here and stay denied.
     */
    private function hasMissingOwner(ProxyRoute $route): bool
    {
        $route->loadMissing(['instance', 'workspace']);

        return match ($route->owner_type) {
            'app', 'app-analytics', 'app-websocket' => ! $route->instance instanceof Instance,
            'workspace' => ! $route->workspace instanceof Workspace,
            'tool' => $this->toolOwnerIsMissing($route),
            default => false,
        };
    }

    /**
     * Tool-owned routes store the catalog slug in config.owner_name; they are
     * orphans when no matching installed NodeTool remains on the serving node.
     */
    private function toolOwnerIsMissing(ProxyRoute $route): bool
    {
        $config = is_array($route->config) ? $route->config : [];
        $ownerName = is_string($config['owner_name'] ?? null) ? $config['owner_name'] : null;

        if (
            ! $route->node instanceof Node
            || $route->app_id !== null
            || $route->workspace_id !== null
            || $route->instance_id !== null
            || $route->kind !== 'proxy'
            || $ownerName === null
            || $ownerName === ''
        ) {
            return false;
        }

        return ! NodeTool::query()
            ->where('name', $ownerName)
            ->where('expected_state', 'installed')
            ->exists();
    }

    private function resolveServingNode(string $nodeName, ?Node $caller, string $permission): Node
    {
        $node = Node::query()
            ->where('name', $nodeName)
            ->where('status', NodeStatus::Active->value)
            ->first();

        if (! $node instanceof Node || ! $this->canServeProxyRoutes($node)) {
            throw new GatewayApiException("Unknown node: '{$nodeName}'.", 'validation_failed', [
                'field' => 'node',
                'value' => $nodeName,
            ]);
        }

        $this->authorizeServingNode($node, $caller, $permission);

        return $node;
    }

    private function canServeProxyRoutes(Node $node): bool
    {
        return app(NodeRoleAssignments::class)->nodeCanServeGatewayOrAppHostWorkloads($node);
    }

    private function authorizeServingNode(Node $node, ?Node $caller, string $permission): void
    {
        if (! $caller instanceof Node || app(NodeRoleAssignments::class)->nodeIsGateway($caller)) {
            return;
        }

        $result = app(NodeAccessAuthorizer::class)->authorize($caller, $node, $permission);

        if ($result->allowed) {
            return;
        }

        throw new GatewayApiException(
            'This node is not authorized to manage custom proxy routes for the selected serving node.',
            'authorization_failed',
            [
                'node' => $node->name,
                'reason' => $result->reason,
                'missing_permission' => $result->missingPermission,
                'serving_node' => $node->name,
            ],
        );
    }

    private function validateAddTarget(?string $upstream, ?string $redirect, ?int $code): void
    {
        if ($upstream === null && $redirect === null || $upstream !== null && $redirect !== null) {
            throw new GatewayApiException('Select exactly one of --upstream or --redirect.', 'validation_failed', [
                'fields' => ['upstream', 'redirect'],
            ]);
        }

        if ($upstream !== null && $code !== null) {
            throw new GatewayApiException('--code may only be used with --redirect.', 'validation_failed', [
                'field' => 'code',
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function sameCustomIntent(ProxyRoute $route, Node $node, string $kind, array $config): bool
    {
        $storedConfig = is_array($route->config) ? $route->config : [];
        unset($storedConfig['enactment']);

        return (
            $route->node_id === $node->id
            && $route->app_id === null
            && $route->workspace_id === null
            && $route->instance_id === null
            && $route->owner_type === 'custom'
            && $route->kind === $kind
            && $storedConfig === $config
        );
    }

    private function hasValidCustomOwnership(ProxyRoute $route): bool
    {
        return (
            $route->node instanceof Node
            && $route->app_id === null
            && $route->workspace_id === null
            && $route->instance_id === null
            && $route->owner_type === 'custom'
            && in_array($route->kind, ['proxy', 'redirect'], true)
        );
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function sourceHash(string $domain, int $nodeId, string $kind, array $config): string
    {
        return $this->renderer->sourceHash(new ProxyRoute([
            'node_id' => $nodeId,
            'domain' => $domain,
            'kind' => $kind,
            'owner_type' => 'custom',
            'config' => $config,
        ]));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function hostFirewallWarnings(string $node, string $domain, ?string $upstream): array
    {
        if ($upstream === null || ! $this->isHostLocalUpstream($upstream)) {
            return [];
        }

        $port = $this->upstreamPort($upstream);

        if ($port === null) {
            return [];
        }

        return [[
            'code' => 'firewall_rule.host_upstream_may_block',
            'family' => 'firewall_rule',
            'node' => $node,
            'message' => 'Containerized orbit-caddy reaches host-local upstreams from the Docker bridge; if UFW blocks that path, add a scoped firewall rule for the bridge/source and upstream port.',
            'upstream' => $upstream,
            'port' => $port,
            'next_command' => "firewall:allow caddy-to-host-{$port} --node={$node} --port={$port} --from=<orbit-caddy-bridge-cidr> --to=<host-address> --reason=\"orbit-caddy to {$domain}\"",
        ]];
    }

    private function isHostLocalUpstream(string $upstream): bool
    {
        $host = parse_url($upstream, PHP_URL_HOST);

        return (
            is_string($host)
            && in_array(
                strtolower($host),
                [
                    '127.0.0.1',
                    'localhost',
                    ProxyRouteRenderer::HostLoopbackHostname,
                ],
                true,
            )
        );
    }

    private function upstreamPort(string $upstream): ?string
    {
        $port = parse_url($upstream, PHP_URL_PORT);

        if (is_int($port)) {
            return (string) $port;
        }

        return match (parse_url($upstream, PHP_URL_SCHEME)) {
            'http' => '80',
            'https' => '443',
            default => null,
        };
    }
}
