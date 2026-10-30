<?php

declare(strict_types=1);

namespace App\Domain\DatabaseServers;

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Shared\ResourceOperationException;
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
        if ($operation->kind !== 'database.write' || $operation->target_type !== 'database_connection'
            || $connection === null || $intent === null || count($intent->payload['commands']) !== 1
            || ($intent->payload['projection'] ?? []) !== []
            || ! in_array($connection->driver->value, ['sqlite', 'mysql', 'pgsql'], true)
            || ($operation->input['connection_id'] ?? null) !== $connection->id || ($operation->input['driver'] ?? null) !== $connection->driver->value
            || ($command['protected'] ?? []) !== ['password', 'sql', 'dsn', 'username']
            || ! is_string($operation->input['agent_sha256'] ?? null) || preg_match('/\A[0-9a-f]{64}\z/D', $operation->input['agent_sha256']) !== 1) {
            $this->invalid();
        }
        $safe = ['driver' => $connection->driver->value, 'agent_sha256' => $operation->input['agent_sha256']];
        if ($connection->driver->value === 'sqlite') {
            $expected = app(SqliteNodeDatabaseInspector::class)->writeInput($connection, '');
            if ($command['name'] !== 'database.write-sqlite' || $connection->node_id !== $operation->node_id
                || ($input['owner'] ?? null) !== $expected['owner'] || ($input['path'] ?? null) !== $expected['path']) {
                $this->invalid();
            }

            return [...$safe, 'owner' => $expected['owner'], 'path' => $expected['path']];
        }
        if ($command['name'] !== 'database.write') {
            $this->invalid();
        }

        return $safe;
    }

    public function apply(Operation $operation, array $data): Operation
    {
        $this->input($operation);
        $result = array_intersect_key(ValidatedData::object($data['result'] ?? []), array_flip(['backend', 'error_code', 'row_count', 'truncated']));
        $status = ValidatedData::string($data['status']);
        $code = ValidatedData::nullableString($data['error_code'] ?? null);
        if (($status === 'succeeded' && (array_diff_key($result, array_flip(['backend', 'row_count', 'truncated'])) !== []
                || ($result['backend'] ?? null) !== 'active' || ! is_int($result['row_count'] ?? null) || $result['row_count'] < 0 || ! is_bool($result['truncated'] ?? null) || $code !== null))
            || (in_array($status, ['running', 'cancelled'], true) && ($result !== [] || $code !== null))
            || ($status === 'failed' && ($result !== ['error_code' => $code] || ! in_array($code, ['agent.version_mismatch', 'database.sqlite_unreadable', 'database.query_failed'], true)))) {
            throw new ResourceOperationException('operation.result_invalid', 'SQL write needs its bounded native row count.', 422);
        }

        return app(DirectOperation::class)->project($operation, $data, $result, 'database-apply');
    }

    private function invalid(): never
    {
        throw new ResourceOperationException('database.write_intent_invalid', 'The write does not match its admitted Connection and Node.', 422);
    }
}
