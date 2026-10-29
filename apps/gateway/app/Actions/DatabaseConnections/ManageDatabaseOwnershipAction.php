<?php

declare(strict_types=1);

namespace App\Actions\DatabaseConnections;

use App\Domain\Broadcasting\RecordEventBroadcaster;
use App\Domain\Broadcasting\RecordEventType;
use App\Domain\DatabaseConnections\DatabaseDriver;
use App\Domain\DatabaseServers\DatabaseRemovalLock;
use App\Domain\DatabaseServers\DatabaseServerAdmin;
use App\Domain\DatabaseServers\MysqlStatements;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Activity;
use App\Models\DatabaseConnection;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Operator-only recovery. Reads live grants for adoption; forgetting performs no Node work. */
final readonly class ManageDatabaseOwnershipAction
{
    public function __construct(private DatabaseServerAdmin $admin, private MysqlStatements $sql, private DatabaseRemovalLock $lock, private RecordEventBroadcaster $events) {}

    public function execute(string $action, DatabaseConnection $connection, string $actor, string $reason): void
    {
        $actor = trim($actor);
        $reason = trim($reason);
        if (! in_array($action, ['adopt', 'forget'], true) || $actor === '' || strlen($actor) > 100 || $reason === '' || strlen($reason) > 500
            || preg_match('/[\x00\r\n]/', $actor.$reason)) {
            throw new ResourceOperationException('validation.failed', 'Choose adopt or forget and supply an operator and reason.', 422);
        }
        $ids = $connection->database_server_id === null ? [] : [$connection->database_server_id];
        $this->lock->run($ids, function () use ($action, $connection, $actor, $reason): void {
            DB::transaction(function () use ($action, $connection, $actor, $reason): void {
                $connection->refresh();
                $this->assertIdle($connection);
                $receipt = $action === 'adopt' ? $this->adopt($connection, $actor, $reason) : null;
                Activity::query()->create(['request_id' => (string) Str::uuid(), 'command' => 'database:ownership:'.$action,
                    'description' => 'Operator '.$action.' for Database connection ['.$connection->slug.'].',
                    'subject_type' => DatabaseConnection::class, 'subject_id' => $connection->id, 'status' => 'succeeded', 'exit_code' => 0,
                    'properties' => ['actor' => $actor, 'reason' => $reason, 'connection_id' => $connection->id, 'slug' => $connection->slug, 'receipt' => $receipt]]);
                if ($action === 'forget') {
                    $connection->targets()->delete();
                    $connection->delete();
                    $this->events->broadcast(RecordEventType::DatabaseDeleted, $connection->id, ['id' => $connection->id, 'slug' => $connection->slug]);
                }
            });
        });
    }

    /** @return array{actor:string,reason:string,verified_at:string,databases:list<string>,prefix:?string,username:string,server_id:int,node_id:int,digest:string} */
    private function adopt(DatabaseConnection $connection, string $actor, string $reason): array
    {
        $server = $connection->server;
        if ($server === null || $server->driver !== DatabaseDriver::Mysql || $connection->driver !== DatabaseDriver::Mysql
            || $connection->database === null || $connection->username === null) {
            throw new ResourceOperationException('database.driver_unsupported', 'Ownership adoption requires a managed MySQL connection.', 422);
        }
        $query = $this->sql->ownershipProof($connection->database, $connection->username, $connection->test_database);
        if ($this->admin->execute($server, $query) !== ['owned']) {
            throw new ResourceOperationException('database.ownership_unproven', 'The recorded account, database and full database grants must exist before adoption.', 409);
        }
        $names = $this->admin->execute($server, $this->sql->databasesNamed($connection->database, $connection->test_database));
        if (! in_array($connection->database, $names, true) || array_any($names, fn (string $name): bool => $name !== $connection->database
            && ($connection->test_database === null || ! str_starts_with($name, $connection->test_database)))) {
            throw new ResourceOperationException('database.ownership_unproven', 'The live names do not match the recorded database and test prefix.', 409);
        }
        $receipt = ['actor' => $actor, 'reason' => $reason, 'verified_at' => now()->toIso8601String(),
            'databases' => array_values(array_unique($names)), 'prefix' => $connection->test_database,
            'username' => $connection->username, 'server_id' => $server->id, 'node_id' => $server->node_id,
            'digest' => hash('sha256', $query."\0".implode("\0", $names))];
        $connection->forceFill(['ownership_adoption' => $receipt])->save();

        return $receipt;
    }

    private function assertIdle(DatabaseConnection $connection): void
    {
        foreach (Operation::query()->whereNotIn('status', ['succeeded', 'failed', 'cancelled'])->cursor() as $operation) {
            if (($operation->target_type === 'database_connection' && $operation->target_id === $connection->id)
                || ($operation->target_type === 'database_server' && $operation->target_id === $connection->database_server_id)
                || ($operation->target_type === 'instance' && $operation->target_id === $connection->owner_instance_id)
                || ($operation->input['connection_id'] ?? null) === $connection->id
                || in_array($connection->id, ValidatedData::integerList($operation->input['connection_ids'] ?? []), true)) {
                throw new ResourceOperationException('database.connection_busy', 'Finish the connection or Instance Operation before operator recovery.', 409);
            }
        }
    }
}
