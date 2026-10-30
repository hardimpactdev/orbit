<?php

declare(strict_types=1);

namespace App\Domain\Metrics;

/** Records intent only. Exporters and runtime ordering belong to Swamp. */
final readonly class StartMetricsReconcileAction implements MetricsFleetReconciler
{
    public function __construct(private MetricsReconcileAdmission $admission) {}

    public function reconcile(): void
    {
        $this->admission->start();
    }
}
