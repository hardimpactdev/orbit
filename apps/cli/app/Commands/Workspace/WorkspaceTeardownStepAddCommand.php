<?php

declare(strict_types=1);

namespace App\Commands\Workspace;

final class WorkspaceTeardownStepAddCommand extends AbstractWorkspaceStepAddCommand
{
    #[\Override]
    protected $signature = 'workspace-teardown-step:add
        {--command= : Shell command to run during workspace teardown}
        {--instance= : Instance selector (app.instance)}
        {--before= : Insert before this teardown step id}
        {--after= : Insert after this teardown step id}
        {--timeout=600 : Timeout in seconds}
        {--json : Output JSON}';

    #[\Override]
    protected $description = 'Add a workspace teardown step for an instance.';

    protected function phase(): string
    {
        return 'teardown';
    }

    protected function phaseLabel(): string
    {
        return 'teardown';
    }
}
