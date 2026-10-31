<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use App\Domain\Metrics\MetricsFleetCapture;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\NodeRole;
use App\Support\ValidatedData;

/** One complete post-transition desired fleet; no before/after impact simulation or host effects. */
final readonly class NodeRoleMetricsInput
{
    public function __construct(private MetricsFleetCapture $fleet) {}

    /** Capture after the owning transaction records the changed role assignment.
     * The removed Metrics assignment supplies its own full disabled fleet.
     *
     * @return array<string,mixed>|null
     */
    public function capture(NodeRole $changed): ?array
    {
        if (! $changed->exists) {
            throw new ResourceOperationException('metrics.assignment_missing', 'Metrics capture requires a recorded role transition.', 409);
        }
        if ($changed->role === RoleName::Metrics) {
            return $this->fleet->captureFor($changed, $changed->status !== LifecycleStatus::Removing);
        }
        $assignments = NodeRole::query()->where('role', RoleName::Metrics)
            ->whereIn('status', [LifecycleStatus::Active, LifecycleStatus::Provisioning])
            ->whereHas('node', static fn ($query) => $query->where('status', LifecycleStatus::Active))
            ->with('node')->limit(2)->get();
        if ($assignments->isEmpty()) {
            return null;
        }
        if ($assignments->count() !== 1) {
            throw new ResourceOperationException('metrics.assignment_drift', 'A role transition requires one serving Metrics owner.', 409);
        }

        return $this->fleet->captureFor($assignments->sole());
    }

    /** Every recorded member participates, including workerless Nodes.
     * @param  array<string,mixed>|null  $plan
     * @return list<int>
     */
    public static function participants(?array $plan): array
    {
        return $plan === null ? [] : array_map(
            static fn (array $member): int => ValidatedData::integer($member['node_id']),
            MetricsFleetCapture::members($plan),
        );
    }
}
