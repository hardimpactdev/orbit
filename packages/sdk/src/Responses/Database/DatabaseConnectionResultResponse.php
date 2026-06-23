<?php

declare(strict_types=1);

namespace Orbit\Sdk\Laravel\Responses\Database;

final readonly class DatabaseConnectionResultResponse
{
    /**
     * @param  array<string, mixed>  $result
     */
    public function __construct(
        public array $result,
    ) {}
}
