<?php

declare(strict_types=1);

namespace Orbit\Sdk\Laravel\Responses\Workspaces;

final readonly class WorkspaceStepListResponse
{
    /**
     * @param  list<array{id: int, project: string, instance: string, phase: string, order: int, command: string, timeout_seconds: int}>  $steps
     */
    public function __construct(
        public array $steps,
    ) {}
}
