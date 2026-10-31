<?php

declare(strict_types=1);

namespace App\Infrastructure\Metrics;

use App\Domain\Metrics\MetricsHostObserver;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\Processes\CommandDeadline;
use App\Models\Node;
use Throwable;

/** Fixed read-only agent observations; native Swamp owns all Metrics mutations. */
final readonly class AgentMetricsHostObserver implements MetricsHostObserver
{
    public function __construct(private AgentCommandRunner $agents, private CommandDeadline $deadline) {}

    public function actual(Node $node, Node $metricsNode): string
    {
        try {
            $data = $this->agents->run($node, 'metrics.exporter-state', [
                'address' => $node->wireguard_ip,
                'metrics_address' => $metricsNode->wireguard_ip,
            ], $this->deadline->cap(60.0));

            return in_array($data['actual'] ?? null, ['active', 'inactive', 'drift', 'unknown'], true)
                ? $data['actual'] : 'unknown';
        } catch (Throwable) {
            return 'unknown';
        }
    }

    public function health(Node $node, string $service): bool
    {
        if (MetricsService::tryFrom($service) === null) {
            return false;
        }
        try {
            return ($this->agents->run($node, 'metrics.runtime-health', ['service' => $service], $this->deadline->cap(60.0))['healthy'] ?? false) === true;
        } catch (Throwable) {
            return false;
        }
    }
}
