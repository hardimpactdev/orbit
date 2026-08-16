<?php

declare(strict_types=1);

namespace App\Actions\Workspaces;

use App\Models\App;
use App\Models\Node;
use App\Models\Workspace;
use Throwable;

final readonly class SetupWorkspaceProgress
{
    public function __construct(
        private SetupWorkspace $setupWorkspace,
    ) {}

    public function for(Workspace $workspace, App $app, Node $node, bool $isAdoption): SetupWorkspacePlan
    {
        return $this->setupWorkspace->plan($app, $workspace, $node, $isAdoption);
    }

    public function planConstructionFailure(
        Workspace $workspace,
        Node $node,
        bool $isAdoption,
        Throwable $exception,
    ): SetupWorkspaceResult {
        return $this->setupWorkspace->unexpectedFailure(
            $workspace,
            $node,
            $isAdoption,
            $exception,
            phase: 'planning',
            reason: 'plan_construction_failed',
        );
    }
}
