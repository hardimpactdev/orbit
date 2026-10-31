<?php

declare(strict_types=1);

namespace App\Domain\Metrics;

use App\Actions\Operations\StartOperationAction;
use App\Domain\Operations\OperationIdempotency;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Swamp\SwampCommandStep;
use App\Models\Node;
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
            $input = app(MetricsFleetWorkflowIntent::class)->capture($plan);
            $password = app(MetricsCredentialManager::class)->passwordForConvergence(Node::query()->findOrFail($nodeId));
            $input['execution_secrets'] = ['grafana_password' => app(SwampCommandStep::class)->executionCopy(
                'orbit-control-inputs', 'metrics-grafana-node-'.$nodeId.'-active', $password,
            )];

            return $this->operations->execute('metrics.reconcile', 'metrics', $nodeId, $nodeId, $input, 'operator', null);
        });
    }
}
