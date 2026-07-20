<?php

declare(strict_types=1);

namespace Orbit\Sdk\Laravel\Responses\Dashboard;

final readonly class RuntimeInventoryResponse
{
    /**
     * @param  list<array<string, mixed>>  $nodes
     * @param  list<array<string, mixed>>  $projects
     * @param  list<array<string, mixed>>  $processes
     * @param  list<array<string, mixed>>  $tools
     */
    public function __construct(
        public array $nodes,
        public array $projects,
        public array $processes,
        public array $tools,
    ) {}
}
