<?php

declare(strict_types=1);

namespace App\Services\Proxy;

use App\Enums\Nodes\NodeRoleName;
use App\Enums\Nodes\NodeRoleStatus;
use App\Enums\Nodes\NodeStatus;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Models\NodeTool;
use App\Models\ProxyRoute;
use App\Services\Gateway\GatewayCaddyRouteRenderer;
use App\Services\Gateway\GatewayLeafIdentity;
use App\Services\Metrics\MetricsServiceRoute;
use App\Services\Nodes\Roles\NodeRoleAssignments;
use App\Services\S3\S3BackendName;
use App\Services\Tools\ToolCatalog;
use App\Tools\HermesTool;
use RuntimeException;

/**
 * One boundary intentionally owns the complete stable intent for every
 * non-Instance route family so render, probe, repair, and backfill cannot drift.
 *
 * @mago-expect lint:cyclomatic-complexity
 * @mago-expect lint:kan-defect
 * @mago-expect lint:too-many-methods
 */
final readonly class NonInstanceProxyRouteOwnership
{
    private const array OWNER_TYPES = ['custom', 'tool', 'router', 's3', 'gateway'];

    public function __construct(
        private ?NodeRoleAssignments $nodeRoleAssignments = null,
        private ?ToolCatalog $toolCatalog = null,
    ) {}

    public static function supports(string $ownerType): bool
    {
        return in_array($ownerType, self::OWNER_TYPES, strict: true);
    }

    public function matches(ProxyRoute $route): bool
    {
        $node = $this->ownedNode($route);

        if (! $node instanceof Node || ! $this->matchesNodeSelectionFor($route, $node)) {
            return false;
        }

        $config = is_array($route->config) ? $route->config : [];

        return match ($route->owner_type) {
            'custom' => $this->matchesCustom($route, $config),
            'tool' => $this->matchesTool($route, $node, $config),
            'router' => $this->matchesRouter($route, $node, $config),
            's3' => $this->matchesPublicS3($route, $node, $config),
            'gateway' => $this->matchesGateway($route, $node, $config),
            default => false,
        };
    }

    public function matchesNodeSelection(ProxyRoute $route): bool
    {
        $node = self::supports($route->owner_type)
            ? Node::query()->find($route->node_id)
            : null;

        if (! $node instanceof Node) {
            return false;
        }

        return $this->matchesNodeSelectionFor($route, $node);
    }

    private function matchesNodeSelectionFor(ProxyRoute $route, Node $node): bool
    {
        return match ($route->owner_type) {
            'custom' => $node->isActive()
                && (
                    $this->nodeRoleAssignments()->nodeCanServeGatewayOrAppHostWorkloads($node)
                    || $this->nodeRoleAssignments()->nodeCanServeIngress($node)
                ),
            'tool' => $node->isActive()
                && in_array($node->id, $this->nodeRoleAssignments()->activeToolHostNodeIds(), strict: true),
            'router' => $this->isCanonicalNode($node, 'router'),
            's3' => $this->isCanonicalNode($node, 'ingress'),
            'gateway' => $this->isCanonicalNode($node, 'gateway'),
            default => false,
        };
    }

    public function matchesStableMetricsFamily(ProxyRoute $route): bool
    {
        $node = $this->ownedNode($route);
        $config = is_array($route->config) ? $route->config : [];

        return (
            $node instanceof Node
            && $this->isCanonicalNode($node, 'router')
            && $route->domain === MetricsServiceRoute::Domain
            && $route->owner_type === 'router'
            && $route->kind === 'proxy'
            && ($config['owner_name'] ?? null) === MetricsServiceRoute::OwnerName
            && ($config['protocol'] ?? null) === MetricsServiceRoute::Scheme
            && in_array($config, [MetricsServiceRoute::config(), $this->legacyMetricsConfig()], strict: true)
        );
    }

    public function matchesStableServiceFamily(ProxyRoute $route): bool
    {
        $node = $this->ownedNode($route);

        if (
            ! $node instanceof Node
            || ! $this->isCanonicalNode($node, 'router')
            || $route->owner_type !== 'router'
            || $route->kind !== 'proxy'
        ) {
            return false;
        }

        $config = $this->stableConfig(is_array($route->config) ? $route->config : []);

        return match ($route->domain) {
            'analytics.orbit' => $this->matchesStableAnalyticsServiceConfig($node, $config),
            'websocket.orbit' => $this->matchesStableWebSocketServiceConfig($node, $config),
            's3.orbit' => $this->matchesStableS3ServiceConfig($config),
            MetricsServiceRoute::Domain => $this->hasAssignedBackendRole(NodeRoleName::Metrics)
                && $this->configsMatch($config, MetricsServiceRoute::config()),
            default => false,
        };
    }

    public function matchesToolOrphan(ProxyRoute $route): bool
    {
        $node = $this->ownedNode($route);

        if (! $node instanceof Node || ! $this->matchesNodeSelectionFor($route, $node)) {
            return false;
        }

        $config = $this->stableConfig(is_array($route->config) ? $route->config : []);
        $ownerName = $config['owner_name'] ?? null;

        if (
            $route->owner_type !== 'tool'
            || $route->kind !== 'proxy'
            || ! is_string($ownerName)
            || $ownerName === ''
            || ! in_array($ownerName, ['openclaw'], true)
            && $this->toolCatalog()->category($ownerName) !== 'agent'
            || $route->domain !== "{$ownerName}.".trim($node->tld, characters: " \n\r\t\v\0.")
        ) {
            return false;
        }

        $expectedUpstream = $this->defaultToolUpstream($ownerName);

        return $expectedUpstream !== null
        && $this->configsMatch($config, [
            'target' => ['type' => 'upstream', 'value' => $expectedUpstream],
            'upstream' => $expectedUpstream,
            'owner_name' => $ownerName,
        ]);
    }

    public function assertValid(ProxyRoute $route): void
    {
        if ($this->matches($route)) {
            return;
        }

        throw new RuntimeException(
            "Proxy route '{$route->domain}' has invalid {$route->owner_type} ownership.",
        );
    }

    private function ownedNode(ProxyRoute $route): ?Node
    {
        if (
            ! self::supports($route->owner_type)
            || $route->app_id !== null
            || $route->workspace_id !== null
            || $route->instance_id !== null
        ) {
            return null;
        }

        return Node::query()->find($route->node_id);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function matchesCustom(ProxyRoute $route, array $config): bool
    {
        $config = $this->stableConfig($config);

        if (
            array_key_exists('owner_name', $config)
            || array_key_exists('protocol', $config)
            || array_key_exists('placement', $config)
        ) {
            return false;
        }

        return match ($route->kind) {
            'proxy' => $this->matchesCustomProxyConfig($config),
            'redirect' => $this->matchesCustomRedirectConfig($config),
            default => false,
        };
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function matchesCustomProxyConfig(array $config): bool
    {
        $target = is_array($config['target'] ?? null) ? $config['target'] : [];
        $targetType = $target['type'] ?? null;
        $targetValue = $target['value'] ?? null;
        $upstream = $config['upstream'] ?? null;

        if ($targetType !== null && $targetType !== 'upstream') {
            return false;
        }

        if (is_string($targetValue) && is_string($upstream) && $targetValue !== $upstream) {
            return false;
        }

        $value = is_string($targetValue) && $targetValue !== '' ? $targetValue : $upstream;

        if (! is_string($value) || $value === '') {
            return false;
        }

        return (
            $this->configsMatch($config, ['upstream' => $value])
            || $this->configsMatch($config, [
                'target' => ['type' => 'upstream', 'value' => $value],
                'upstream' => $value,
            ])
        );
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function matchesCustomRedirectConfig(array $config): bool
    {
        $target = is_array($config['target'] ?? null) ? $config['target'] : [];
        $targetType = $target['type'] ?? null;
        $value = $target['value'] ?? $config['redirect'] ?? $config['redirect_url'] ?? null;

        if ($targetType !== null && $targetType !== 'redirect' || ! is_string($value) || $value === '') {
            return false;
        }

        $code = $config['code'] ?? 302;
        $normalizedCode = is_int($code)
            ? $code
            : (is_string($code) && ctype_digit($code) ? (int) $code : null);

        if ($normalizedCode === null || $normalizedCode < 300 || $normalizedCode > 399) {
            return false;
        }

        $expected = [
            'target' => ['type' => 'redirect', 'value' => $value],
            'code' => $code,
        ];

        if (($config['tls'] ?? null) === ['managed_by' => 'acme']) {
            $expected['tls'] = ['managed_by' => 'acme'];
        }

        return $this->configsMatch($config, $expected);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function matchesTool(ProxyRoute $route, Node $node, array $config): bool
    {
        $config = $this->stableConfig($config);
        $ownerName = $config['owner_name'] ?? null;

        if (
            $route->kind !== 'proxy'
            || ! is_string($ownerName)
            || $ownerName === ''
            || $this->toolCatalog()->category($ownerName) !== 'agent'
            || ! $this->toolCatalog()->supportsNode($ownerName, $node)
            || $route->domain !== "{$ownerName}.".trim($node->tld, characters: " \n\r\t\v\0.")
        ) {
            return false;
        }

        $tool = NodeTool::query()
            ->where('node_id', $node->id)
            ->where('name', $ownerName)
            ->where('expected_state', 'installed')
            ->first();

        if (! $tool instanceof NodeTool) {
            return false;
        }

        $toolConfig = is_array($tool->config) ? $tool->config : [];
        $expectedUpstream = is_string($toolConfig['upstream'] ?? null) && $toolConfig['upstream'] !== ''
            ? $toolConfig['upstream']
            : $this->defaultToolUpstream($ownerName);
        $target = is_array($config['target'] ?? null) ? $config['target'] : [];

        return $expectedUpstream !== null
        && ($config['upstream'] ?? null) === $expectedUpstream
        && ($target['type'] ?? null) === 'upstream'
        && ($target['value'] ?? null) === $expectedUpstream
        && $this->configsMatch($config, [
            'target' => ['type' => 'upstream', 'value' => $expectedUpstream],
            'upstream' => $expectedUpstream,
            'owner_name' => $ownerName,
        ]);
    }

    private function defaultToolUpstream(string $ownerName): ?string
    {
        return match ($ownerName) {
            'hermes' => 'http://'.ProxyRouteRenderer::HostLoopbackHostname.':'.HermesTool::WEB_PORT,
            'openclaw' => 'http://'.ProxyRouteRenderer::HostLoopbackHostname.':18789',
            default => null,
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function legacyMetricsConfig(): array
    {
        return [
            'owner_name' => MetricsServiceRoute::OwnerName,
            'protocol' => MetricsServiceRoute::Scheme,
            'target' => [
                'type' => 'upstream',
                'value' => 'http://gateway.metrics.orbit:3000',
            ],
            'upstreams' => [
                ['scheme' => 'http', 'host' => 'gateway.metrics.orbit', 'port' => MetricsServiceRoute::Port],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function matchesRouter(ProxyRoute $route, Node $node, array $config): bool
    {
        if ($route->kind !== 'proxy' || ! $this->isCanonicalNode($node, 'router')) {
            return false;
        }

        $config = $this->stableConfig($config);

        return match ($route->domain) {
            'analytics.orbit' => $this->matchesAnalyticsServiceConfig($node, $config),
            'websocket.orbit' => $this->matchesWebSocketServiceConfig($node, $config),
            's3.orbit' => $this->matchesS3ServiceConfig($config),
            MetricsServiceRoute::Domain => $this->hasPendingOrActiveBackendRole(NodeRoleName::Metrics)
                && $this->configsMatch($config, MetricsServiceRoute::config()),
            default => false,
        };
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function matchesAnalyticsServiceConfig(Node $router, array $config): bool
    {
        $backends = $this->intentBackendNodes(NodeRoleName::Analytics);

        if (count($backends) !== 1) {
            return false;
        }

        $upstreams = array_map(
            $this->analyticsUpstream(...),
            $backends,
        );

        return $this->configsMatch($config, [
            'protocol' => 'analytics',
            'router_upstream' => $this->routerUpstream($router),
            'router_backend_pool' => $this->backendPool($upstreams),
            'upstreams' => $upstreams,
            'tls' => [
                'managed_by' => 'orbit',
                'trusted_by_gateway_ca' => true,
                'cert_path' => '/etc/orbit/certs/analytics.orbit.crt',
                'key_path' => '/etc/orbit/certs/analytics.orbit.key',
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function matchesStableAnalyticsServiceConfig(Node $router, array $config): bool
    {
        $backends = $this->stableReferencedBackends($config, NodeRoleName::Analytics);

        if (count($backends) !== 1) {
            return false;
        }

        $upstreams = array_map(
            $this->analyticsUpstream(...),
            $backends,
        );

        return $this->configsMatch($config, [
            'protocol' => 'analytics',
            'router_upstream' => $this->routerUpstream($router),
            'router_backend_pool' => $this->backendPool($upstreams),
            'upstreams' => $upstreams,
            'tls' => [
                'managed_by' => 'orbit',
                'trusted_by_gateway_ca' => true,
                'cert_path' => '/etc/orbit/certs/analytics.orbit.crt',
                'key_path' => '/etc/orbit/certs/analytics.orbit.key',
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function matchesWebSocketServiceConfig(Node $router, array $config): bool
    {
        $backends = $this->intentBackendNodes(NodeRoleName::WebSocket);

        if (count($backends) !== 1) {
            return false;
        }

        $upstreams = array_map(
            $this->webSocketUpstream(...),
            $backends,
        );

        return $this->configsMatch($config, [
            'protocol' => 'websocket',
            'router_upstream' => $this->routerUpstream($router),
            'router_backend_pool' => $this->backendPool($upstreams),
            'router_backend_tls' => [
                'trusted_by_gateway_ca' => true,
                'ca_path' => '/etc/orbit/ca/root.crt',
            ],
            'upstreams' => $upstreams,
            'tls' => [
                'managed_by' => 'internal',
                'trusted_by_gateway_ca' => true,
                'cert_path' => '/etc/orbit/certs/websocket.orbit.crt',
                'key_path' => '/etc/orbit/certs/websocket.orbit.key',
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function matchesStableWebSocketServiceConfig(Node $router, array $config): bool
    {
        $backends = $this->stableReferencedBackends($config, NodeRoleName::WebSocket);

        if (count($backends) !== 1) {
            return false;
        }

        $upstreams = array_map(
            $this->webSocketUpstream(...),
            $backends,
        );

        return $this->configsMatch($config, [
            'protocol' => 'websocket',
            'router_upstream' => $this->routerUpstream($router),
            'router_backend_pool' => $this->backendPool($upstreams),
            'router_backend_tls' => [
                'trusted_by_gateway_ca' => true,
                'ca_path' => '/etc/orbit/ca/root.crt',
            ],
            'upstreams' => $upstreams,
            'tls' => [
                'managed_by' => 'internal',
                'trusted_by_gateway_ca' => true,
                'cert_path' => '/etc/orbit/certs/websocket.orbit.crt',
                'key_path' => '/etc/orbit/certs/websocket.orbit.key',
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function matchesS3ServiceConfig(array $config): bool
    {
        $backends = $this->intentBackendNodes(NodeRoleName::S3);

        if ($backends === []) {
            return false;
        }

        return $this->matchesS3ConfigForBackends($config, $backends);
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  list<Node>  $backends
     */
    private function matchesS3ConfigForBackends(array $config, array $backends): bool
    {
        $urls = [];
        $upstreams = [];

        foreach ($backends as $backend) {
            $tool = NodeTool::query()
                ->where('node_id', $backend->id)
                ->where('name', 'seaweedfs')
                ->where('expected_state', 'installed')
                ->first();

            if (! $tool instanceof NodeTool) {
                return false;
            }

            $host = new S3BackendName()->forNode($backend);
            $toolConfig = is_array($tool->config) ? $tool->config : [];

            if (($toolConfig['backend_host'] ?? null) !== $host) {
                return false;
            }

            $upstreams[] = [
                'scheme' => S3BackendName::BackendScheme,
                'host' => $host,
                'port' => S3BackendName::BackendPort,
            ];
            $urls[] = S3BackendName::BackendScheme."://{$host}:".S3BackendName::BackendPort;
        }

        return $this->configsMatch($config, [
            'owner_name' => 'seaweedfs',
            'protocol' => 's3',
            'target' => ['type' => 'upstream', 'value' => $urls[0]],
            'upstreams' => $upstreams,
        ]);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function matchesStableS3ServiceConfig(array $config): bool
    {
        $upstreams = $config['upstreams'] ?? null;

        if (! is_array($upstreams) || $upstreams === []) {
            return false;
        }

        $backends = [];

        foreach ($upstreams as $upstream) {
            if (! is_array($upstream)) {
                return false;
            }

            $host = is_string($upstream['host'] ?? null) ? $upstream['host'] : null;

            if (! is_string($host) || ! str_ends_with($host, '.s3.orbit')) {
                return false;
            }

            $node = Node::query()
                ->where('name', substr($host, 0, -strlen('.s3.orbit')))
                ->first();

            if (
                ! $node instanceof Node
                || ! $node->isActive()
                || ! $this->nodeHasAssignedRole($node, NodeRoleName::S3)
            ) {
                return false;
            }

            $backends[] = $node;
        }

        return $this->matchesS3ConfigForBackends($config, $backends);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function matchesPublicS3(ProxyRoute $route, Node $node, array $config): bool
    {
        $config = $this->stableConfig($config);
        $router = $this->canonicalNode('router');
        $routerAddress = $router instanceof Node && is_string($router->wireguard_address)
            ? trim($router->wireguard_address)
            : '';

        $expected = [
            'placement' => 'ingress',
            'owner_name' => 'seaweedfs',
            'protocol' => 's3',
            'target' => ['type' => 'upstream', 'value' => 'https://s3.orbit'],
            'router_upstream' => [
                'node_id' => $router?->id,
                'node' => $router?->name,
                'url' => "http://{$routerAddress}:80",
            ],
            'tls' => [
                'cert_path' => "/etc/orbit/certs/{$route->domain}.crt",
                'key_path' => "/etc/orbit/certs/{$route->domain}.key",
            ],
        ];

        return (
            $route->kind === 'proxy'
            && $this->isCanonicalNode($node, 'ingress')
            && $router instanceof Node
            && $routerAddress !== ''
            && $this->hasActiveBackendRole(NodeRoleName::S3)
            && $this->configsMatch($config, $expected)
        );
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function matchesGateway(ProxyRoute $route, Node $node, array $config): bool
    {
        return (
            $route->kind === 'internal'
            && $this->isCanonicalNode($node, 'gateway')
            && $route->domain === GatewayLeafIdentity::browserHostname()
            && $this->configsMatch($this->stableConfig($config), [
                'target' => [
                    'type' => 'upstream',
                    'value' => GatewayCaddyRouteRenderer::Upstream,
                ],
            ])
        );
    }

    /**
     * @return list<Node>
     */
    private function activeBackendNodes(NodeRoleName $role): array
    {
        $nodes = [];

        foreach (Node::query()
            ->where('status', NodeStatus::Active->value)
            ->whereIn('id', $this->nodeRoleAssignments()->activeNodeIdsForRole($role->value))
            ->orderBy('name')
            ->get() as $node) {
            if ($node instanceof Node) {
                $nodes[] = $node;
            }
        }

        return $nodes;
    }

    /**
     * @return list<Node>
     */
    private function intentBackendNodes(NodeRoleName $role): array
    {
        $nodes = [];

        foreach (Node::query()
            ->whereIn('status', [NodeStatus::Active->value, NodeStatus::Provisioning->value])
            ->whereIn('id', NodeRoleAssignment::query()
                ->select('node_id')
                ->where('role', $role->value)
                ->whereIn('status', [NodeRoleStatus::Active->value, NodeRoleStatus::Pending->value]))
            ->orderBy('name')
            ->get() as $node) {
            if ($node instanceof Node) {
                $nodes[] = $node;
            }
        }

        return $nodes;
    }

    /**
     * @param  array<string, mixed>  $config
     * @return list<Node>
     */
    private function stableReferencedBackends(array $config, NodeRoleName $role): array
    {
        $upstreams = $config['upstreams'] ?? null;

        if (! is_array($upstreams) || $upstreams === []) {
            return [];
        }

        $backends = [];

        foreach ($upstreams as $upstream) {
            $nodeId = is_array($upstream) ? $upstream['node_id'] ?? null : null;
            $node = is_int($nodeId) ? Node::query()->find($nodeId) : null;

            if (! $node instanceof Node || ! $node->isActive() || ! $this->nodeHasAssignedRole($node, $role)) {
                return [];
            }

            $backends[] = $node;
        }

        return $backends;
    }

    private function hasActiveBackendRole(NodeRoleName $role): bool
    {
        return $this->activeBackendNodes($role) !== [];
    }

    private function hasPendingOrActiveBackendRole(NodeRoleName $role): bool
    {
        $nodeIds = NodeRoleAssignment::query()
            ->where('role', $role->value)
            ->whereIn('status', [NodeRoleStatus::Active->value, NodeRoleStatus::Pending->value])
            ->pluck('node_id');

        return Node::query()
            ->where('status', NodeStatus::Active->value)
            ->whereIn('id', $nodeIds)
            ->exists();
    }

    private function hasAssignedBackendRole(NodeRoleName $role): bool
    {
        $nodeIds = NodeRoleAssignment::query()
            ->where('role', $role->value)
            ->pluck('node_id');

        return Node::query()
            ->where('status', NodeStatus::Active->value)
            ->whereIn('id', $nodeIds)
            ->exists();
    }

    private function nodeHasAssignedRole(Node $node, NodeRoleName $role): bool
    {
        return NodeRoleAssignment::query()
            ->where('node_id', $node->id)
            ->where('role', $role->value)
            ->exists();
    }

    /**
     * @return array{node_id: int, node: string, url: string}
     */
    private function routerUpstream(Node $router): array
    {
        $address = trim((string) $router->wireguard_address);

        return [
            'node_id' => $router->id,
            'node' => $router->name,
            'url' => "http://{$address}:80",
        ];
    }

    /**
     * @return array{node_id: int, node: string, scheme: string, host: string, port: int, url: string}
     */
    private function analyticsUpstream(Node $backend): array
    {
        $address = trim((string) $backend->wireguard_address);

        return [
            'node_id' => $backend->id,
            'node' => $backend->name,
            'scheme' => 'http',
            'host' => $address,
            'port' => 8000,
            'url' => "http://{$address}:8000",
        ];
    }

    /**
     * @return array{node_id: int, node: string, scheme: string, host: string, backend_name: string, port: int, url: string}
     */
    private function webSocketUpstream(Node $backend): array
    {
        $address = trim((string) $backend->wireguard_address);

        return [
            'node_id' => $backend->id,
            'node' => $backend->name,
            'scheme' => 'https',
            'host' => $address,
            'backend_name' => $address,
            'port' => 8080,
            'url' => "https://{$address}:8080",
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $upstreams
     * @return list<array{node_id: int, node: string, url: string}>
     */
    private function backendPool(array $upstreams): array
    {
        return array_map(
            static fn (array $upstream): array => [
                'node_id' => (int) $upstream['node_id'],
                'node' => (string) $upstream['node'],
                'url' => (string) $upstream['url'],
            ],
            $upstreams,
        );
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private function stableConfig(array $config): array
    {
        unset($config['enactment']);

        return $config;
    }

    /**
     * @param  array<string, mixed>  $actual
     * @param  array<string, mixed>  $expected
     */
    private function configsMatch(array $actual, array $expected): bool
    {
        $this->sortConfig($actual);
        $this->sortConfig($expected);

        return $actual === $expected;
    }

    /**
     * @param  array<mixed>  $value
     */
    private function sortConfig(array &$value): void
    {
        if (! array_is_list($value)) {
            ksort($value);
        }

        foreach ($value as &$item) {
            if (is_array($item)) {
                $this->sortConfig($item);
            }
        }
    }

    private function isCanonicalNode(Node $node, string $role): bool
    {
        return $this->canonicalNode($role)?->is($node) === true;
    }

    private function canonicalNode(string $role): ?Node
    {
        $query = match ($role) {
            'gateway' => $this->nodeRoleAssignments()->activeGatewayNodeQuery(),
            'router' => $this->nodeRoleAssignments()->activeRouterNodeQuery(),
            'ingress' => $this->nodeRoleAssignments()->activeIngressNodeQuery(),
            default => null,
        };

        $node = $query?->orderBy('id')->first();

        return $node instanceof Node ? $node : null;
    }

    private function nodeRoleAssignments(): NodeRoleAssignments
    {
        return $this->nodeRoleAssignments ?? app(NodeRoleAssignments::class);
    }

    private function toolCatalog(): ToolCatalog
    {
        return $this->toolCatalog ?? app(ToolCatalog::class);
    }
}
