<?php

declare(strict_types=1);

namespace App\Services\Tools;

use App\Models\Node;
use App\Models\NodeTool;
use App\Services\Proxy\AgentToolProxyRouteIntent;

final readonly class ToolPayloadMapper
{
    public function __construct(
        private ToolCatalog $catalog,
        private AgentToolProxyRouteIntent $agentToolProxy,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(NodeTool $tool): array
    {
        return [
            'name' => $tool->name,
            'node' => $tool->node?->name,
            'expected_state' => $tool->expected_state,
            'observed_state' => null,
            'observed_version' => null,
            'version' => $tool->expected_version,
            'managed' => true,
            'endpoints' => $this->endpoints($tool),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function endpoints(NodeTool $tool): array
    {
        $derived = $this->derivedAgentEndpoints($tool);

        if ($derived !== []) {
            return $derived;
        }

        $endpoints = $tool->config['endpoints'] ?? [];

        return is_array($endpoints) ? array_values($endpoints) : [];
    }

    /**
     * Canonical agent consumer shape:
     * `{name, kind, url, host, port, upstream_port}` where `port` is public HTTPS
     * (443) and `upstream_port` is the proxy loopback listen port.
     *
     * @return list<array{name: string, kind: string, url: string, host: string, port: int, upstream_port: int}>
     */
    private function derivedAgentEndpoints(NodeTool $tool): array
    {
        $tool->loadMissing('node');
        $node = $tool->node;

        if (! $node instanceof Node) {
            return [];
        }

        if ($this->catalog->category($tool->name) !== 'agent' || $tool->expected_state !== 'installed') {
            return [];
        }

        $route = $this->agentToolProxy->expectedRoute($tool);
        $upstreamPort = $this->agentToolProxy->upstreamPort($tool);

        if ($route === null || $upstreamPort === null) {
            return [];
        }

        $host = $route->domain;

        return [[
            'name' => $tool->name,
            'kind' => 'https',
            'url' => "https://{$host}",
            'host' => $host,
            'port' => 443,
            'upstream_port' => $upstreamPort,
        ]];
    }
}
