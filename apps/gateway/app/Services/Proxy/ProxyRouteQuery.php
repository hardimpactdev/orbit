<?php

declare(strict_types=1);

namespace App\Services\Proxy;

use App\Enums\Nodes\NodeStatus;
use App\Exceptions\WorkspaceUnsupportedForProduction;
use App\Models\Instance;
use App\Models\Node;
use App\Models\ProxyRoute;
use App\Models\Workspace;
use App\Services\Nodes\Access\NodeAccessAuthorizer;
use App\Services\Nodes\Roles\NodeRoleAssignments;
use App\Services\Workspaces\WorkspaceRoleGuard;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Orbit\Sdk\Laravel\GatewayApiException;

class ProxyRouteQuery
{
    public const array AllowedFilters = [
        'all',
        'instance',
        'workspace',
        'gateway',
        'analytics',
        'websocket',
        's3',
        'tool',
        'custom',
        'redirect',
    ];

    public function __construct(
        private readonly AppProxyRouteTargetResolver $appRouteTargets,
        private readonly WorkspaceRoleGuard $workspaceRoleGuard,
        private readonly WorkspaceProxyRouteOwnershipResolver $workspaceRouteOwnership,
        private readonly InstanceProxyRouteOwnershipResolver $instanceRouteOwnership,
    ) {}

    /**
     * @return array{
     *     routes: list<array<string, mixed>>,
     *     meta: array{filter: string, node: ?string, count: int},
     * }
     */
    public function list(?string $filter = null, ?string $node = null, ?Node $caller = null): array
    {
        $filter = $filter !== null && trim($filter) !== '' ? trim($filter) : 'all';
        $node = $node !== null && trim($node) !== '' ? trim($node) : null;

        $this->validateFilter($filter);

        if ($filter === 'workspace' && $caller instanceof Node) {
            $this->workspaceRoleGuard->ensureNodeMayOperateWorkspaces($caller);
        }

        $visibleNodeIds = $this->visibleNodeIds($caller);
        $callerIsGateway = $caller instanceof Node && $this->nodeRoleAssignments()->nodeIsGateway($caller);

        if ($caller instanceof Node && ! $callerIsGateway && $visibleNodeIds === []) {
            throw new GatewayApiException(
                message: 'This node is not authorized to read the proxy route registry.',
                errorCode: 'authorization_failed',
                errorMeta: [
                    'reason' => 'missing_permission',
                    'missing_permission' => 'proxy:read',
                ],
            );
        }

        $nodeId = $this->resolveNodeId($node, $caller, $visibleNodeIds);

        /** @var Builder<ProxyRoute> $query */
        $query = ProxyRoute::query()->with(['node', 'instance.app', 'workspace.instance.app']);

        if ($caller instanceof Node && ! $callerIsGateway) {
            $query->whereIn('node_id', $visibleNodeIds);
        }

        if ($nodeId !== null) {
            $query->where('node_id', $nodeId);
        }

        if ($filter !== 'all') {
            $query = $this->applyFilter($query, $filter);
        }

        /** @var \Illuminate\Database\Eloquent\Collection<int, ProxyRoute> $proxyRoutes */
        $proxyRoutes = $query
            ->get()
            ->filter($this->routeIsValidForPublicRegistry(...))
            ->values();

        if ($filter === 'workspace') {
            foreach ($proxyRoutes as $route) {
                $this->ensureWorkspaceRouteSupported($route, $caller);
            }
        }

        if ($filter !== 'workspace') {
            $proxyRoutes = $proxyRoutes
                ->reject(fn (ProxyRoute $route): bool => ! $this->workspaceRouteIsSupported($route, $caller))
                ->values();
        }

        /** @var list<ProxyRoute> $visibleRoutes */
        $visibleRoutes = $proxyRoutes->values()->all();

        usort(
            $visibleRoutes,
            fn (ProxyRoute $first, ProxyRoute $second): int => (
                [
                    mb_strtolower($first->node->name),
                    mb_strtolower($first->domain),
                ] <=> [
                    mb_strtolower($second->node->name),
                    mb_strtolower($second->domain),
                ]
            ),
        );
        $routes = array_map(
            fn (ProxyRoute $route): array => $this->toRouteEntity($route),
            $visibleRoutes,
        );

        return [
            'routes' => $routes,
            'meta' => [
                'filter' => $filter,
                'node' => $node,
                'count' => count($routes),
            ],
        ];
    }

    private function validateFilter(string $filter): void
    {
        if (in_array($filter, self::AllowedFilters, true)) {
            return;
        }

        throw new GatewayApiException(
            message: 'The selected proxy route filter is invalid.',
            errorCode: 'validation_failed',
            errorMeta: [
                'field' => 'filter',
                'allowed' => self::AllowedFilters,
            ],
        );
    }

    private function ensureWorkspaceRouteSupported(ProxyRoute $route, ?Node $caller): void
    {
        if ($route->owner_type !== 'workspace') {
            return;
        }

        $this->workspaceRoleGuard->ensureNodeIsWorkspaceEligible($route->node);

        if ($caller instanceof Node) {
            $this->workspaceRoleGuard->ensureNodeMayOperateWorkspaces($caller);
        }

        if ($route->workspace instanceof Workspace) {
            $this->workspaceRoleGuard->ensureWorkspaceSupported($route->workspace);
        }
    }

    private function workspaceRouteIsSupported(ProxyRoute $route, ?Node $caller): bool
    {
        try {
            $this->ensureWorkspaceRouteSupported($route, $caller);

            return true;
        } catch (WorkspaceUnsupportedForProduction) {
            return false;
        }
    }

    /**
     * @param  list<int>  $visibleNodeIds
     */
    private function resolveNodeId(?string $node, ?Node $caller, array $visibleNodeIds): ?int
    {
        if ($node === null) {
            return null;
        }

        $query = Node::query()->where('name', $node);

        if ($caller instanceof Node && ! $this->nodeRoleAssignments()->nodeIsGateway($caller)) {
            $query->whereIn('id', $visibleNodeIds);
        }

        $nodeId = $query->value('id');

        if (is_int($nodeId)) {
            return $nodeId;
        }

        throw new GatewayApiException(
            message: "Unknown node: '{$node}'.",
            errorCode: 'validation_failed',
            errorMeta: [
                'field' => 'node',
                'value' => $node,
            ],
        );
    }

    /**
     * @return list<int>
     */
    private function visibleNodeIds(?Node $caller): array
    {
        if (! $caller instanceof Node || $this->nodeRoleAssignments()->nodeIsGateway($caller)) {
            return Node::query()->pluck('id')->all();
        }

        $authorizer = app(NodeAccessAuthorizer::class);
        $nodes = Node::query()
            ->where('status', NodeStatus::Active->value)
            ->get();

        $visibleNodeIds = [];

        foreach ($nodes as $node) {
            if ($authorizer->allows($caller, $node, 'proxy:read')) {
                $visibleNodeIds[] = $node->id;
            }
        }

        return $visibleNodeIds;
    }

    private function nodeRoleAssignments(): NodeRoleAssignments
    {
        return app(NodeRoleAssignments::class);
    }

    private function applyFilter(Builder $query, string $filter): Builder
    {
        if ($filter === 'redirect') {
            return $query->where('kind', 'redirect');
        }

        if ($filter === 'custom') {
            return $query
                ->where('owner_type', 'custom')
                ->where('kind', 'proxy');
        }

        if ($filter === 'websocket') {
            return $query->where(function (Builder $query): void {
                $query->where('domain', 'websocket.orbit')->orWhere('owner_type', 'app-websocket');
            });
        }

        if ($filter === 'analytics') {
            return $query->where(function (Builder $query): void {
                $query->where('domain', 'analytics.orbit')->orWhere('owner_type', 'app-analytics');
            });
        }

        if ($filter === 's3') {
            return $query->where(function (Builder $q): void {
                $q->where('domain', 's3.orbit')
                    ->orWhere('owner_type', 's3');
            });
        }

        return $query->where('owner_type', $filter === 'instance' ? 'app' : $filter);
    }

    /**
     * @return array<string, mixed>
     */
    public function toRouteEntity(ProxyRoute $route, ?string $status = null): array
    {
        $route->loadMissing(['node', 'instance.app', 'workspace.instance.app']);
        $config = is_array($route->config) ? $route->config : [];
        $tlsManagedBy = $this->stringConfig($config, ['tls.managed_by', 'tls_managed_by']) ?? 'orbit';

        $entity = [
            'domain' => $route->domain,
            'kind' => $route->kind === 'app'
                ? $this->appRouteTargetType()
                : $route->kind,
            'owner' => [
                'type' => $this->registryOwnerType($route),
                'name' => $this->ownerName($route, $config),
            ],
            'node' => $route->node->name,
            'target' => [
                'type' => $this->targetType($route),
                'value' => $this->targetValue($route, $config),
            ],
            'redirect_code' => $this->redirectCode($config),
            'tls' => [
                'managed_by' => $tlsManagedBy,
                'trusted_by_gateway_ca' => $this->trustedByGatewayCa($config, $tlsManagedBy),
            ],
            'status' => $status ?? ProxyRouteEnactment::status($config),
        ];

        $workspaceInstance = $this->workspaceParentInstanceSelector($route);

        if ($workspaceInstance !== null) {
            // Canonical parent app.instance from the route's FK-owned workspace only.
            $entity['instance'] = $workspaceInstance;
        }

        if (($config['placement'] ?? null) === 'ingress') {
            $entity['placement'] = 'ingress';
            $entity['router'] = $this->router($config);
        }

        return $entity;
    }

    /**
     * Parent instance selector for workspace-owned routes, derived only from the
     * route's FK workspace relation (never a secondary name lookup).
     */
    private function workspaceParentInstanceSelector(ProxyRoute $route): ?string
    {
        if ($route->owner_type !== 'workspace') {
            return null;
        }

        $ownership = $this->workspaceRouteOwnership->resolve($route);

        if ($ownership === null) {
            return null;
        }

        $app = $ownership->app;
        $instance = $ownership->instance;

        $appName = trim((string) $app->name);
        $instanceName = trim((string) $instance->name);

        if ($appName === '' || $instanceName === '') {
            return null;
        }

        return "{$appName}.{$instanceName}";
    }

    public function publicOwnerType(ProxyRoute $route): string
    {
        return $this->registryOwnerType($route);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function ownerName(ProxyRoute $route, array $config): ?string
    {
        return match ($route->owner_type) {
            'app' => $this->appRouteOwnerName($route),
            'app-analytics', 'app-websocket' => $this->bindingRouteAppName($route),
            'workspace' => $this->workspaceRouteOwnership->resolve($route)?->workspace->name,
            'router' => $route->domain,
            'gateway', 'tool', 's3' => $this->stringConfig($config, ['owner_name', 'tool']),
            default => null,
        };
    }

    private function ownerType(ProxyRoute $route): string
    {
        return match ($route->owner_type) {
            'app' => $this->appRouteTargetType(),
            'app-analytics' => 'analytics',
            'app-websocket' => 'websocket',
            default => $route->owner_type,
        };
    }

    private function registryOwnerType(ProxyRoute $route): string
    {
        if ($route->owner_type === 'app' && ! $this->appRouteIsValid($route)) {
            return 'app';
        }

        if (
            InstanceProxyRouteOwnershipResolver::isPublicBindingOwner($route->owner_type)
            && ! $this->bindingRouteIsValid($route)
        ) {
            return $route->owner_type;
        }

        return $this->ownerType($route);
    }

    private function targetType(ProxyRoute $route): string
    {
        if ($route->kind === 'redirect') {
            return 'redirect';
        }

        return match ($route->owner_type) {
            'app' => $this->appRouteIsValid($route) ? $this->appRouteTargetType() : 'upstream',
            'app-analytics' => $this->bindingRouteIsValid($route) ? 'analytics' : 'upstream',
            'app-websocket' => $this->bindingRouteIsValid($route) ? 'websocket' : 'upstream',
            'workspace' => 'workspace',
            'gateway' => 'gateway',
            'tool', 's3', 'router' => 'upstream',
            default => 'upstream',
        };
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function targetValue(ProxyRoute $route, array $config): ?string
    {
        if ($route->kind === 'redirect') {
            return $this->stringConfig($config, ['target.value', 'redirect', 'redirect_url', 'target']);
        }

        return match ($route->owner_type) {
            'app' => $this->appRouteTargetValue($route),
            'app-analytics' => $this->stringConfig($config, ['target.value', 'target', 'upstream']),
            'app-websocket' => $this->stringConfig($config, ['target.value', 'target', 'upstream']),
            'workspace' => $this->workspaceRouteOwnership->resolve($route)?->workspace->name,
            'router' => $this->stringConfig($config, ['target.value', 'target', 'upstream']) ?? $route->domain,
            'gateway', 'tool', 's3' => $this->stringConfig($config, ['target.value', 'target', 'upstream']),
            default => $this->stringConfig($config, ['upstream', 'target.value', 'target']),
        };
    }

    private function appRouteOwnerName(ProxyRoute $route): ?string
    {
        return $this->appRouteTargetValue($route);
    }

    private function appRouteTargetType(): string
    {
        return 'instance';
    }

    private function appRouteTargetValue(ProxyRoute $route): ?string
    {
        $instance = $this->appRouteTargets->instanceForRoute($route);

        return $instance instanceof Instance ? $this->appRouteTargets->selector($instance) : null;
    }

    private function appRouteIsValid(ProxyRoute $route): bool
    {
        return $this->appRouteTargets->instanceForRoute($route) instanceof Instance;
    }

    private function bindingRouteIsValid(ProxyRoute $route): bool
    {
        return $this->instanceRouteOwnership->resolve($route) instanceof Instance;
    }

    private function bindingRouteAppName(ProxyRoute $route): ?string
    {
        $instance = $this->instanceRouteOwnership->resolve($route);

        return $instance?->app?->name;
    }

    private function routeIsValidForPublicRegistry(ProxyRoute $route): bool
    {
        if (InstanceProxyRouteOwnershipResolver::isDirectOwner($route->owner_type)) {
            return $this->instanceRouteOwnership->resolve($route) instanceof Instance;
        }

        if ($route->owner_type === 'workspace') {
            return $this->workspaceRouteOwnership->resolve($route) !== null;
        }

        return $route->owner_type !== 'instance';
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function redirectCode(array $config): ?int
    {
        $code = $this->nestedConfig($config, 'redirect_code') ?? $this->nestedConfig($config, 'code');

        return is_int($code) ? $code : null;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function trustedByGatewayCa(array $config, string $managedBy): bool
    {
        $value = $this->nestedConfig($config, 'tls.trusted_by_gateway_ca') ?? $this->nestedConfig(
            $config,
            'trusted_by_gateway_ca',
        );

        return is_bool($value) ? $value : $managedBy === 'orbit';
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array{node: ?string, url: ?string, backend_pool: list<array{node: string, url: string}>}
     */
    private function router(array $config): array
    {
        $upstream = $config['router_upstream'] ?? [];

        return [
            'node' => is_array($upstream) ? $this->stringConfig($upstream, ['node']) : null,
            'url' => is_array($upstream) ? $this->stringConfig($upstream, ['url']) : null,
            'backend_pool' => $this->routerBackendPool($config),
        ];
    }

    /**
     * @param  array<string, mixed>  $config
     * @return list<array{node: string, url: string}>
     */
    private function routerBackendPool(array $config): array
    {
        $pool = $config['router_backend_pool'] ?? null;

        if (! is_array($pool)) {
            return [];
        }

        return collect($pool)
            ->filter(fn (mixed $backend): bool => is_array($backend))
            ->map(function (array $backend): ?array {
                $node = $backend['node'] ?? null;
                $url = $backend['url'] ?? null;

                if (! is_string($node) || $node === '' || ! is_string($url) || $url === '') {
                    return null;
                }

                return [
                    'node' => $node,
                    'url' => $url,
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  list<string>  $keys
     */
    private function stringConfig(array $config, array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = $this->nestedConfig($config, $key);

            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function nestedConfig(array $config, string $key): mixed
    {
        /** @var mixed $value */
        $value = Collection::make(explode('.', $key))
            ->reduce(function (mixed $carry, string $segment): mixed {
                if (! is_array($carry) || ! array_key_exists($segment, $carry)) {
                    return null;
                }

                return $carry[$segment];
            }, $config);

        return $value;
    }
}
