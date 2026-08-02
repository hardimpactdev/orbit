<?php

declare(strict_types=1);

namespace App\Services\Proxy;

use App\Models\Node;
use App\Models\NodeTool;
use App\Models\ProxyRoute;
use App\Services\Tools\ToolCatalog;
use App\Tools\HermesTool;
use App\Tools\OpenClawTool;

/** @mago-expect lint:cyclomatic-complexity */
final readonly class AgentToolProxyRouteIntent
{
    public function __construct(
        private ToolCatalog $tools,
        private ProxyRouteRenderer $renderer,
    ) {}

    /**
     * @return list<ProxyRoute>
     */
    public function expectedRoutesForNode(Node $node): array
    {
        $routes = [];

        foreach (NodeTool::query()
            ->with('node')
            ->where('node_id', $node->id)
            ->where('expected_state', 'installed')
            ->get() as $tool) {
            $route = $this->expectedRoute($tool);

            if ($route instanceof ProxyRoute) {
                $routes[] = $route;
            }
        }

        return $routes;
    }

    public function expectedRouteFor(Node $node, string $toolName): ?ProxyRoute
    {
        $tool = NodeTool::query()
            ->with('node')
            ->where('node_id', $node->id)
            ->where('name', $toolName)
            ->where('expected_state', 'installed')
            ->first();

        return $tool instanceof NodeTool ? $this->expectedRoute($tool) : null;
    }

    public function expectedRoute(NodeTool $tool): ?ProxyRoute
    {
        if ($tool->expected_state !== 'installed' || $this->tools->category($tool->name) !== 'agent') {
            return null;
        }

        $tool->loadMissing('node');
        $node = $tool->node;

        if (! $node instanceof Node) {
            return null;
        }

        $tld = trim($node->tld, characters: " \n\r\t\v\0.");

        if ($tld === '') {
            return null;
        }

        $toolConfig = is_array($tool->config) ? $tool->config : [];
        $upstream = is_string($toolConfig['upstream'] ?? null) && $toolConfig['upstream'] !== ''
            ? $toolConfig['upstream']
            : $this->defaultUpstream($tool->name);
        $config = [
            'target' => ['type' => 'upstream', 'value' => $upstream],
            'upstream' => $upstream,
            'owner_name' => $tool->name,
        ];
        $route = new ProxyRoute([
            'node_id' => $node->id,
            'domain' => "{$tool->name}.{$tld}",
            'app_id' => null,
            'workspace_id' => null,
            'owner_type' => 'tool',
            'kind' => 'proxy',
            'config' => $config,
        ]);
        $route->source_hash = $this->renderer->sourceHash($route);

        return $route;
    }

    public function persistedRoute(ProxyRoute $expected): ?ProxyRoute
    {
        return ProxyRoute::query()->where('domain', $expected->domain)->first();
    }

    public function matches(ProxyRoute $actual, ProxyRoute $expected): bool
    {
        return (
            $actual->node_id === $expected->node_id
            && $actual->app_id === null
            && $actual->workspace_id === null
            && $actual->owner_type === $expected->owner_type
            && $actual->kind === $expected->kind
            && $actual->config === $expected->config
            && $actual->source_hash === $expected->source_hash
        );
    }

    public function hasOwnershipConflict(ProxyRoute $actual, ProxyRoute $expected): bool
    {
        if ($actual->owner_type !== 'tool') {
            return true;
        }

        $actualConfig = is_array($actual->config) ? $actual->config : [];
        $expectedConfig = is_array($expected->config) ? $expected->config : [];

        return ($actualConfig['owner_name'] ?? null) !== ($expectedConfig['owner_name'] ?? null);
    }

    /**
     * Default container-reachable upstream for agent tool web UIs.
     * Hermes keeps port 8080; OpenClaw uses its documented default 18789 so both
     * can co-host without colliding with Orbit Caddy's private backend on 8081.
     */
    private function defaultUpstream(string $toolName): string
    {
        $port = match ($toolName) {
            'openclaw' => OpenClawTool::WEB_PORT,
            'hermes' => HermesTool::WEB_PORT,
            default => 8080,
        };

        return 'http://'.ProxyRouteRenderer::HostLoopbackHostname.':'.$port;
    }

    public function persist(ProxyRoute $expected): ?ProxyRoute
    {
        $actual = $this->persistedRoute($expected);

        if ($actual instanceof ProxyRoute && $this->hasOwnershipConflict($actual, $expected)) {
            return null;
        }

        return ProxyRoute::query()->updateOrCreate(
            ['domain' => $expected->domain],
            [
                'node_id' => $expected->node_id,
                'app_id' => null,
                'workspace_id' => null,
                'owner_type' => $expected->owner_type,
                'kind' => $expected->kind,
                'config' => $expected->config,
                'source_hash' => $expected->source_hash,
            ],
        );
    }
}
