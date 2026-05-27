<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\WorkspaceLifecyclePhase;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;

#[Signature('workspace-setup-step:add
    {--command= : Shell command to run during workspace setup}
    {--app= : Parent app slug}
    {--before= : Insert before this setup step id}
    {--after= : Insert after this setup step id}
    {--timeout=600 : Timeout in seconds}
    {--json : Output JSON}')]
#[Description('Add a workspace setup step for an app')]
class WorkspaceSetupStepAddCommand extends AbstractWorkspaceStepAddCommand
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
