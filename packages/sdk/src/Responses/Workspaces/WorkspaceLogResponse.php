<?php

declare(strict_types=1);

namespace Orbit\Sdk\Laravel\Responses\Workspaces;

final readonly class WorkspaceLogResponse
{
    /**
     * @param  array<string, mixed>  $run
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public array $run,
        public array $meta = [],
    ) {}
}
