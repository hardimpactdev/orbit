<?php

declare(strict_types=1);

namespace App\Data\DatabaseServers;

use App\Domain\DatabaseConnections\DatabaseDriver;
use Spatie\LaravelData\Data;

final class CreateDatabaseServerData extends Data
{
    public function __construct(
        public string $slug,
        public int $nodeId,
        public DatabaseDriver $driver,
        public string $tag,
        public int $port,
    ) {}
}
