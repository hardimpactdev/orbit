<?php

declare(strict_types=1);

namespace App\Domain\Metrics;

use App\Domain\Operations\OperationCompletion;
use App\Domain\Operations\OperationOutcome;
use App\Domain\Operations\OperationStatus;
use App\Models\Operation;

final readonly class MetricsFleetCompletion implements OperationCompletion
{
    public function __construct(private MetricsChildIntent $children, private ExporterDegradationRepository $degradations,
        private MetricsReconcileDegradationRepository $reconcile) {}

    public function complete(Operation $operation, OperationOutcome $outcome): void
    {
        $recoveredServices = [];
        foreach (MetricsIntent::fleet($operation) as $node) {
            $id = $node['node_id'];
            $degraded = false;
            foreach (['node_exporter', 'cadvisor'] as $component) {
                $snapshot = $this->children->find($operation, $component.'-snapshot-'.$id);
                if ($snapshot?->status === OperationStatus::Failed) {
                    $reason = ExporterDegradationReason::fromErrorCode($snapshot->error_code ?? '');
                    if ($reason !== null && $id !== $operation->node_id) {
                        $this->degradations->put($id, $reason);
                        $degraded = true;
                        break;
                    }
                }
                $apply = $this->children->find($operation, $component.'-apply-'.$id);
                if ($apply?->status === OperationStatus::Failed) {
                    $this->reconcile->put($id, $apply->error_code ?? 'metrics.exporter_reconcile_failed');
                }
            }
            if (! $degraded && $outcome->status === OperationStatus::Succeeded) {
                $this->degradations->forget($id);
                $this->reconcile->forget($id);
            }
            foreach (['service-snapshot-' => 'snapshot', 'service-apply-' => 'converge', 'service-restore-' => 'restore'] as $prefix => $step) {
                $child = $this->children->find($operation, $prefix.$id);
                if ($child?->status === OperationStatus::Failed) {
                    $this->degradations->recordReconcileFailure($id, $step, $step === 'restore' ? 'metrics.service_rollback_failed' : ($child->error_code ?? 'metrics.service_reconcile_failed'));
                }
            }
            if ($this->children->find($operation, 'service-apply-'.$id)?->status === OperationStatus::Succeeded && $outcome->status === OperationStatus::Succeeded) {
                $recoveredServices[] = $id;
            }
        }
        $this->degradations->forgetServiceFailures($recoveredServices);
    }
}
