<?php

declare(strict_types=1);

namespace App\Domain\Metrics;

use App\Actions\Operations\StartOperationAction;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\OperationChildAdmission;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Metrics\ServiceMetricsProjection;
use App\Models\NodeRole;
use App\Models\Operation;

/** Route parents request an empty intent; Metrics alone captures the serving assignment and fleet. */
final readonly class MetricsReconcileAdmission implements OperationChildAdmission
{
    public function __construct(private StartOperationAction $operations, private MetricsExporterProjection $exporters,
        private ServiceMetricsProjection $services) {}

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
        $node = $assignment->node;
        if ($node->platform !== 'linux' || ! is_string($node->wireguard_ip) || filter_var($node->wireguard_ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            throw new ResourceOperationException('metrics.platform_unsupported', 'Reconciliation needs its serving Linux Node with a WireGuard IPv4 address.', 422);
        }
        $candidates = [];
        foreach ($this->exporters->for($node) as $item) {
            if (! is_string($item->node->wireguard_ip) || filter_var($item->node->wireguard_ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
                throw new ResourceOperationException('metrics.exporter_address_invalid', 'Selected exporters require valid WireGuard IPv4 addresses.', 409);
            }
            $service = $this->services->forNode($node, $item->node, true);
            $candidates[] = ['node_id' => $item->node->id, 'name' => $item->node->name, 'address' => $item->node->wireguard_ip,
                'selected' => $item->selection->selected, 'caddy' => $service->caddy, 'fpm' => $service->fpm, 'hosts' => $service->hosts,
                'instance_ids' => array_map(static fn ($instance): int => $instance->id, $service->instances)];
        }
        $input = ['node_id' => $node->id, 'assignment_id' => $assignment->id, 'metrics_address' => $node->wireguard_ip,
            'gateway_node_id' => null, 'gateway_address' => null, 'relocated' => false, 'dns_node_id' => null,
            'fleet' => $candidates, 'fleet_node_ids' => array_column($candidates, 'node_id'),
            'remove' => false, 'purge_data' => false, 'unreachable' => false, 'publication_cleanup' => null];

        return $this->operations->execute('metrics.reconcile', 'metrics', $node->id, $node->id, $input, 'integration', null);
    }
}
