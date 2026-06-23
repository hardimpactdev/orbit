<?php

declare(strict_types=1);

namespace Orbit\Sdk\Laravel\Responses\Workspaces;

final readonly class WorkspaceRemoveResponse
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
