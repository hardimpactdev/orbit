<?php

declare(strict_types=1);

namespace App\Domain\DatabaseServers;

use App\Domain\Operations\OperationGatewayCommand;
use App\Models\Operation;
use App\Support\ValidatedData;

/** Read-only final evidence check, shared by this family's transport variants. */
final readonly class DatabaseProven implements OperationGatewayCommand
{
    public function __construct(private DatabaseOperationExecution $execution) {}

    public function schema(): array
    {
        return ['type' => 'object', 'additionalProperties' => false, 'required' => ['intent_id', 'children'], 'properties' => ['intent_id' => ['type' => 'integer', 'minimum' => 1], 'children' => ['type' => 'array', 'items' => ['type' => 'object']]]];
    }

    public function run(Operation $operation, #[\SensitiveParameter] array $input): array
    {
        $this->execution->assertEffects($operation);
        $runs = ValidatedData::object($operation->state['executor_runs'] ?? []);

        return ValidatedData::object(ValidatedData::object($runs['database-apply'] ?? [])['result'] ?? []);
    }
}
