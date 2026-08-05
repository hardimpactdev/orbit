<?php

declare(strict_types=1);

namespace App\Commands\Workspace;

final class WorkspaceSetupStepRemoveCommand extends AbstractWorkspaceStepRemoveCommand
{
    #[\Override]
    protected $signature = 'workspace-setup-step:remove
        {--step= : Step ID to remove}
        {--instance= : Instance selector (app.instance)}
        {--force : Skip interactive confirmation}
        {--json : Output JSON}';

    #[\Override]
    protected $description = 'Remove a workspace setup step from an instance.';

    protected function phase(): string
    {
        return 'setup';
    }

    protected function phaseLabel(): string
    {
        return 'setup';
    }
}
