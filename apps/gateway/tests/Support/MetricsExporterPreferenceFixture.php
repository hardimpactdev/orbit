<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Metrics\ExporterPreference;
use App\Domain\Metrics\ExporterPreferenceRepository;
use App\Infrastructure\AgentCommands\AgentDelivery;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Models\Node;

final readonly class MetricsExporterPreferenceFixture
{
    public Node $metrics;

    public Node $exporter;

    public FakeRoutePublicationSocket $socket;

    private string $manifest;

    public function __construct()
    {
        $this->manifest = tempnam(sys_get_temp_dir(), 'exporter-agent-');
        file_put_contents($this->manifest, json_encode(['commit' => str_repeat('c', 40), 'version' => '0.5.0',
            'artifacts' => ['linux-x86_64' => ['sha256' => str_repeat('a', 64)]]], JSON_THROW_ON_ERROR));
        app()->instance(AgentDelivery::class, new AgentDelivery($this->manifest, sys_get_temp_dir()));
        $nodes = [];
        foreach ([9, 13, 1, 18] as $id) {
            $nodes[$id] = Node::query()->forceCreate(['id' => $id, 'name' => 'node-'.$id, 'platform' => 'linux',
                'architecture' => 'x86_64', 'status' => 'active', 'user' => 'orbit', 'public_ssh_host' => '192.0.2.'.$id,
                'wireguard_ip' => '10.44.0.'.$id, 'ssh_host_fingerprint' => 'fixture']);
        }
        $this->metrics = $nodes[9];
        $this->exporter = $nodes[13];
        $this->metrics->roles()->create(['role' => 'metrics', 'status' => 'active']);
        $this->socket = new FakeRoutePublicationSocket;
        app()->instance(WebSocketClient::class, $this->socket);
        config(['swamp.run_token' => 'fixture-token', 'swamp_workers.nodes' => [9, 13]]);
    }

    public function preference(): ?ExporterPreference
    {
        return app(ExporterPreferenceRepository::class)->get($this->exporter->id);
    }

    public function close(): void
    {
        unlink($this->manifest);
    }
}
