<?php

declare(strict_types=1);

namespace App\Commands\Workspace;

final class WorkspaceSetupStepAddCommand extends AbstractWorkspaceStepAddCommand
{
    #[\Override]
    protected $signature = 'workspace-setup-step:add
        {--command= : Shell command to run during workspace setup}
        {--instance= : Instance selector (app.instance)}
        {--before= : Insert before this setup step id}
        {--after= : Insert after this setup step id}
        {--timeout=600 : Timeout in seconds}
        {--json : Output JSON}';

    #[\Override]
    protected $description = 'Add a workspace setup step for an instance.';

    protected function phase(): string
    {
        return 'setup';
    }

    protected function phaseLabel(): string
    {
        return 'setup';
    }
}
