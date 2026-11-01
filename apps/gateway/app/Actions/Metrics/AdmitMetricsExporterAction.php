<?php

declare(strict_types=1);

namespace App\Actions\Metrics;

use App\Actions\Operations\StartOperationAction;
use App\Domain\Metrics\ExporterPreference;
use App\Domain\Metrics\ExporterPreferenceRepository;
use App\Domain\Metrics\MetricsExporterProjection;
use App\Domain\Metrics\MetricsFleetCapture;
use App\Domain\Nodes\ManagedNodeEligibility;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\OperationIdempotency;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Metrics\PrometheusConfigRenderer;
use App\Infrastructure\Metrics\ServiceMetricsProjection;
use App\Infrastructure\Swamp\SwampSshExecutionInput;
use App\Models\Node;
use App\Models\NodeRole;
use App\Models\Operation;
use App\Models\TaskVm;
use Illuminate\Support\Facades\DB;

/** Record exporter intent and its complete public scrape configuration. */
final readonly class AdmitMetricsExporterAction
{
    public function __construct(private StartOperationAction $operations, private ExporterPreferenceRepository $preferences,
        private MetricsExporterProjection $exporters, private ServiceMetricsProjection $services,
        private PrometheusConfigRenderer $prometheus, private SwampSshExecutionInput $ssh, private ManagedNodeEligibility $eligibility) {}

    public function execute(Node $node, bool $enabled): Operation
    {
        if (($existing = app(OperationIdempotency::class)->replay($enabled ? 'metrics.exporter_enable' : 'metrics.exporter_disable')) !== null) {
            return $existing;
        }
        if ($node->status !== LifecycleStatus::Active) {
            throw new ResourceOperationException('metrics.exporter_node_inactive', 'Metrics exporters require an active Node.', 409);
        }
        if ($node->platform !== 'linux') {
            throw new ResourceOperationException('metrics.platform_unsupported', 'Metrics exporters require Linux.', 422);
        }
        if (! $enabled && $node->roles()->where('role', RoleName::Metrics)->exists()) {
            throw new ResourceOperationException('node.role_conflict', 'The metrics node exporter cannot be disabled.', 422);
        }
        if (! $this->eligibility->allows($node) || TaskVm::forNode($node) instanceof TaskVm) {
            throw new ResourceOperationException('metrics.exporter_node_ineligible', 'Metrics exporters require managed WireGuard SSH access.', 409);
        }

        return DB::transaction(function () use ($node, $enabled): Operation {
            $assignments = NodeRole::query()->where('role', RoleName::Metrics)->where('status', LifecycleStatus::Active)
                ->whereHas('node', static fn ($query) => $query->where('status', LifecycleStatus::Active))->with('node')->limit(2)->get();
            if ($assignments->count() > 1) {
                throw new ResourceOperationException('metrics.assignment_drift', 'Exporter changes require one Metrics owner.', 409);
            }
            $metrics = $assignments->isEmpty() ? null : $assignments->sole()->node;
            Node::query()->whereKey($metrics instanceof Node ? $metrics->id : $node->id)->lockForUpdate()->firstOrFail();
            if (($existing = app(OperationIdempotency::class)->replay($enabled ? 'metrics.exporter_enable' : 'metrics.exporter_disable')) !== null) {
                return $existing;
            }
            if (Operation::query()->where('target_type', 'metrics')->where('target_id', $metrics instanceof Node ? $metrics->id : $node->id)
                ->whereIn('kind', ['metrics.exporter_enable', 'metrics.exporter_disable'])->whereIn('status', ['queued', 'running'])->exists()) {
                throw new ResourceOperationException('metrics.operation_active', 'An exporter change is already active for this Metrics owner.', 409);
            }
            $this->preferences->put($node->id, $enabled ? ExporterPreference::Enabled : ExporterPreference::Disabled);
            $runtime = null;
            if ($metrics instanceof Node) {
                $targets = [];
                foreach ($this->exporters->for($metrics) as $item) {
                    if ($item->selection->selected) {
                        $targets[] = ['name' => $item->node->name, 'address' => (string) $item->node->wireguard_ip];
                    }
                }
                $runtime = ['assignment_id' => $assignments->sole()->id, 'ssh_execution' => $this->ssh->forNode($metrics),
                    'configuration' => $this->prometheus->render($targets, $this->services->forFleet($metrics))];
            }

            return $this->operations->execute($enabled ? 'metrics.exporter_enable' : 'metrics.exporter_disable', 'metrics', $metrics instanceof Node ? $metrics->id : $node->id,
                $node->id, ['exporter_node_id' => $node->id, 'enabled' => $enabled,
                    'ssh_execution' => $this->ssh->forNode($node), 'metrics_address' => $metrics?->wireguard_ip, 'runtime' => $runtime,
                    'service' => $metrics instanceof Node ? app(MetricsFleetCapture::class)->forExporter($metrics, $node, $enabled) : null],
                'operator', request()->attributes->getString('orbit.request_id'));
        });
    }
}
