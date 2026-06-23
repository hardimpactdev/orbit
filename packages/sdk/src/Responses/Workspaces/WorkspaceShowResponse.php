<?php

declare(strict_types=1);

namespace Orbit\Sdk\Laravel\Responses\Workspaces;

final readonly class WorkspaceShowResponse
{
    /**
     * @param  array<string, mixed>  $workspace
     */
    public function __construct(
        public array $workspace,
    ) {}
}
