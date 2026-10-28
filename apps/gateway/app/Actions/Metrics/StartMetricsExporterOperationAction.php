<?php

declare(strict_types=1);

namespace App\Actions\Metrics;

use App\Actions\Operations\StartOperationAction;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
use App\Models\Operation;
use LogicException;

/** Records one fixed, convergent Node effect; fleet ordering belongs to Swamp. */
final readonly class StartMetricsExporterOperationAction
{
    public function __construct(private StartOperationAction $operations) {}

    public function execute(Node $node, Node $metricsNode, string $component, bool $remove = false): Operation
    {
        if (! in_array($component, ['node_exporter', 'cadvisor'], true)) {
            throw new LogicException('Unknown Metrics exporter component.');
        }
        foreach ([$node, $metricsNode] as $target) {
            if ($target->platform !== 'linux') {
                throw new ResourceOperationException('metrics.platform_unsupported', 'Metrics exporters require a Linux node.', 422);
            }
            if (! is_string($target->wireguard_ip) || filter_var($target->wireguard_ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
                throw new ResourceOperationException('metrics.exporter_address_invalid', 'Metrics exporters require valid WireGuard IPv4 addresses.', 409);
            }
        }

        return $this->operations->execute(
            'metrics.'.$component.($remove ? '_remove' : '_converge'),
            'metrics_'.$component,
            $node->id,
            $node->id,
            ['address' => $node->wireguard_ip, 'metrics_address' => $metricsNode->wireguard_ip],
            'integration',
            null,
        );
    }
}
