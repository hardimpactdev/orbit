<?php

declare(strict_types=1);

namespace App\Domain\Metrics;

use App\Infrastructure\Metrics\NativeMetricsExporterProjection;
use App\Models\Node;
use Illuminate\Container\Attributes\Bind;

#[Bind(NativeMetricsExporterProjection::class)]
interface MetricsExporterProjection
{
    /** @return list<MetricsExporterProjectionItem> */
    public function for(Node $metricsNode): array;

    public function forNode(Node $metricsNode, Node $node): ?MetricsExporterProjectionItem;
}
