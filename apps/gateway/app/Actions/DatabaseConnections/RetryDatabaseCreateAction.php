<?php

declare(strict_types=1);

namespace App\Actions\DatabaseConnections;

use App\Data\DatabaseConnections\CreateServerDatabaseData;
use App\Domain\DatabaseServers\PreparedDatabaseOperation;
use App\Models\DatabaseConnection;
use App\Models\DatabaseExecutionIntent;
use App\Models\Operation;
use App\Support\ValidatedData;

/** Reuses reserved names and credentials after a failed create, only for the same admitted request. */
final readonly class RetryDatabaseCreateAction
{
    public function prepare(CreateServerDatabaseData $data): ?PreparedDatabaseOperation
    {
        $connection = DatabaseConnection::query()->where('slug', $data->slug)->first();
        if ($connection === null) {
            return null;
        }
        $operation = Operation::query()->where('kind', 'database.create')
            ->where('input->connection_id', $connection->id)
            ->orderByDesc('id')->first();
        if ($operation === null || $operation->finished_at === null || $operation->finished_at->lt(now()->subHours(DatabaseExecutionIntent::RETRY_HOURS)) || ! in_array($operation->status->value, ['failed', 'cancelled'], true)) {
            return null;
        }
        $intent = DatabaseExecutionIntent::query()->whereKey(ValidatedData::integer($operation->input['intent_id']))->first();
        if ($intent === null || ($intent->payload['request'] ?? null) !== $data->toArray()
            || ($intent->payload['record'] ?? null) !== $this->recordValues($connection)) {
            return null;
        }

        return new PreparedDatabaseOperation('database.create', $intent->target_type, $intent->target_id, $intent->node_id, $operation->input);
    }

    public function record(PreparedDatabaseOperation $prepared, CreateServerDatabaseData $data): PreparedDatabaseOperation
    {
        $intent = DatabaseExecutionIntent::recorded($prepared->input['intent_id']);
        $intent->update(['payload' => [...$intent->payload, 'request' => $data->toArray(), 'record' => $this->recordValues(DatabaseConnection::query()->findOrFail(ValidatedData::integer($prepared->input['connection_id'])))]]);

        return $prepared;
    }

    /** @return array<string, mixed> */
    private function recordValues(DatabaseConnection $connection): array
    {
        return [...$connection->only(['slug', 'database_server_id', 'owner_instance_id', 'node_id', 'host', 'port', 'database', 'path', 'username', 'password', 'locale', 'test_database', 'test_processes']), 'driver' => $connection->driver->value];
    }
}
