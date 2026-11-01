<?php

declare(strict_types=1);

namespace App\Domain\Metrics;

use App\Models\Node;

interface MetricsRuntimeLifecycle
{
    public function health(Node $node, string $service): bool;
}
