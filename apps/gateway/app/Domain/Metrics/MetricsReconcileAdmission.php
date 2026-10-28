<?php

declare(strict_types=1);

namespace App\Domain\Metrics;

use App\Actions\Metrics\StartMetricsOperationAction;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\OperationChildAdmission;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\NodeRole;
use App\Models\Operation;

/** Route and other parents request an empty intent; Metrics captures its own fleet at admission. */
final readonly class MetricsReconcileAdmission implements OperationChildAdmission
{
    public function __construct(private StartMetricsOperationAction $operations) {}

    public function admit(Operation $parent, array $intent): Operation
    {
        if ($intent['kind'] !== 'metrics.reconcile' || $intent['input'] !== []) {
            throw new ResourceOperationException('operation.execution_forbidden', 'Metrics reconciliation accepts an empty child input.', 422);
        }
        $assignments = NodeRole::query()->where('role', RoleName::Metrics)->where('status', LifecycleStatus::Active)
            ->whereHas('node', static fn ($query) => $query->where('status', LifecycleStatus::Active))
            ->with('node')->limit(2)->get();
        if ($assignments->count() !== 1 || $assignments->sole()->node_id !== $intent['node_id']) {
            throw new ResourceOperationException('metrics.assignment_missing', 'Reconciliation needs its one active Metrics serving Node.', 409);
        }
        $assignment = $assignments->sole();

        return $this->operations->execute($assignment->node, $assignment, 'reconcile');
    }
}
