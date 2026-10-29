<?php

declare(strict_types=1);

namespace App\Domain\Instances\Deployment;

use App\Domain\Operations\OperationChildren;
use App\Domain\Operations\OperationGatewayCommand;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;
use App\Support\ValidatedData;

/** Projects a terminal child; this receipt never substitutes for the child's admission proof. */
final readonly class InstanceChildReceiptCommand implements OperationGatewayCommand
{
    public function __construct(private OperationChildren $children) {}

    public function schema(): array
    {
        return ['type' => 'object', 'additionalProperties' => false, 'required' => ['child_key'],
            'properties' => ['child_key' => ['type' => 'string']]];
    }

    public function run(Operation $operation, #[\SensitiveParameter] array $input): array
    {
        $child = $this->children->child($operation, ValidatedData::string($input['child_key']));
        if (! $child->status->terminal()) {
            throw new ResourceOperationException('operation.child_unproven', 'The child has not ended.', 409);
        }

        return ['child_id' => $child->id, 'child_kind' => $child->kind, 'child_status' => $child->status->value,
            ...($child->status === OperationStatus::Succeeded ? [] : ['error_code' => $child->error_code ?? 'operation.child_failed'])];
    }
}
