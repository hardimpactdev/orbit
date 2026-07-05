<?php

declare(strict_types=1);

namespace App\Commands\Internal;

use App\Services\Workspaces\LocalWorkspaceSourceCreateAction;
use App\Services\Workspaces\LocalWorkspaceSourceCreateFailure;

final class WorkspaceSourceCreateCommand extends InternalExecutorCommand
{
    #[\Override]
    protected $signature = 'internal:workspace-source:create {app-path} {workspace} {base} {--operation-token=} {--json}';

    #[\Override]
    protected $description = 'Create a local git worktree workspace through fixed argv operations';

    public function handle(LocalWorkspaceSourceCreateAction $action): int
    {
        if (! $this->verifyOperationToken('internal:workspace-source:create')) {
            return self::FAILURE;
        }

        try {
            return $this->emitInternalSuccess($action->create(
                appPath: $this->argument('app-path'),
                workspace: $this->argument('workspace'),
                base: $this->argument('base'),
            ));
        } catch (LocalWorkspaceSourceCreateFailure $failure) {
            return $this->renderFailure($failure->errorCode, $failure->getMessage(), $failure->meta);
        }
    }
}
