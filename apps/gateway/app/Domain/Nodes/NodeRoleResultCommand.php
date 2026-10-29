<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use App\Domain\Operations\OperationGatewayCommand;
use App\Models\Operation;

final readonly class NodeRoleResultCommand implements OperationGatewayCommand
{
    public function __construct(private NodeRoleOperationExecution $execution) {}

    public function schema(): array
    {
        return ['type' => 'object', 'additionalProperties' => false];
    }

    public function run(Operation $operation, #[\SensitiveParameter] array $input): array
    {
        return $this->execution->result($operation);
    }
}
