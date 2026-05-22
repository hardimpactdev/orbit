<?php

declare(strict_types=1);

namespace App\Http\Gateway\Responses\Workspaces;

final readonly class WorkspaceExecResponse
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        public array $data,
    ) {}
}
