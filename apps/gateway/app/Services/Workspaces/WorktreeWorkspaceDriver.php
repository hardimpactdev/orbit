<?php

declare(strict_types=1);

namespace App\Services\Workspaces;

use App\Data\Workspaces\WorkspaceProvisionResult;
use App\Exceptions\WorkspaceCreateFailed;
use App\Models\App;
use App\Models\Instance;
use App\Models\Node;
use App\Services\RemoteShell\RunsInternalCommands;

final readonly class WorktreeWorkspaceDriver
{
    public function __construct(
        private RunsInternalCommands $localExecutor,
        private WorkspacePlacement $placement = new WorkspacePlacement,
    ) {}

    public function create(
        App $app,
        Node $node,
        string $name,
        string $base,
        ?Instance $instance = null,
    ): WorkspaceProvisionResult {
        $path = $this->workspacePath($app, $name, $instance);
        $result = $this->localExecutor->runInternal(
            node: $node,
            commandName: 'internal:workspace-source:create',
            arguments: [
                rtrim($this->placement->runtimePath($app, $instance), '/'),
                $name,
                $base,
            ],
            transportOptions: [
                'redact_stdout' => true,
                'redact_stderr' => true,
                'strict' => false,
                'timeout' => 300,
            ],
        );

        if (! $result->successful()) {
            $output = trim($result->output());

            throw new WorkspaceCreateFailed(
                'workspace.source_create_failed',
                $output !== '' ? "Failed to create git worktree: {$output}" : 'Failed to create git worktree.',
                [
                    'driver' => 'worktree',
                    'node' => $node->name,
                    'app' => $app->name,
                    'workspace' => $name,
                    'path' => $path,
                ],
            );
        }

        return new WorkspaceProvisionResult(
            name: $name,
            path: $path,
        );
    }

    private function workspacePath(App $app, string $workspaceName, ?Instance $instance = null): string
    {
        return rtrim($this->placement->runtimePath($app, $instance), '/').'/.worktrees/'.$workspaceName;
    }
}
