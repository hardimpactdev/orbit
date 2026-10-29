<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use App\Domain\Operations\OperationGatewayCommand;
use App\Models\Operation;

/** Reads the transport and parent-stamped child evidence for the final footprint step. */
final readonly class NodeFootprintResultCommand implements OperationGatewayCommand
{
    public function __construct(private NodeFootprintEvidence $evidence) {}

    public function schema(): array
    {
        return ['type' => 'object', 'additionalProperties' => false];
    }

    public function run(Operation $operation, #[\SensitiveParameter] array $input): array
    {
        return $this->evidence->derive($operation, Operation::query()->where('parent_operation_id', $operation->id)->get())['result'];
    }
}
