<?php

declare(strict_types=1);

namespace App\Domain\Metrics;

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Operations\OperationStatus;
use App\Models\Operation;

final readonly class MetricsExporterOperationProjection implements DirectOperationProjection
{
    public function __construct(private DirectOperation $direct) {}

    public function input(Operation $operation): array
    {
        return ['intent' => $operation->input];
    }

    public function apply(Operation $operation, array $data): Operation
    {
        $result = $data['status'] === OperationStatus::Succeeded->value
            ? ['node_id' => $operation->input['exporter_node_id'], 'status' => $operation->input['enabled'] ? 'enabled' : 'disabled',
                'preference_only' => $operation->input['runtime'] === null]
            : ['error_code' => $data['error_code'] ?? null];

        return $this->direct->project($operation, $data, $result);
    }
}
