<?php

declare(strict_types=1);

namespace App\Infrastructure\Metrics;

use App\Models\Node;
use Throwable;

final readonly class AgentMetricsExporterRuntime implements MetricsExporterRuntime
{
    private const string Command = 'metrics.node-exporter';

    public function __construct(private MetricsExporterCommands $commands) {}

    public function snapshot(Node $node, Node $metricsNode): MetricsExporterState
    {
        return $this->commands->snapshot($node, $metricsNode, self::Command);
    }

    public function converge(Node $node, Node $metricsNode): void
    {
        $this->commands->run($node, $metricsNode, self::Command, ['operation' => 'converge']);
    }

    public function remove(Node $node, Node $metricsNode): void
    {
        $this->commands->run($node, $metricsNode, self::Command, ['operation' => 'remove']);
    }

    public function restore(Node $node, Node $metricsNode, MetricsExporterState $state): void
    {
        $this->commands->run($node, $metricsNode, self::Command, ['operation' => 'restore', 'state' => $state->toAgent()]);
    }

    public function actual(Node $node, Node $metricsNode): string
    {
        try {
            $result = $this->commands->run($node, $metricsNode, self::Command, ['operation' => 'actual']);

            return is_string($result['actual']) ? $result['actual'] : 'unknown';
        } catch (Throwable) {
            return 'unknown';
        }
    }
}
