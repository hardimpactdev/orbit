<?php

declare(strict_types=1);

namespace App\Infrastructure\DatabaseConnections;

use App\Domain\DatabaseConnections\DatabaseDriver;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\DatabaseConnection;
use App\Models\Node;

/** Pure admitted wire values. Swamp owns every database inspection and SQL effect. */
final readonly class DatabaseConnectionInput
{
    public function dsn(DatabaseConnection $connection): string
    {
        return match ($connection->driver) {
            DatabaseDriver::Mysql => sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                $connection->host ?? '',
                $connection->port ?? 3306,
                $connection->database ?? '',
            ),
            DatabaseDriver::Pgsql => sprintf(
                'pgsql:host=%s;port=%d;dbname=%s',
                $connection->host ?? '',
                $connection->port ?? 5432,
                $connection->database ?? '',
            ),
            DatabaseDriver::Sqlite => 'sqlite:'.$connection->path,
            DatabaseDriver::Redis => throw new ResourceOperationException(
                errorCode: 'database.driver_unsupported',
                message: 'The redis driver does not support this inspection.',
                status: 422,
            ),
        };
    }

    /** @return array{owner: string, path: string|null, sql: string} */
    public function sqlite(DatabaseConnection $connection, string $sql): array
    {
        $node = $this->owningNode($connection);

        return ['owner' => $this->executionOwner($connection, $node), 'path' => $connection->path, 'sql' => $sql];
    }

    private function executionOwner(DatabaseConnection $connection, Node $node): string
    {
        if (! $node->roles->contains('role', RoleName::AppProd)
            || $node->roles->contains('role', RoleName::AppDev)) {
            return $node->user;
        }

        $owner = $connection->ownerInstance;

        if ($owner !== null && $owner->node_id === $node->id
            && is_string($owner->production_user) && $owner->production_user !== '') {
            return $owner->production_user;
        }

        $path = $connection->path ?? '';

        // Do not infer ownership from a path that escapes its apparent home.
        if (! in_array('..', explode('/', $path), true)) {
            foreach ($node->instances()->whereNotNull('production_home')->whereNotNull('production_user')->get() as $instance) {
                $home = rtrim($instance->production_home ?? '', '/');

                if ($home !== '' && $instance->production_user !== ''
                    && str_starts_with($path, $home.'/')) {
                    return (string) $instance->production_user;
                }
            }
        }

        return $node->user;
    }

    private function owningNode(DatabaseConnection $connection): Node
    {
        if ($connection->node_id === null) {
            throw new ResourceOperationException(
                errorCode: 'database.sqlite_node_required',
                message: "SQLite connection [{$connection->slug}] requires an associated Node.",
            );
        }

        $node = $connection->node ?? Node::query()->find($connection->node_id);

        if (! $node instanceof Node) {
            throw new ResourceOperationException(
                errorCode: 'database.node_missing',
                message: "Database connection [{$connection->slug}] names a Node that is gone.",
                status: 422,
            );
        }

        if ($node->status !== LifecycleStatus::Active) {
            throw new ResourceOperationException(
                errorCode: 'database.sqlite_node_inactive',
                message: "SQLite connection [{$connection->slug}] requires an active Node.",
            );
        }

        if (! is_string($node->wireguard_ip) || $node->wireguard_ip === '') {
            throw new ResourceOperationException(
                errorCode: 'database.wireguard_ip_missing',
                message: "Node [{$node->name}] has no WireGuard address.",
            );
        }

        if (! is_string($connection->path) || $connection->path === '') {
            throw new ResourceOperationException(
                errorCode: 'database.query_failed',
                message: "Database connection [{$connection->slug}] query failed.",
                status: 502,
            );
        }

        return $node;
    }
}
