<?php

declare(strict_types=1);

namespace App\Infrastructure\Metrics;

use App\Models\Node;
use Throwable;

final readonly class AgentMetricsExporterRuntime implements MetricsExporterRuntime
{
    public function __construct(private MetricsExporterCommands $commands) {}

    public function actual(Node $node, Node $metricsNode): string
    {
        try {
            $result = $this->commands->run($node, $metricsNode);

            return is_string($result['actual']) ? $result['actual'] : 'unknown';
        } catch (Throwable) {
            return 'unknown';
        }
    }
}
