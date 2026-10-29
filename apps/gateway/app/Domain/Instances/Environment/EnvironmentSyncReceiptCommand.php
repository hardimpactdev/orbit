<?php

declare(strict_types=1);

namespace App\Domain\Instances\Environment;

use App\Domain\Operations\OperationGatewayCommand;
use App\Models\Operation;

/** Reads proven writes. It performs no Node work. */
final readonly class EnvironmentSyncReceiptCommand implements OperationGatewayCommand
{
    public function __construct(private EnvironmentSyncExecution $execution) {}

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
