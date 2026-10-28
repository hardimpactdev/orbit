<?php

declare(strict_types=1);

namespace App\Infrastructure\Metrics;

use App\Actions\Metrics\StartMetricsOperationAction;
use App\Domain\Metrics\ExporterDegradationRepository;
use App\Domain\Metrics\MetricsFleetReconciler;
use App\Domain\Nodes\RoleAssignmentException;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Models\Node;
use App\Models\NodeRole;

/** Gateway callers record a fleet intent. Swamp owns its effects and recovery. */
final readonly class NativeMetricsFleetReconciler implements MetricsFleetReconciler
{
    public function __construct(private StartMetricsOperationAction $operations, private ExporterDegradationRepository $degradations) {}
    public function reconcile(): void
    {
        $assignments = NodeRole::query()->where('role', RoleName::Metrics)->where('status', LifecycleStatus::Active)
            ->whereHas('node', static fn ($query) => $query->where('status', LifecycleStatus::Active))->with('node')->limit(2)->get();
        if ($assignments->isEmpty()) { return; }
        if ($assignments->count() !== 1) { throw new RoleAssignmentException('Active Metrics role assignment drift detected.'); }
        $assignment = $assignments->sole();
        $this->operations->execute($assignment->node, $assignment);
    }
    public function retire(Node $node): void
    {
        $this->degradations->forget($node->id);
        $this->reconcile();
    }
}
