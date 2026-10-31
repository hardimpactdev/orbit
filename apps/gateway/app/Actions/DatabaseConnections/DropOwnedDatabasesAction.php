<?php

declare(strict_types=1);

namespace App\Actions\DatabaseConnections;

use App\Actions\DatabaseServers\RecordDatabaseOperationAction;
use App\Domain\DatabaseConnections\DatabaseDriver;
use App\Domain\DatabaseServers\PreparedDatabaseOperation;
use App\Models\DatabaseConnection;
use App\Models\Instance;

/** Admission for one native DAG, including databases on other Nodes and SQLite files. */
final readonly class DropOwnedDatabasesAction
{
    public function __construct(private DropServerDatabaseAction $servers, private RecordDatabaseOperationAction $operations) {}

    public function prepare(Instance $owner): PreparedDatabaseOperation
    {
        $connections = DatabaseConnection::query()->with(['server.node', 'users'])->where('owner_instance_id', $owner->id)->orderBy('id')->get();
        $managed = $connections->filter(static fn (DatabaseConnection $connection): bool => $connection->database_server_id !== null)->values()->all();
        $servers = [];
        $passwords = [];
        foreach ($this->servers->snapshots(array_values($managed), $owner->id) as $snapshot) {
            $servers[] = ['server_id' => $snapshot['server_id'], 'node_id' => $snapshot['node_id'], 'intent' => $snapshot['input']];
            $passwords[$snapshot['node_id']][$snapshot['server_id']] = $snapshot['password'];
        }
        $sqlite = [];
        foreach ($connections as $connection) {
            if ($connection->database_server_id === null && $connection->driver === DatabaseDriver::Sqlite && $connection->path !== null) {
                $sqlite[] = ['connection_id' => $connection->id, 'node_id' => $owner->node_id,
                    'checkout' => $owner->checkout_path, 'path' => $connection->path];
            }
        }

        $readerNodes = array_values(array_unique(array_column($servers, 'node_id')));

        return $this->operations->prepare('database.destroy_owned', 'instance', $owner->id, $owner->node_id,
            ['connection_ids' => $connections->modelKeys(), 'servers' => $servers, 'sqlite' => $sqlite,
                'private_bindings' => ['payload' => ['owner_node_id' => $owner->node_id, 'read_node_ids' => $readerNodes, 'write_node_ids' => []]],
                'participant_node_ids' => array_values(array_unique([$owner->node_id, ...array_column($servers, 'node_id')]))],
            ['database-apply' => ['name' => 'database.destroy_owned', 'input' => ['participant_passwords' => $passwords], 'protected' => ['payload']]],
            ['delete_connections' => $connections->modelKeys()]);
    }
}
