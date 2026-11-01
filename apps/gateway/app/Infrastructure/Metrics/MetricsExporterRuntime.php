<?php

declare(strict_types=1);

namespace App\Infrastructure\Metrics;

use App\Models\Node;

interface MetricsExporterRuntime
{
    public function actual(Node $node, Node $metricsNode): string;
}
