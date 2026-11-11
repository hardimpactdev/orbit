<?php

declare(strict_types=1);

namespace App\Data\DatabaseServers;

use App\Models\DatabaseServer;
use Spatie\LaravelData\Data;

final class DatabaseServerIdentityData extends Data
{
    public function __construct(
        public int $id,
        public string $name,
    ) {}

    public static function fromModel(DatabaseServer $server): self
    {
        return new self(id: $server->id, name: $server->slug);
    }
}
