<?php

declare(strict_types=1);

namespace App\Domain\DatabaseServers;

use App\Domain\Operations\OperationGatewayCommand;
use App\Models\DatabaseConnection;
use App\Models\Operation;

/** Reads completion evidence after the managed removal children have finished. */
final readonly class DatabaseOwnedReady implements OperationGatewayCommand
{
    public function schema(): array
    {
        return ['type' => 'object', 'required' => ['owner_instance_id'], 'additionalProperties' => false,
            'properties' => ['owner_instance_id' => ['type' => 'integer', 'minimum' => 1]]];
    }

    public function run(Operation $operation, #[\SensitiveParameter] array $input): array
    {
        return DatabaseConnection::query()->where('owner_instance_id', $input['owner_instance_id'])->whereNotNull('database_server_id')->exists()
            ? ['error_code' => 'database.owned_pending'] : ['backend' => 'absent'];
    }
}
