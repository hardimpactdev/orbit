<?php

declare(strict_types=1);

namespace App\Domain\Metrics;

use App\Domain\Operations\OperationCompletion;
use App\Domain\Operations\OperationOutcome;
use App\Domain\Operations\OperationStatus;
use App\Models\Operation;

/** Records safe exporter failure state after a proven Node effect. */
final readonly class MetricsOperationCompletion implements OperationCompletion
{
    public function __construct(private ExporterDegradationRepository $degradations) {}

    public function complete(Operation $operation, OperationOutcome $outcome): void
    {
        if ($operation->node_id === null || $operation->getAttribute('parent_operation_id') !== null
            || preg_match('/\Ametrics\.(?:node_exporter|cadvisor)_(?:converge|remove|restore)\z/D', $operation->kind) !== 1) {
            return;
        }
        if ($outcome->status === OperationStatus::Failed) {
            $this->degradations->recordReconcileFailure(
                $operation->node_id,
                str_starts_with($operation->kind, 'metrics.cadvisor_') ? 'cadvisor' : 'exporter',
                $outcome->errorCode ?? 'metrics.exporter_reconcile_failed',
            );
        }
    }
}
