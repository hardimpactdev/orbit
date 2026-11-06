<?php

declare(strict_types=1);

namespace App\Infrastructure\Metrics;

use App\Domain\Metrics\MetricsExporterLifecycle;
use App\Domain\Metrics\MetricsExporterProjection;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;

final readonly class NativeMetricsExporterLifecycle implements MetricsExporterLifecycle
{
    public function __construct(private MetricsExporterProjection $projection) {}

    public function targets(Node $metricsNode): array
    {
        $targets = [];

        foreach ($this->projection->for($metricsNode) as $item) {
            if (! $item->selection->selected) {
                continue;
            }

            $node = $item->node;
            $address = $node->wireguard_ip;

            if (! is_string($address) || filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
                throw new ResourceOperationException(
                    'metrics.exporter_address_invalid',
                    "Selected Metrics exporter node [{$node->name}] requires a valid WireGuard address.",
                    409,
                );
            }

            $targets[] = ['name' => $node->name, 'address' => $address];
        }

        usort($targets, static fn (array $left, array $right): int => strcmp($left['name'], $right['name']));

        return $targets;
    }
}
