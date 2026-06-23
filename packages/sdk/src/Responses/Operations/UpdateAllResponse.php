<?php

declare(strict_types=1);

namespace Orbit\Sdk\Laravel\Responses\Operations;

final readonly class UpdateAllResponse
{
    /**
     * @param  list<array<string, mixed>>  $updates
     * @param  array<string, mixed>  $summary
     */
    public function __construct(
        public array $updates,
        public array $summary,
    ) {}
}
