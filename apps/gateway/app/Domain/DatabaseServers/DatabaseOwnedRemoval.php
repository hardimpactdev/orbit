<?php

declare(strict_types=1);

namespace App\Domain\DatabaseServers;

use App\Domain\DatabaseConnections\DatabaseDriver;
use App\Domain\Shared\ResourceOperationException;
use App\Models\DatabaseServer;
use App\Support\ValidatedData;

/**
 * The `operation-database-destroy` child of `instance:destroy`, one per Database server: the same run inputs that
 * `database:destroy` uses, rendered from the admitted owned-database snapshot.
 */
final readonly class DatabaseOwnedRemoval
{
    /**
     * @return array{0: list<array{server_id: int, node_id: int, intent: array<string, mixed>}>, 1: array<string, array{vault: string, key: string}>}
     */
    public static function servers(int $intentId, mixed $rows, bool $push): array
    {
        if (! is_array($rows) || ! array_is_list($rows)) {
            throw new ResourceOperationException('database.ownership_unproven', 'The owned removal snapshot is incomplete.', 422);
        }
        $servers = [];
        $copies = [];
        foreach ($rows as $row) {
            $row = ValidatedData::object($row);
            $serverId = ValidatedData::integer($row['server_id'] ?? null);
            $nodeId = ValidatedData::integer($row['node_id'] ?? null);
            $snapshot = ValidatedData::object($row['intent'] ?? null);
            $driver = DatabaseDriver::from(ValidatedData::string($snapshot['driver']));
            $container = ValidatedData::string($snapshot['container']);
            $server = DatabaseServer::query()->findOrFail($serverId);
            $run = DatabaseDestroyRun::render($server, $container, app(DatabaseStatements::class)->destroy($driver,
                ValidatedData::stringList($snapshot['databases']), ValidatedData::stringList($snapshot['users'])), "{$intentId}-{$serverId}", $push);
            $servers[] = ['server_id' => $serverId, 'node_id' => $nodeId, 'intent' => $run['intent']];
            foreach ($run['execution_secrets'] as $batch => $copy) {
                $copies["server_{$serverId}_{$batch}"] = $copy;
            }
        }

        return [$servers, $copies];
    }
}
