<?php

declare(strict_types=1);

namespace App\Domain\Metrics;

use App\Models\Node;

interface MetricsExporterLifecycle
{
    /** @return list<array{name: string, address: string}> */
    public function targets(Node $metricsNode): array;
}
