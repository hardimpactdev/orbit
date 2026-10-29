<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use App\Domain\Operations\OperationGatewayCommand;
use App\Models\Operation;

final readonly class NodeNetworkResultCommand implements OperationGatewayCommand
{
    public function __construct(private NodeNetworkOperationExecution $execution) {}

    public function schema(): array
    {
        return ['anyOf' => [['type' => 'object', 'additionalProperties' => false], ['type' => 'array', 'maxItems' => 0]]];
    }

    public function run(Operation $operation, #[\SensitiveParameter] array $input): array
    {
        return $this->execution->result($operation);
    }
}
