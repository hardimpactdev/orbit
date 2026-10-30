<?php

declare(strict_types=1);

namespace App\Domain\Metrics;

use App\Domain\Operations\OperationGatewayCommand;
use App\Models\Operation;

/** A fixed safe receipt, dispatched only after the family proves its child sequence. */
final readonly class MetricsReceiptCommand implements OperationGatewayCommand
{
    public const array Effects = ['metrics.node_exporter_snapshot', 'metrics.node_exporter_converge', 'metrics.node_exporter_remove', 'metrics.node_exporter_restore',
        'metrics.cadvisor_snapshot', 'metrics.cadvisor_converge', 'metrics.cadvisor_remove', 'metrics.cadvisor_restore',
        'metrics.service_snapshot', 'metrics.service_converge', 'metrics.service_restore', 'metrics.service_caddy_restore',
        'metrics.runtime_converge', 'metrics.runtime_verify'];

    public function schema(): array
    {
        return ['type' => 'object', 'additionalProperties' => false, 'required' => ['backend'],
            'properties' => ['backend' => ['type' => 'string', 'enum' => ['active', 'recorded']]]];
    }

    public function run(Operation $operation, array $input): array
    {
        return ['backend' => $input['backend']];
    }
}
