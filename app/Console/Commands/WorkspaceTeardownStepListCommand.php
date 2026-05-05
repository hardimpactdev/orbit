<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\WorkspaceLifecyclePhase;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;

#[Signature('workspace-teardown-step:list
    {--app= : Parent app slug}
    {--json : Output JSON}')]
#[Description('List workspace teardown steps for an app')]
class WorkspaceTeardownStepListCommand extends AbstractWorkspaceStepListCommand
{
    protected function phase(): WorkspaceLifecyclePhase
    {
        return WorkspaceLifecyclePhase::Teardown;
    }

    protected function phaseLabel(): string
    {
        return 'teardown';
    }
}
