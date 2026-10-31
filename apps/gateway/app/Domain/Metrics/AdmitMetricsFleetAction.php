<?php

declare(strict_types=1);

namespace App\Domain\Metrics;

use App\Actions\Operations\StartOperationAction;
use App\Domain\Operations\OperationIdempotency;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;
use Illuminate\Support\Facades\DB;

/** Capture intent and start one native fleet root. */
final readonly class AdmitMetricsFleetAction
{
    public function __construct(private MetricsFleetCapture $capture, private StartOperationAction $operations, private OperationIdempotency $idempotency) {}

    public function execute(): Operation
    {
        if (($replay = $this->idempotency->replay('metrics.reconcile')) !== null) {
            return $replay;
        }

        return DB::transaction(function (): Operation {
            $plan = $this->capture->capture();
            $nodeId = $plan['metrics_node_id'];
            if (! is_int($nodeId)) {
                throw new ResourceOperationException('metrics.assignment_missing', 'The native Metrics root needs its serving Node.', 409);
            }
            $input = ['participant_node_ids' => array_column(MetricsFleetCapture::members($plan), 'node_id'), 'metrics_fleet' => $plan, 'fleet_runtime' => ['$secret' => 'metrics.fleet_runtime', 'id' => $nodeId]];

            return $this->operations->execute('metrics.reconcile', 'metrics', $nodeId, $nodeId, $input, 'operator', null);
        });
    }
}
