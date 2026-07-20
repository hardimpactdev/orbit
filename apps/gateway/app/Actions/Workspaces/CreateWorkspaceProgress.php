<?php

declare(strict_types=1);

namespace App\Actions\Workspaces;

use App\Models\AppInstance;
use App\Models\Node;
use App\Models\Project;

final readonly class CreateWorkspaceProgress
{
    public function __construct(
        private CreateWorkspace $createWorkspace,
        private SetupWorkspace $setupWorkspace,
    ) {}

    public function for(
        Project $app,
        Node $node,
        string $name,
        string $base,
        ?string $phpVersion,
        AppInstance $instance,
    ): CreateWorkspaceProgressPlan {
        return new CreateWorkspaceProgressPlan(
            createWorkspace: $this->createWorkspace,
            setupWorkspace: $this->setupWorkspace,
            app: $app,
            node: $node,
            name: $name,
            base: $base,
            phpVersion: $phpVersion,
            instance: $instance,
        );
    }
}
