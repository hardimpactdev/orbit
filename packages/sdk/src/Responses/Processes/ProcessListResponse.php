<?php

declare(strict_types=1);

namespace Orbit\Sdk\Laravel\Responses\Processes;

final readonly class ProcessListResponse
{
    /**
     * @param  array{node?: string|null, instance?: string|null, workspace?: string|null}  $context
     * @param  list<array<string, mixed>>  $processes
     */
    public function __construct(
        public array $context,
        public array $processes,
    ) {}
}
