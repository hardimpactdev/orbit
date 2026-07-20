<?php

declare(strict_types=1);

namespace Orbit\Sdk\Laravel\Responses\Projects;

final readonly class ProjectListResponse
{
    /**
     * @param  list<array<string, mixed>>  $projects
     */
    public function __construct(
        public array $projects,
    ) {}
}
