<?php

declare(strict_types=1);

namespace App\Domain\Integrations;

use App\Domain\Operations\OperationGatewayCommand;
use App\Models\Operation;

/** The preceding Node and child receipts are checked by IntegrationExecution before this final state receipt. */
final readonly class IntegrationReceiptCommand implements OperationGatewayCommand
{
    public function schema(): array
    {
        return ['type' => 'object', 'additionalProperties' => false, 'required' => ['backend'],
            'properties' => ['backend' => ['type' => 'string', 'enum' => ['active', 'absent', 'enabled', 'disabled', 'reconciled']]]];
    }

    public function run(Operation $operation, array $input): array
    {
        return ['backend' => $input['backend']];
    }
}
