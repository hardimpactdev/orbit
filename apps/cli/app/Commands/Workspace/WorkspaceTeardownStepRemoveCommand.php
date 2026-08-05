<?php

declare(strict_types=1);

namespace App\Commands\Workspace;

final class WorkspaceTeardownStepRemoveCommand extends AbstractWorkspaceStepRemoveCommand
{
    #[\Override]
    protected $signature = 'workspace-teardown-step:remove
        {--step= : Step ID to remove}
        {--instance= : Instance selector (app.instance)}
        {--force : Skip interactive confirmation}
        {--json : Output JSON}';

    #[\Override]
    protected $description = 'Remove a workspace teardown step from an instance.';

    protected function phase(): string
    {
        return 'teardown';
    }

    protected function phaseLabel(): string
    {
        return 'teardown';
    }
}
