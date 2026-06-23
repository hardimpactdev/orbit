<?php

declare(strict_types=1);

namespace Orbit\Sdk\Laravel\Responses\Database;

final readonly class DatabaseConnectionResponse
{
    /**
     * @param  array<string, mixed>  $connection
     */
    public function __construct(
        public array $connection,
    ) {}
}
