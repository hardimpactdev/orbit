<?php

declare(strict_types=1);

namespace App\Domain\Nodes\Metrics;

use App\Infrastructure\Nodes\Metrics\GrafanaPrometheusNodeMetricsReader;
use App\Models\Node;
use Illuminate\Container\Attributes\Bind;

#[Bind(GrafanaPrometheusNodeMetricsReader::class)]
interface NodeMetricsReader
{
    /**
     * Reads one metrics snapshot for the Node from the Metrics role's Grafana (Prometheus
     * datasource proxy).
     *
     * @return array<string, mixed>
     */
    public function read(Node $node): array;
}
