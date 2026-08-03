<?php

declare(strict_types=1);

namespace App\Services\Tools;

use App\Models\Node;
use App\Models\NodeTool;
use App\Services\Proxy\AgentToolProxyRouteIntent;
use App\Tools\HermesTool;
use App\Tools\OpenClawTool;

final readonly class ToolPayloadMapper
{
    public function __construct(
        private ?ToolCatalog $catalog = null,
        private ?AgentToolProxyRouteIntent $agentToolProxy = null,
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
     * Non-secret endpoint metadata. Agent tools derive the consumer HTTPS URL
     * from catalog category + node TLD + proxy contract instead of persisting
     * redundant endpoint copies on the tool row.
     *
     * Canonical endpoint shape:
     * `{name, kind, url, host, port}` where `url` is the operator-facing
     * consumer address (for agent tools, `https://{tool}.{tld}`).
     *
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
     * @return list<array{name: string, kind: string, url: string, host: string, port: int}>
     */
    private function derivedAgentEndpoints(NodeTool $tool): array
    {
        $tool->loadMissing('node');
        $node = $tool->node;

        if (! $node instanceof Node) {
            return [];
        }

        $catalog = $this->catalog ?? app(ToolCatalog::class);

        if ($catalog->category($tool->name) !== 'agent') {
            return [];
        }

        if ($tool->expected_state !== 'installed') {
            return [];
        }

        $route = ($this->agentToolProxy ?? app(AgentToolProxyRouteIntent::class))->expectedRoute($tool);

        if ($route === null) {
            return [];
        }

        $host = $route->domain;
        $url = "https://{$host}";

        return [[
            'name' => $tool->name,
            'kind' => 'https',
            'url' => $url,
            'host' => $host,
            'port' => $this->agentUpstreamPort($tool->name),
        ]];
    }

    private function agentUpstreamPort(string $toolName): int
    {
        return match ($toolName) {
            'openclaw' => OpenClawTool::WEB_PORT,
            'hermes' => HermesTool::WEB_PORT,
            default => 8080,
        };
    }
}
