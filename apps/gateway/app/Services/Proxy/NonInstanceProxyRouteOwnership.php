<?php

declare(strict_types=1);

namespace App\Services\Proxy;

use App\Models\Node;
use App\Models\NodeTool;
use App\Models\ProxyRoute;
use App\Services\Metrics\MetricsServiceRoute;
use App\Services\Nodes\Roles\NodeRoleAssignments;
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
            'tool' => $node->isActive() && $this->nodeRoleAssignments()->nodeHasActiveAgentRole($node),
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

        return is_string($value) && $value !== '';
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function matchesCustomRedirectConfig(array $config): bool
    {
        $target = is_array($config['target'] ?? null) ? $config['target'] : [];
        $targetType = $target['type'] ?? null;
        $value = $target['value'] ?? $config['redirect'] ?? $config['redirect_url'] ?? null;

        return ($targetType === null || $targetType === 'redirect') && is_string($value) && $value !== '';
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function matchesTool(ProxyRoute $route, Node $node, array $config): bool
    {
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

        return (
            $expectedUpstream !== null
            && ($config['upstream'] ?? null) === $expectedUpstream
            && ($target['type'] ?? null) === 'upstream'
            && ($target['value'] ?? null) === $expectedUpstream
        );
    }

    private function defaultToolUpstream(string $ownerName): ?string
    {
        if ($ownerName !== 'hermes') {
            return null;
        }

        return 'http://'.ProxyRouteRenderer::HostLoopbackHostname.':'.HermesTool::WEB_PORT;
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

        return match ($route->domain) {
            'analytics.orbit' => ($config['protocol'] ?? null) === 'analytics'
                && $this->matchesBackendPool($config['router_backend_pool'] ?? null),
            'websocket.orbit' => ($config['protocol'] ?? null) === 'websocket'
                && $this->matchesBackendPool($config['router_backend_pool'] ?? null),
            's3.orbit' => $this->matchesS3ServiceConfig($config),
            MetricsServiceRoute::Domain => $config === MetricsServiceRoute::config(),
            default => false,
        };
    }

    private function matchesBackendPool(mixed $pool): bool
    {
        return (
            is_array($pool)
            && $pool !== []
            && array_all(
                $pool,
                static fn (mixed $backend): bool => (
                    is_array($backend)
                    && is_int($backend['node_id'] ?? null)
                    && is_string($backend['node'] ?? null)
                    && $backend['node'] !== ''
                    && is_string($backend['url'] ?? null)
                    && $backend['url'] !== ''
                ),
            )
        );
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function matchesS3ServiceConfig(array $config): bool
    {
        $target = is_array($config['target'] ?? null) ? $config['target'] : [];
        $upstreams = $config['upstreams'] ?? null;

        if (
            ($config['owner_name'] ?? null) !== 'seaweedfs'
            || ($config['protocol'] ?? null) !== 's3'
            || ($target['type'] ?? null) !== 'upstream'
            || ! is_string($target['value'] ?? null)
            || ! is_array($upstreams)
            || $upstreams === []
        ) {
            return false;
        }

        $urls = [];

        foreach ($upstreams as $upstream) {
            if (
                ! is_array($upstream)
                || ! is_string($upstream['scheme'] ?? null)
                || ! is_string($upstream['host'] ?? null)
                || ! is_int($upstream['port'] ?? null)
            ) {
                return false;
            }

            $urls[] = "{$upstream['scheme']}://{$upstream['host']}:{$upstream['port']}";
        }

        return $target['value'] === $urls[0];
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function matchesPublicS3(ProxyRoute $route, Node $node, array $config): bool
    {
        $router = $this->canonicalNode('router');
        $routerUpstream = is_array($config['router_upstream'] ?? null) ? $config['router_upstream'] : [];
        $target = is_array($config['target'] ?? null) ? $config['target'] : [];
        $tls = is_array($config['tls'] ?? null) ? $config['tls'] : [];
        $routerAddress = $router instanceof Node && is_string($router->wireguard_address)
            ? trim($router->wireguard_address)
            : '';

        return (
            $route->kind === 'proxy'
            && $this->isCanonicalNode($node, 'ingress')
            && $router instanceof Node
            && $routerAddress !== ''
            && ($config['placement'] ?? null) === 'ingress'
            && ($config['owner_name'] ?? null) === 'seaweedfs'
            && ($config['protocol'] ?? null) === 's3'
            && ($target['type'] ?? null) === 'upstream'
            && ($target['value'] ?? null) === 'https://s3.orbit'
            && ($routerUpstream['node_id'] ?? null) === $router->id
            && ($routerUpstream['node'] ?? null) === $router->name
            && ($routerUpstream['url'] ?? null) === "http://{$routerAddress}:80"
            && ($tls['cert_path'] ?? null) === "/etc/orbit/certs/{$route->domain}.crt"
            && ($tls['key_path'] ?? null) === "/etc/orbit/certs/{$route->domain}.key"
        );
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function matchesGateway(ProxyRoute $route, Node $node, array $config): bool
    {
        $target = is_array($config['target'] ?? null) ? $config['target'] : [];

        return (
            $route->kind === 'internal'
            && $this->isCanonicalNode($node, 'gateway')
            && ($target['type'] ?? null) === 'upstream'
            && is_string($target['value'] ?? null)
            && $target['value'] !== ''
        );
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
