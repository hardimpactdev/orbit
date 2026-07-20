<?php

declare(strict_types=1);

namespace App\Actions\Workspaces;

use App\Models\Node;
use App\Models\Project;
use App\Models\Workspace;
use App\Services\Workspaces\WorkspaceStepPolicyService;

final readonly class SetupWorkspaceProgress
{
    public function __construct(
        private SetupWorkspace $setupWorkspace,
        private WorkspaceStepPolicyService $stepPolicy,
    ) {}

    public function for(Workspace $workspace, Project $app, Node $node, bool $isAdoption): SetupWorkspaceProgressPlan
    {
        return new SetupWorkspaceProgressPlan(
            setupWorkspace: $this->setupWorkspace,
            workspace: $workspace,
            app: $app,
            node: $node,
            isAdoption: $isAdoption,
            stepPolicy: $this->stepPolicy,
        );
    }
}
