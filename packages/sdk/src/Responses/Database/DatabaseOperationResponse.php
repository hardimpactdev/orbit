<?php

declare(strict_types=1);

namespace Orbit\Sdk\Laravel\Responses\Database;

final readonly class DatabaseOperationResponse
{
    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public array $data,
        public array $meta,
    ) {}
}
