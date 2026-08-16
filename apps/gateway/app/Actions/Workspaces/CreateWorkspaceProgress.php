<?php

declare(strict_types=1);

namespace App\Actions\Workspaces;

use App\Models\App;
use App\Models\Instance;

final readonly class CreateWorkspaceProgress
{
    public function __construct(
        private CreateWorkspace $createWorkspace,
    ) {}

    public function for(
        App $app,
        string $name,
        string $base,
        ?string $phpVersion,
        Instance $instance,
    ): CreateWorkspacePlan {
        return $this->createWorkspace->plan(
            $app,
            $name,
            $instance,
            $base,
            $phpVersion,
        );
    }
}
