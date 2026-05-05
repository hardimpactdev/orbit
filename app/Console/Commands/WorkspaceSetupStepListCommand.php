<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\WorkspaceLifecyclePhase;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;

#[Signature('workspace-setup-step:list
    {--app= : Parent app slug}
    {--json : Output JSON}')]
#[Description('List workspace setup steps for an app')]
class WorkspaceSetupStepListCommand extends AbstractWorkspaceStepListCommand
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
