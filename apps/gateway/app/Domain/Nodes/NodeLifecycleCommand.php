<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use App\Domain\Operations\OperationGatewayCommand;
use App\Models\Operation;

/** Database transitions and evidence only; every Node effect is a separate allowlisted run or child. */
final readonly class NodeLifecycleCommand implements OperationGatewayCommand
{
    public function __construct(private NodeLifecycleOperationExecution $execution) {}

    public function schema(): array
    {
        return ['anyOf' => [['type' => 'object', 'additionalProperties' => false], ['type' => 'array', 'maxItems' => 0]]];
    }

    public function run(Operation $operation, #[\SensitiveParameter] array $input): array
    {
        return $this->execution->result($operation);
    }
}
