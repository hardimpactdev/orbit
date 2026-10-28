<?php

declare(strict_types=1);

namespace App\Actions\Metrics;

use App\Actions\Integrations\StartIntegrationOperationAction;
use App\Domain\Integrations\IntegrationPlan;
use App\Domain\Metrics\MetricsExporterProjection;
use App\Domain\Metrics\MetricsGatewayResolver;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Metrics\ServiceMetricsProjection;
use App\Models\Node;
use App\Models\NodeRole;
use App\Models\Operation;
use Illuminate\Support\Facades\DB;

/** Stores a fixed fleet projection. Swamp snapshots, changes, degrades and restores that projection. */
final readonly class StartMetricsOperationAction
{
    public function __construct(private StartIntegrationOperationAction $operations, private MetricsExporterProjection $exporters,
        private ServiceMetricsProjection $services, private MetricsGatewayResolver $gateways) {}

    public function preference(Node $node, string $kind): Operation
    {
        $plan = new IntegrationPlan($node->id);
        $plan->receipt('active');

        return $this->operations->execute('metrics.'.$kind, 'metrics_preference', $node->id, $node->id,
            ['preference_only' => true, 'node_id' => $node->id], $plan);
    }

    /** Retirement cleanup does not block removal or the remaining fleet's target update. */
    public function retire(Node $node, Node $metrics): Operation
    {
        $plan = new IntegrationPlan($node->id);
        $addresses = ['address' => $node->wireguard_ip, 'metrics_address' => $metrics->wireguard_ip];
        foreach (['node_exporter', 'cadvisor'] as $component) {
            $plan->child('retire-'.$component, 'metrics.'.$component.'_remove', $node->id, $addresses, true);
        }
        $service = $this->services->forNode($metrics, $node, false);
        $plan->child('retire-services', 'metrics.service_converge', $node->id,
            [...$addresses, 'instance_ids' => array_map(static fn ($instance): int => $instance->id, $service->instances), 'caddy' => false, 'fpm' => false], true);
        $plan->receipt('absent');

        return $this->operations->execute('metrics.retire', 'metrics_retirement', $node->id, $node->id, [], $plan, 'integration');
    }

    public function execute(Node $node, NodeRole|int $assignment, string $verb = 'reconcile', bool $purge = false, bool $unreachable = false, ?Operation $existing = null): Operation
    {
        return DB::transaction(function () use ($node, $assignment, $verb, $purge, $unreachable, $existing): Operation {
            $remove = $verb === 'disable';
            $gateway = $remove ? $this->gateways->find() : $this->gateways->resolve();
            $relocated = $remove && NodeRole::query()->where('role', RoleName::Metrics)->where('node_id', '!=', $node->id)->exists();
            $candidates = [];
            if (! $unreachable && ! $relocated) {
                foreach ($this->exporters->for($node) as $item) {
                    if (! is_string($item->node->wireguard_ip) || filter_var($item->node->wireguard_ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
                        throw new ResourceOperationException('metrics.exporter_address_invalid', 'Selected exporters require valid WireGuard IPv4 addresses.', 409);
                    }
                    $service = $this->services->forNode($node, $item->node, ! $remove);
                    $candidates[] = ['node_id' => $item->node->id, 'name' => $item->node->name, 'address' => $item->node->wireguard_ip,
                        'selected' => ! $remove && $item->selection->selected, 'caddy' => $service->caddy, 'fpm' => $service->fpm, 'hosts' => $service->hosts,
                        'instance_ids' => array_map(static fn ($instance): int => $instance->id, $service->instances)];
                }
            }
            $dns = NodeRole::query()->where('role', RoleName::Vpn)->where('status', LifecycleStatus::Active)->first();
            $input = ['node_id' => $node->id, 'assignment_id' => $assignment instanceof NodeRole ? $assignment->id : $assignment, 'metrics_address' => $node->wireguard_ip,
                'gateway_node_id' => $gateway?->id, 'gateway_address' => $gateway?->wireguard_ip, 'relocated' => $relocated, 'dns_node_id' => $dns?->node_id,
                'fleet' => $candidates, 'fleet_node_ids' => array_column($candidates, 'node_id'),
                'remove' => $remove, 'purge_data' => $purge, 'unreachable' => $unreachable,
                'publication_cleanup' => $remove ? ($gateway === null ? 'uncleaned' : 'cleaned') : null];
            $plan = new IntegrationPlan($node->id);
            $plan->receipt($remove ? 'absent' : 'active');

            if ($existing !== null) { return $this->operations->prepare($existing, $input, $plan); }
            return $this->operations->execute('metrics.'.$verb, 'metrics', $node->id, $node->id, $input, $plan);
        });
    }
}
