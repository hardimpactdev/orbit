<?php

declare(strict_types=1);

namespace Orbit\Sdk\Laravel\Responses\Projects;

final readonly class ProjectShowResponse
{
    /**
     * @param  array<string, mixed>  $project
     * @param  array<string, mixed>  $details
     */
    public function __construct(
        public array $project,
        public array $details,
    ) {}
}
