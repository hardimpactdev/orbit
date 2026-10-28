<?php

declare(strict_types=1);

namespace App\Domain\Instances\Deployment;

use App\Domain\Operations\OperationGatewayCommand;
use App\Models\Operation;

/** Reads the selected release and existing deployment identity. It never selects code. */
final readonly class InstanceDeploymentReceiptCommand implements OperationGatewayCommand
{
    public function __construct(private InstanceDeploymentExecution $execution) {}

    public function schema(): array
    {
        return ['type' => 'object', 'additionalProperties' => false, 'required' => ['instance_id'],
            'properties' => ['instance_id' => ['type' => 'integer', 'minimum' => 1]]];
    }

    public function run(Operation $operation, #[\SensitiveParameter] array $input): array
    {
        return $this->execution->result($operation);
    }
}
