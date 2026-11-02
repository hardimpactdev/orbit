<?php

declare(strict_types=1);

namespace App\Actions\DatabaseConnections;

use App\Domain\DatabaseConnections\DatabaseDriver;
use App\Domain\Shared\ResourceOperationException;
use App\Models\DatabaseConnection;
use App\Models\Instance;

/** The databases and SQLite files a removal set of Instances owns, including databases on other Nodes.
 * @phpstan-type Server array{server_id:int,node_id:int,intent:array<string,mixed>}
 * @phpstan-type Sqlite array{connection_id:int,node_id:int,checkout:string,path:string}
 */
final readonly class DropOwnedDatabasesAction
{
    public function __construct(private DropServerDatabaseAction $servers) {}

    /** @param  list<Instance>  $owners
     * @return array{connection_ids:list<int>,servers:list<Server>,sqlite:list<Sqlite>}
     */
    public function snapshot(array $owners): array
    {
        $ownerIds = array_map(static fn (Instance $owner): int => $owner->id, $owners);
        $instances = array_column(array_map(static fn (Instance $owner): array => ['id' => $owner->id, 'instance' => $owner], $owners), 'instance', 'id');
        $connections = DatabaseConnection::query()->with(['server.node', 'users'])->whereIn('owner_instance_id', $ownerIds)->orderBy('id')->get();
        if ($connections->count() > 1000) {
            throw new ResourceOperationException('database.removal_conflict', 'The owned database set exceeds the native bound.', 409);
        }
        $managed = $connections->filter(static fn (DatabaseConnection $connection): bool => $connection->database_server_id !== null)->values()->all();
        $servers = [];
        foreach ($this->servers->snapshots(array_values($managed), ownerInstanceIds: $ownerIds) as $snapshot) {
            $servers[] = ['server_id' => $snapshot['server_id'], 'node_id' => $snapshot['node_id'], 'intent' => $snapshot['input']];
        }
        $sqlite = [];
        foreach ($connections as $connection) {
            if ($connection->targets()->whereNotIn('instance_id', $ownerIds)->exists()) {
                throw new ResourceOperationException('database.connection_attached', 'An owned database is attached outside the removal set.', 409);
            }
            if ($connection->database_server_id === null && $connection->driver === DatabaseDriver::Sqlite) {
                $owner = $instances[$connection->owner_instance_id ?? 0] ?? null;
                if (! $owner instanceof Instance) {
                    throw new ResourceOperationException('database.ownership_unproven', 'The SQLite owner is outside the admitted set.', 409);
                }
                $path = $connection->path;
                $checkout = $owner->checkout_path;
                if ($connection->node_id !== $owner->node_id || ! is_string($path)
                    || ! str_starts_with($path, $checkout.'/') || preg_match('#[\x00-\x1f\x7f]|(?:^|/)\.{1,2}(?:/|$)|//#', $path)) {
                    throw new ResourceOperationException('database.removal_conflict', 'Owned SQLite must stay inside its recorded checkout and Node.', 409);
                }
                $sqlite[] = ['connection_id' => $connection->id, 'node_id' => $owner->node_id, 'checkout' => $checkout, 'path' => $path];
            }
        }

        return ['connection_ids' => array_map(static fn (DatabaseConnection $connection): int => $connection->id, array_values($connections->all())), 'servers' => $servers, 'sqlite' => $sqlite];
    }
}
