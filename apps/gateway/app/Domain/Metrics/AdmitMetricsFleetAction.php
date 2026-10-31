<?php

declare(strict_types=1);

namespace App\Domain\Metrics;

use App\Actions\Operations\StartOperationAction;
use App\Domain\Operations\DirectOperation;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;
use App\Support\ValidatedData;

/** Candidate intent admission; refuses while its production direct declaration is held. */
final readonly class AdmitMetricsFleetAction
{
    public function __construct(private MetricsFleetCapture $capture, private StartOperationAction $operations) {}

    /** @param list<int> $canaryNodeIds Explicit effect scope, never an owner-selection filter. */
    public function execute(array $canaryNodeIds = []): Operation
    {
        if (app(DirectOperation::class)->writer('metrics.reconcile') !== 'direct') {
            throw new ResourceOperationException('metrics.writer_held', 'Native Metrics activation awaits fleet placement and parent composition.', 503);
        }
        $plan = $this->capture->capture();
        $ready = ValidatedData::integerList(array_column(array_filter(MetricsFleetCapture::members($plan), static fn (array $node): bool => $node['worker'] === true), 'node_id'));
        if (count($canaryNodeIds) !== count(array_unique($canaryNodeIds)) || array_diff($canaryNodeIds, $ready) !== []) {
            throw new ResourceOperationException('metrics.canary_scope_invalid', 'Canary effects require their own admitted workers.', 422);
        }
        $nodeId = $plan['metrics_node_id'];
        if (! is_int($nodeId)) {
            throw new ResourceOperationException('metrics.assignment_missing', 'The native Metrics root needs its serving Node.', 409);
        }
        $input = ['metrics_fleet' => $plan, 'fleet_runtime' => ['$secret' => 'metrics.fleet_runtime', 'id' => $nodeId]];
        if ($canaryNodeIds !== []) {
            $input['canary_node_ids'] = $canaryNodeIds;
        }

        return $this->operations->execute('metrics.reconcile', 'metrics', $nodeId, $nodeId, $input, 'operator', null, expectedWriter: 'direct');
    }
}
