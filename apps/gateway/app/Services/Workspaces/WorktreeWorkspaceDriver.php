<?php

declare(strict_types=1);

namespace App\Services\Workspaces;

use App\Data\Workspaces\WorkspaceProvisionResult;
use App\Exceptions\WorkspaceCreateFailed;
use App\Models\Node;
use App\Models\Project;
use App\Services\RemoteShell\RunsInternalCommands;

final readonly class WorktreeWorkspaceDriver
{
    public function __construct(
        private RunsInternalCommands $localExecutor,
    ) {}

    public function create(Project $app, Node $node, string $name, string $base): WorkspaceProvisionResult
    {
        $path = $this->workspacePath($app, $name);
        $result = $this->localExecutor->runInternal(
            node: $node,
            commandName: 'internal:workspace-source:create',
            arguments: [
                rtrim($app->path, '/'),
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

    private function workspacePath(Project $app, string $workspaceName): string
    {
        return rtrim($app->path, '/').'/.worktrees/'.$workspaceName;
    }
}
