<?php

declare(strict_types=1);

namespace App\Domain\Metrics;

interface MetricsFleetReconciler
{
    public function reconcile(): void;
}
