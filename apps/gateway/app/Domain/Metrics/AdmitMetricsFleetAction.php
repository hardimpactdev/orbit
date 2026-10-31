<?php

declare(strict_types=1);

namespace App\Domain\Metrics;

use App\Actions\Operations\StartOperationAction;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
use App\Models\NodeRole;
use App\Models\Operation;
use App\Support\ValidatedData;

/** Records standalone native intent. Ordered retirement needs owning native composition. */
final readonly class AdmitMetricsFleetAction implements MetricsFleetReconciler
{
    public function __construct(private MetricsFleetCapture $capture, private StartOperationAction $operations) {}

    public function reconcile(): void
    {
        if (NodeRole::query()->where('role', 'metrics')->where('status', 'active')->whereHas('node', static fn ($query) => $query->where('status', 'active'))->exists()) {
            $this->execute();
        }
    }

    public function retire(Node $node): void
    {
        if (NodeRole::query()->where('role', 'metrics')->where('status', 'active')->whereHas('node', static fn ($query) => $query->where('status', 'active'))->exists()) {
            throw new ResourceOperationException('metrics.native_composition_required', 'Node retirement requires Metrics cleanup in the owning native run.', 409);
        }
    }

    /** @param list<int> $canaryNodeIds Explicit effect scope, never an owner-selection filter. */
    public function execute(array $canaryNodeIds = []): Operation
    {
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
