<?php

declare(strict_types=1);

namespace App\Actions\DatabaseConnections;

use App\Domain\DatabaseConnections\DatabaseDriver;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentDelivery;
use App\Models\DatabaseConnection;
use App\Models\DatabaseConnectionTarget;
use App\Models\Instance;

/**
 * Public immutable artifact for one native DAG. The parent stores it once; no execution intent or child is created.
 *
 * @phpstan-type Connection array{connection_id:int,slug:string,driver:string,node_id:?int,path:?string}
 * @phpstan-type Artifact array{version:1,artifact_id:string,owner_instance_id:int,node_id:int,checkout:string,agent_sha256:string,connections:list<Connection>}
 */
final readonly class PrepareOwnedDatabaseDestructionAction
{
    /** @return Artifact */
    public function execute(Instance $target): array
    {
        $connections = $this->snapshot($target);

        return ['version' => 1, 'artifact_id' => bin2hex(random_bytes(32)), 'owner_instance_id' => $target->id,
            'node_id' => $target->node_id, 'checkout' => $target->checkout_path,
            'agent_sha256' => app(AgentDelivery::class)->pin($target->node), 'connections' => $connections];
    }

    /** @return list<Connection> */
    public function snapshot(Instance $target, bool $lock = false): array
    {
        if ($target->defaultAppEnv() !== 'development' || $target->node->platform !== 'linux' || ! $this->path($target->checkout_path)) {
            throw new ResourceOperationException('database.removal_conflict', 'Native database destruction requires its recorded development checkout.', 409);
        }
        $query = DatabaseConnection::query()->where('owner_instance_id', $target->id)->orderBy('id');
        $connections = ($lock ? $query->lockForUpdate() : $query)->get();
        if ($connections->count() > 1000) {
            throw new ResourceOperationException('database.removal_conflict', 'The owned database set exceeds the native artifact bound.', 409);
        }
        if (DatabaseConnectionTarget::query()->whereIn('database_connection_id', $connections->modelKeys())->where('instance_id', '!=', $target->id)->exists()) {
            throw new ResourceOperationException('database.removal_conflict', 'An owned database is attached to another Instance.', 409);
        }
        $snapshot = [];
        foreach ($connections as $connection) {
            // Even same-Node SQL stays closed until its owning native action and private material contract exist.
            if ($connection->database_server_id !== null) {
                throw new ResourceOperationException('database.native_removal_unavailable', 'Managed database destruction has no approved native artifact and private material wire.', 409);
            }
            if ($connection->driver === DatabaseDriver::Sqlite && ($connection->node_id !== $target->node_id
                || ! is_string($connection->path) || ! $this->path($connection->path)
                || ! str_starts_with($connection->path, $target->checkout_path.'/'))) {
                throw new ResourceOperationException('database.removal_conflict', 'The owned SQLite database is outside its recorded checkout or Node.', 409);
            }
            $snapshot[] = ['connection_id' => $connection->id, 'slug' => $connection->slug, 'driver' => $connection->driver->value,
                'node_id' => $connection->node_id, 'path' => $connection->path];
        }

        return $snapshot;
    }

    private function path(string $path): bool
    {
        return str_starts_with($path, '/') && strlen($path) > 1 && ! str_ends_with($path, '/')
            && ! preg_match('#[\x00-\x1f\x7f]|(?:^|/)\.{1,2}(?:/|$)|//#', $path);
    }
}
