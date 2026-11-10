<?php

declare(strict_types=1);

namespace App\Data\Instances;

use App\Data\DatabaseServers\DatabaseServerIdentityData;
use App\Data\Nodes\NodeIdentityData;
use App\Domain\DatabaseConnections\DatabaseDriver;
use App\Models\DatabaseConnectionTarget;
use Spatie\LaravelData\Data;

final class InstanceAttachedTargetData extends Data
{
    public function __construct(
        public string $kind,
        public int $id,
        public string $name,
        public string $driver,
        public ?NodeIdentityData $node,
        public ?string $host,
        public ?int $port,
        public ?string $database,
        public ?string $username,
        public string $prefix,
        public ?DatabaseServerIdentityData $server,
    ) {}

    public static function fromModel(DatabaseConnectionTarget $target): self
    {
        $connection = $target->databaseConnection;
        $server = $connection->server;
        $node = $server->node ?? $connection->node;

        return new self(
            kind: match ($connection->driver) {
                DatabaseDriver::Mysql, DatabaseDriver::Pgsql, DatabaseDriver::Sqlite => 'database',
                DatabaseDriver::Redis => 'redis',
            },
            id: $connection->id,
            name: $connection->slug,
            driver: $connection->driver->value,
            node: $node === null ? null : NodeIdentityData::fromModel($node),
            host: $connection->host,
            port: $connection->port,
            database: $connection->database,
            username: $connection->username,
            prefix: $target->prefix,
            server: $server === null ? null : DatabaseServerIdentityData::fromModel($server),
        );
    }
}
