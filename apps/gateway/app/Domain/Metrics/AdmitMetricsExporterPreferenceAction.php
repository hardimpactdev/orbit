<?php

declare(strict_types=1);

namespace App\Domain\Metrics;

use App\Actions\Operations\StartOperationAction;
use App\Domain\Nodes\ManagedNodeEligibility;
use App\Domain\Nodes\RoleAssignmentException;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\OperationIdempotency;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
use App\Models\NodeRole;
use App\Models\Operation;
use App\Models\TaskVm;
use Illuminate\Support\Facades\DB;

/** Record a preference and its complete fleet intent; Swamp owns every host effect. */
final readonly class AdmitMetricsExporterPreferenceAction
{
    public function __construct(
        private MetricsFleetCapture $capture,
        private ExporterPreferenceRepository $preferences,
        private ManagedNodeEligibility $eligibility,
        private StartOperationAction $operations,
        private OperationIdempotency $idempotency,
    ) {}

    public function execute(int $nodeId, ExporterPreference $preference): Operation
    {
        $kind = $preference === ExporterPreference::Enabled ? 'metrics.exporter_enable' : 'metrics.exporter_disable';
        if (($replay = $this->idempotency->replay($kind)) !== null) {
            return $replay;
        }

        return DB::transaction(function () use ($nodeId, $preference, $kind): Operation {
            $node = Node::query()->whereKey($nodeId)->firstOrFail();
            if ($node->status !== LifecycleStatus::Active) {
                throw new ResourceOperationException('metrics.exporter_node_inactive', 'Metrics exporters require an active Node.', 409);
            }
            if ($node->platform !== 'linux') {
                throw new ResourceOperationException('metrics.platform_unsupported', 'Metrics exporters require a Linux Node.', 422);
            }
            if ($preference === ExporterPreference::Disabled && $node->roles()->where('role', RoleName::Metrics)->exists()) {
                throw new RoleAssignmentException('The metrics node exporter cannot be disabled.');
            }
            if (! $this->eligibility->allows($node) || TaskVm::forNode($node) instanceof TaskVm) {
                throw new ResourceOperationException('metrics.exporter_node_ineligible', 'Metrics exporters require a managed persistent Linux Node.', 409);
            }

            $assignments = NodeRole::query()->where('role', RoleName::Metrics)->where('status', LifecycleStatus::Active)
                ->whereHas('node', static fn ($query) => $query->where('status', LifecycleStatus::Active))
                ->limit(2)->get();
            if ($assignments->count() !== 1) {
                throw new ResourceOperationException('metrics.assignment_missing', 'Exporter changes require one active Metrics assignment.', 409);
            }
            $metricsNodeId = $assignments->sole()->node_id;
            Node::query()->whereKey($metricsNodeId)->firstOrFail();
            $this->preferences->put($nodeId, $preference);
            $plan = $this->capture->capture();

            return $this->operations->execute($kind, 'metrics', $metricsNodeId, $metricsNodeId, [
                'exporter_node_id' => $nodeId,
                'exporter_preference' => $preference->value,
                'participant_node_ids' => array_column(MetricsFleetCapture::members($plan), 'node_id'),
                'metrics_fleet' => $plan,
                'fleet_runtime' => ['$secret' => 'metrics.fleet_runtime', 'id' => $metricsNodeId],
            ], 'operator', null);
        });
    }
}
