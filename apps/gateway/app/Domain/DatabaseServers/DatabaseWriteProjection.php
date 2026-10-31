<?php

declare(strict_types=1);

namespace App\Domain\DatabaseServers;

use App\Domain\Nodes\NodeAccessAuthorizer;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Operations\OperationExecutionContract;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\DatabaseConnections\PdoDatabaseInspector;
use App\Infrastructure\DatabaseConnections\SqliteNodeDatabaseInspector;
use App\Models\DatabaseConnection;
use App\Models\DatabaseExecutionIntent;
use App\Models\Operation;
use App\Support\ValidatedData;

final readonly class DatabaseWriteProjection implements DirectOperationProjection
{
    public function input(Operation $operation): array
    {
        $id = app(DatabaseIntentSource::class)->ownerId($operation);
        $intent = $id === null ? null : DatabaseExecutionIntent::recorded($id);
        $command = $intent?->payload['commands']['database-apply'] ?? [];
        $input = $command['input'] ?? [];
        $connection = DatabaseConnection::query()->find($operation->target_id);
        if ($connection === null || $intent === null
            || ($operation->input['connection_node_id'] ?? null) !== $connection->node_id
            || ($operation->input['connection_id'] ?? null) !== $connection->id || ($operation->input['driver'] ?? null) !== $connection->driver->value
            || ($command['protected'] ?? []) !== ['password', 'sql', 'dsn', 'username']) {
            $this->invalid();
        }
        $safe = ['driver' => $connection->driver->value];
        if ($connection->driver->value === 'sqlite') {
            $expected = app(SqliteNodeDatabaseInspector::class)->writeInput($connection, '');
            if (! is_string($operation->input['agent_sha256'] ?? null) || preg_match('/\A[0-9a-f]{64}\z/D', $operation->input['agent_sha256']) !== 1
                || $command['name'] !== 'database.write-sqlite' || $connection->node_id !== $operation->node_id
                || ($input['owner'] ?? null) !== $expected['owner'] || ($input['path'] ?? null) !== $expected['path']) {
                $this->invalid();
            }

            return [...$safe, 'agent_sha256' => $operation->input['agent_sha256'], 'owner' => $expected['owner'], 'path' => $expected['path']];
        }
        if ($command['name'] !== 'database.write' || $operation->node_id !== app(NodeAccessAuthorizer::class)->activeGatewayId()
            || ($input['dsn'] ?? null) !== app(PdoDatabaseInspector::class)->dsn($connection)
            || ($input['username'] ?? null) !== $connection->username || ($input['password'] ?? null) !== ($connection->password ?? '')) {
            $this->invalid();
        }

        return $safe;
    }

    public function apply(Operation $operation, array $data): Operation
    {
        $this->input($operation);
        $result = app(OperationExecutionContract::class)->result($operation, ValidatedData::object($data['result'] ?? []));

        return app(DirectOperation::class)->project($operation, $data, $result, 'database-apply');
    }

    private function invalid(): never
    {
        throw new ResourceOperationException('database.write_intent_invalid', 'The write does not match its admitted Connection and Node.', 422);
    }
}
