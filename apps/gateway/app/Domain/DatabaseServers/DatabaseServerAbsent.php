<?php

declare(strict_types=1);

namespace App\Domain\DatabaseServers;

use App\Domain\Operations\OperationGatewayCommand;
use App\Models\DatabaseServer;
use App\Models\Operation;
use SensitiveParameter;

/** A failed server with no Process needs only proven registry removal. */
final readonly class DatabaseServerAbsent implements OperationGatewayCommand
{
    public function schema(): array
    {
        return ['type' => 'object', 'properties' => [], 'additionalProperties' => false];
    }

    public function run(Operation $operation, #[SensitiveParameter] array $input): array
    {
        $server = DatabaseServer::query()->findOrFail($operation->target_id);

        return $server->process_id === null && ! $server->databaseConnections()->exists()
            ? ['backend' => 'absent'] : ['error_code' => 'database.server_in_use'];
    }
}
