<?php

declare(strict_types=1);

namespace App\Domain\Metrics;

use App\Models\Node;

interface MetricsHostObserver
{
    public function actual(Node $node, Node $metricsNode): string;

    public function health(Node $node, string $service): bool;
}
