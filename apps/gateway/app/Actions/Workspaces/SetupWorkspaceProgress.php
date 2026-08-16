<?php

declare(strict_types=1);

namespace App\Actions\Workspaces;

use App\Models\App;
use App\Models\Node;
use App\Models\Workspace;

final readonly class SetupWorkspaceProgress
{
    public function __construct(
        private SetupWorkspace $setupWorkspace,
    ) {}

    public function for(Workspace $workspace, App $app, Node $node, bool $isAdoption): SetupWorkspacePlan
    {
        return $this->setupWorkspace->plan($app, $workspace, $node, $isAdoption);
    }
}
