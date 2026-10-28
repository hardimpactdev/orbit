<?php

declare(strict_types=1);

namespace App\Domain\Analytics;

use App\Domain\Metrics\MetricsFleetReconciler;
use App\Domain\Operations\OperationCompletion;
use App\Domain\Operations\OperationOutcome;
use App\Domain\Operations\OperationStatus;
use App\Models\Operation;

final readonly class InstanceAnalyticsOperationCompletion implements OperationCompletion
{
    public function __construct(private MetricsFleetReconciler $metrics) {}

    public function complete(Operation $operation, OperationOutcome $outcome): void
    {
        if ($outcome->status === OperationStatus::Succeeded) {
            $this->metrics->reconcile();
        }
    }
}
