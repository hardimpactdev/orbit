<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\WorkspaceLifecyclePhase;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;

#[Signature('workspace-setup-step:remove
    {--step= : Step ID to remove}
    {--app= : Parent app slug}
    {--force : Skip interactive confirmation}
    {--json : Output JSON}')]
#[Description('Remove a workspace setup step')]
class WorkspaceSetupStepRemoveCommand extends AbstractWorkspaceStepRemoveCommand
{
    protected function phase(): WorkspaceLifecyclePhase
    {
        return WorkspaceLifecyclePhase::Setup;
    }

    protected function phaseLabel(): string
    {
        return 'setup';
    }
}
