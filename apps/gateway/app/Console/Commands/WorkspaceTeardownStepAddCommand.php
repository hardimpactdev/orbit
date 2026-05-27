<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\WorkspaceLifecyclePhase;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;

#[Signature('workspace-teardown-step:add
    {--command= : Shell command to run during workspace teardown}
    {--app= : Parent app slug}
    {--before= : Insert before this teardown step id}
    {--after= : Insert after this teardown step id}
    {--timeout=600 : Timeout in seconds}
    {--json : Output JSON}')]
#[Description('Add a workspace teardown step for an app')]
class WorkspaceTeardownStepAddCommand extends AbstractWorkspaceStepAddCommand
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
