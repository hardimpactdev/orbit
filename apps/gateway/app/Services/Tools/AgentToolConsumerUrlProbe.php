<?php

declare(strict_types=1);

namespace App\Services\Tools;

use App\Data\Doctor\DriftEntry;
use App\Data\Doctor\ProbeSnapshot;
use App\Enums\DriftKind;
use App\Models\Node;
use App\Models\NodeTool;
use App\Services\Ca\OrbitCaService;
use App\Services\Proxy\AgentToolProxyRouteIntent;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Tool-family read-only consumer HTTPS check for installed autonomous-agent tools.
 * Proxy family owns route rows, Caddy artifacts, and TLS material.
 */
final readonly class AgentToolConsumerUrlProbe
{
    public function __construct(
        private ToolCatalog $catalog,
        private AgentToolProxyRouteIntent $agentToolProxy,
        private OrbitCaService $orbitCa,
    ) {}

    /**
     * @return list<DriftEntry>
     */
    public function check(NodeTool $tool, ProbeSnapshot $snapshot): array
    {
        if ($tool->expected_state !== 'installed' || $this->catalog->category($tool->name) !== 'agent') {
            return [];
        }

        $tool->loadMissing('node');
        $node = $tool->node;

        if (! $node instanceof Node) {
            return [];
        }

        $observed = $snapshot->get($tool->name);
        $installed = is_array($observed) && ($observed['installed'] ?? null) === true;

        if (! $installed) {
            return [];
        }

        $route = $this->agentToolProxy->expectedRoute($tool);

        if ($route === null) {
            return [];
        }

        $url = 'https://'.$route->domain;
        $caPath = $this->orbitCa->rootCertificatePath();

        if ($caPath === null) {
            return [
                new DriftEntry(
                    family: 'tool',
                    key: 'tool.agent_consumer_url_unreachable',
                    kind: DriftKind::Unverifiable,
                    summary: "Tool {$tool->name} consumer URL {$url} could not be verified: Orbit root CA is unavailable.",
                    detail: [
                        'tool' => $tool->name,
                        'node' => $node->name,
                        'expected_url' => $url,
                        'observed' => 'orbit_root_ca_missing',
                        'next_command' => "doctor --node={$node->name} --family=proxy",
                        'ownership' => 'tool-family service readiness; proxy-family owns route rows/TLS',
                    ],
                ),
            ];
        }

        try {
            $response = Http::connectTimeout(3)
                ->timeout(8)
                ->withOptions(['verify' => $caPath])
                ->withHeaders(['Accept' => '*/*'])
                ->get($url);
            $status = $response->status();
            $reachable = $status >= 200 && $status < 400;
            $observedState = "HTTP {$status}";
        } catch (ConnectionException $exception) {
            $reachable = false;
            $status = null;
            $observedState = 'connection_failed: '.$this->summarize($exception->getMessage());
        } catch (Throwable $exception) {
            $reachable = false;
            $status = null;
            $observedState = 'probe_failed: '.$this->summarize($exception->getMessage());
        }

        if ($reachable) {
            return [];
        }

        return [
            new DriftEntry(
                family: 'tool',
                key: 'tool.agent_consumer_url_unreachable',
                kind: DriftKind::Divergent,
                summary: "Tool {$tool->name} consumer URL {$url} is not reachable from the gateway.",
                detail: [
                    'tool' => $tool->name,
                    'node' => $node->name,
                    'expected_url' => $url,
                    'observed' => $observedState,
                    'http_status' => $status,
                    'next_command' => "doctor --node={$node->name} --family=proxy",
                    'ownership' => 'tool-family service readiness; proxy-family owns route rows/TLS',
                ],
            ),
        ];
    }

    private function summarize(string $value): string
    {
        $normalized = trim(preg_replace('/\s+/', ' ', $value) ?? $value);

        if ($normalized === '') {
            return 'probe failed';
        }

        if (strlen($normalized) > 240) {
            return substr($normalized, 0, 237).'...';
        }

        return $normalized;
    }
}
