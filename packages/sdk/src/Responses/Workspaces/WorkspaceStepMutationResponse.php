<?php

declare(strict_types=1);

namespace Orbit\Sdk\Laravel\Responses\Workspaces;

final readonly class WorkspaceStepMutationResponse
{
    /**
     * @param  array{action: string}  $result
     * @param  array{id: int, app: string, app_instance: string, phase: string, order: int, command: string, timeout_seconds: int}  $step
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public array $result,
        public array $step,
        public array $meta = [],
    ) {}
}
