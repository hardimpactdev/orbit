<?php

declare(strict_types=1);

namespace App\Domain\Metrics;

use App\Models\Node;

/** Records standalone intent only. Ordered native parents compose Metrics stages in their own run. */
interface MetricsFleetReconciler
{
    public function reconcile(): void;

    public function retire(Node $node): void;
}
