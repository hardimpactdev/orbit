<?php

declare(strict_types=1);

use App\Models\DatabaseConnection;
use App\Models\Instance;
use App\Models\Operation;

function databasePreparationParent(Instance $target): Operation
{
    return Operation::query()->firstOrCreate(['kind' => 'instance.create', 'target_type' => 'instance', 'target_id' => $target->id],
        ['node_id' => $target->node_id, 'status' => 'running', 'input' => []]);
}

/** Arrange pre-existing, proven databases at the transport receipt boundary. */
function databaseCreatedProof(DatabaseConnection $connection): void
{
    $runs = [];
    $proofs = [];
    $operation = Operation::query()->create(['kind' => 'database.create', 'target_type' => 'database_server',
        'target_id' => $connection->database_server_id, 'node_id' => $connection->server->node_id,
        'input' => ['connection_id' => $connection->id], 'status' => 'succeeded', 'finished_at' => now()]);
    foreach (array_filter([$connection->database, $connection->test_database]) as $index => $name) {
        $key = 'create-database-'.$index;
        $runs[$key] = ['transport' => 'agent', 'name' => 'database.server-admin', 'result' => ['backend' => 'active']];
        $proofs[] = ['database' => $name, 'operation_id' => $operation->id, 'step' => $key, 'username' => $connection->username, 'server_id' => $connection->database_server_id];
    }
    $operation->update(['state' => ['executor_runs' => $runs]]);
    $connection->forceFill(['creation_receipts' => $proofs])->save();
}
