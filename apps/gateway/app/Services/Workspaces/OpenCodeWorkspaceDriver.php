<?php

declare(strict_types=1);

namespace App\Services\Workspaces;

use App\Contracts\OpenCodeClientFactory;
use App\Contracts\WorkspaceSourceDriver;
use App\Data\Workspaces\WorkspaceProvisionResult;
use App\Exceptions\WorkspaceCreateFailed;
use App\Models\App;
use App\Models\Node;
use App\Services\RemoteShell\RunsInternalCommands;
use HardImpact\OpenCode\Data\Worktree as OpenCodeWorktree;
use HardImpact\OpenCode\OpenCode;
use Throwable;

final readonly class OpenCodeWorkspaceDriver implements WorkspaceSourceDriver
{
    public function __construct(
        private OpenCodeClientFactory $clientFactory,
        private RunsInternalCommands $localExecutor,
    ) {}

    public function create(App $app, Node $node, string $name, string $base): WorkspaceProvisionResult
    {
        $client = $this->clientFactory->forApp($app);
        $this->currentProject($client, $app, $node);

        try {
            $worktree = $this->createOpenCodeWorktree($client, $app->path, $name);
            $path = $this->workspacePath($worktree->directory);
            $this->alignBranch($node, $path, $name, $base);
            $sessionId = $this->createSession($client, $name, $path);
        } catch (Throwable $exception) {
            if (isset($worktree)) {
                $this->cleanupWorktree($client, $worktree->directory, $app->path);
            }

            throw new WorkspaceCreateFailed(
                'workspace.agent_ide_create_failed',
                'OpenCode could not create the workspace.',
                [
                    'adapter' => 'opencode',
                    'node' => $node->name,
                    'app' => $app->name,
                    'workspace' => $name,
                    'path' => $worktree->directory ?? null,
                    'reason' => $exception->getMessage(),
                ],
            );
        }

        return new WorkspaceProvisionResult(
            name: $name,
            path: $path,
            agentIde: 'opencode',
            agentIdeWorkspaceId: $sessionId,
        );
    }

    private function createOpenCodeWorktree(OpenCode $client, string $projectPath, string $name): OpenCodeWorktree
    {
        $knownWorktreeDirectories = $client->worktrees()->list(directory: $projectPath);

        try {
            return $client->worktrees()->create(name: $name, directory: $projectPath);
        } catch (Throwable $exception) {
            $createdWorktrees = array_values(array_filter(
                $client->worktrees()->list(directory: $projectPath),
                fn (string $worktreeDirectory): bool => ! in_array($worktreeDirectory, $knownWorktreeDirectories, true),
            ));

            $worktreeDirectory = array_pop($createdWorktrees);

            if (is_string($worktreeDirectory) && $worktreeDirectory !== '') {
                return new OpenCodeWorktree(
                    name: $name,
                    branch: $name,
                    directory: $worktreeDirectory,
                );
            }

            throw $exception;
        }
    }

    private function currentProject(OpenCode $client, App $app, Node $node): void
    {
        try {
            $client->projects()->current(directory: $app->path);
        } catch (Throwable $exception) {
            throw new WorkspaceCreateFailed(
                'workspace.agent_ide_create_failed',
                'OpenCode could not resolve the app project.',
                [
                    'adapter' => 'opencode',
                    'node' => $node->name,
                    'app' => $app->name,
                    'path' => $app->path,
                    'reason' => $exception->getMessage(),
                ],
            );
        }
    }

    private function alignBranch(Node $node, string $path, string $name, string $base): void
    {
        $result = $this->localExecutor->runInternal(
            node: $node,
            commandName: 'internal:workspace-adapter:update',
            commandOptions: [
                'adapter' => 'opencode',
                'update' => 'host-branch',
                'workspace-path' => $path,
                'branch' => $name,
                'base-ref' => $base,
            ],
            transportOptions: [
                'redact_stdout' => true,
                'redact_stderr' => true,
                'strict' => false,
                'timeout' => 300,
            ],
        );

        if ($result->successful()) {
            return;
        }

        throw new WorkspaceCreateFailed(
            'workspace.agent_ide_create_failed',
            'OpenCode workspace was created but could not be aligned to the requested branch.',
            [
                'adapter' => 'opencode',
                'node' => $node->name,
                'workspace' => $name,
                'path' => $path,
                'base' => $base,
                'reason' => trim($result->stderr) ?: trim($result->stdout),
            ],
        );
    }

    private function createSession(OpenCode $client, string $name, string $path): ?string
    {
        try {
            return $client->sessions()->create(directory: $path, title: $name)->id;
        } catch (Throwable) {
            return null;
        }
    }

    private function cleanupWorktree(OpenCode $client, string $worktreeDirectory, string $projectPath): void
    {
        try {
            $client->worktrees()->remove($worktreeDirectory, directory: $projectPath);
        } catch (Throwable) {
            // Best-effort cleanup after OpenCode created a workspace but a later step failed.
        }
    }

    private function workspacePath(?string $path): string
    {
        if (is_string($path) && $path !== '') {
            return $path;
        }

        throw new WorkspaceCreateFailed(
            'workspace.agent_ide_create_failed',
            'OpenCode did not return a workspace path.',
            ['adapter' => 'opencode'],
        );
    }
}
