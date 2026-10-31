<?php

declare(strict_types=1);

namespace App\Domain\Metrics;

use App\Models\Node;

interface MetricsExporterProjection
{
    /** @return list<MetricsExporterProjectionItem> */
    public function for(Node $metricsNode): array;

    public function forNode(Node $metricsNode, Node $node): ?MetricsExporterProjectionItem;

    /** Admission-owned candidate only; normal observation still requires persisted Active state. */
    public function forEnrollment(Node $metricsNode, Node $candidate): ?MetricsExporterProjectionItem;
}
