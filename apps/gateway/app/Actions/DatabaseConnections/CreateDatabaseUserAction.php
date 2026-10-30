<?php

declare(strict_types=1);

namespace App\Actions\DatabaseConnections;

use App\Actions\DatabaseServers\RecordDatabaseOperationAction;
use App\Data\DatabaseConnections\CreateDatabaseUserData;
use App\Domain\DatabaseConnections\DatabaseDriver;
use App\Domain\DatabaseServers\DatabaseCommands;
use App\Domain\DatabaseServers\MysqlStatements;
use App\Domain\DatabaseServers\PostgresStatements;
use App\Domain\Shared\ResourceOperationException;
use App\Models\DatabaseConnection;
use App\Models\DatabaseServer;
use App\Models\DatabaseUser;
use App\Models\Operation;

/**
 * Adds or updates a SQL login and replaces its grants. Replication users get reader grants.
 * On a Valkey
 * server each user is a connection of its own, so `database:create --server` adds it instead.
 */
final readonly class CreateDatabaseUserAction
{
    public function __construct(
        private MysqlStatements $statements,
        private RecordDatabaseOperationAction $operations,
    ) {}

    public function execute(
        DatabaseConnection $connection,
        CreateDatabaseUserData $data,
        string $createdBy,
    ): Operation {
        $server = $connection->server;

        if ($data->replication && ($connection->driver !== DatabaseDriver::Pgsql || ($server instanceof DatabaseServer && $server->driver !== DatabaseDriver::Pgsql))) {
            throw new ResourceOperationException('database.driver_unsupported', 'Replication users require a PostgreSQL Database server.', 422);
        }

        if ($server instanceof DatabaseServer && $server->driver === DatabaseDriver::Redis) {
            throw new ResourceOperationException(
                errorCode: 'database.driver_unsupported',
                message: "Database connection [{$connection->slug}] is on Valkey server [{$server->slug}], where each user is a connection. Run database:create --server instead.",
                status: 422,
            );
        }

        if (! $server instanceof DatabaseServer || ! is_string($connection->database)) {
            throw new ResourceOperationException(
                errorCode: 'database.server_required',
                message: "Database connection [{$connection->slug}] is not on a Database server.",
                status: 422,
            );
        }

        $ownsConnection = DatabaseConnection::query()
            ->where('database_server_id', $server->id)
            ->where('username', $data->username)
            ->exists();

        if ($ownsConnection) {
            throw new ResourceOperationException(
                errorCode: 'database.name_conflict',
                message: "User [{$data->username}] belongs to a connection on Database server [{$server->slug}].",
                status: 409,
            );
        }

        $knownUser = DatabaseUser::query()->where('database_connection_id', $connection->id)->where('username', $data->username)->exists();
        $statements = $this->statements->forDriver($server->driver);
        $readOnly = $data->readOnly || $data->replication;
        $ensureUser = $statements instanceof PostgresStatements
            ? $statements->ensureUser($data->username, $data->password, replication: $data->replication)
            : $statements->ensureUser($data->username, $data->password);

        return $this->operations->execute('database_user.create', 'database_server', $server->id, $server->node_id,
            ['connection_id' => $connection->id, 'driver' => $server->driver->value, 'username' => $data->username,
                'replication' => $data->replication, 'known_user' => $knownUser],
            ['database-apply' => DatabaseCommands::sql($server, $ensureUser.$statements->replaceGrant($connection->database, $data->username, $readOnly, $connection->username), redactions: [$data->password])], ['upsert_user' => [
                'database_connection_id' => $connection->id, 'username' => $data->username, 'password' => $data->password,
                'privileges' => $statements->privilegeText($connection->database, $readOnly), 'replication' => $data->replication,
                'created_by' => $createdBy,
            ]]);
    }
}
