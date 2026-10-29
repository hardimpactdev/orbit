<?php

declare(strict_types=1);

namespace App\Domain\Instances\Environment;

use App\Domain\Operations\OperationGatewayCommand;
use App\Models\Operation;

final readonly class InstanceServedWebRootsReceiptCommand implements OperationGatewayCommand
{
    public function __construct(private InstanceServedWebRootsExecution $execution) {}

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
