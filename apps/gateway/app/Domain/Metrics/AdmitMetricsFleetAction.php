<?php

declare(strict_types=1);

namespace App\Domain\Metrics;

use App\Actions\Operations\StartOperationAction;
use App\Domain\Operations\OperationStatus;
use App\Domain\Operations\OperationIdempotency;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
use App\Models\Operation;
use Illuminate\Support\Facades\DB;

/** Capture intent and start one native fleet root. */
final readonly class AdmitMetricsFleetAction implements MetricsFleetReconciler
{
    public function __construct(private MetricsFleetCapture $capture, private StartOperationAction $operations, private OperationIdempotency $idempotency) {}

    public function reconcile(): void
    {
        $this->execute();
    }

    public function retire(Node $node): void
    {
        throw new ResourceOperationException('operation.kind_unavailable', 'Node retirement requires its native Metrics stage.', 503);
    }

    public function execute(): Operation
    {
        if (($replay = $this->idempotency->replay('metrics.reconcile')) !== null) {
            return $replay;
        }

        return DB::transaction(function (): Operation {
            $plan = $this->capture->capture();
            Node::query()->whereKey($plan['metrics_node_id'])->lockForUpdate()->firstOrFail();
            if (Operation::query()->where('node_id', $plan['metrics_node_id'])->whereIn('kind', ['metrics.reconcile', 'metrics.credentials_reset', 'metrics.exporter_enable', 'metrics.exporter_disable'])
                ->whereNotIn('status', [OperationStatus::Succeeded, OperationStatus::Failed, OperationStatus::Cancelled])->exists()) {
                throw new ResourceOperationException('metrics.fleet_busy', 'A Metrics fleet change has not ended.', 409);
            }
            $nodeId = $plan['metrics_node_id'];
            if (! is_int($nodeId)) {
                throw new ResourceOperationException('metrics.assignment_missing', 'The native Metrics root needs its serving Node.', 409);
            }
            $input = ['participant_node_ids' => array_column(MetricsFleetCapture::members($plan), 'node_id'), 'metrics_fleet' => $plan, 'fleet_runtime' => ['$secret' => 'metrics.fleet_runtime', 'id' => $nodeId]];


            return $this->operations->execute('metrics.reconcile', 'metrics', $nodeId, $nodeId, $input, 'operator', null);
        });
    }
}
