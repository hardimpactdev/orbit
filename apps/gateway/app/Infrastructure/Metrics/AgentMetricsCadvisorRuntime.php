<?php

declare(strict_types=1);

namespace App\Infrastructure\Metrics;

use App\Models\Node;

final readonly class AgentMetricsCadvisorRuntime implements MetricsCadvisorRuntime
{
    private const string Command = 'metrics.cadvisor';

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
}
