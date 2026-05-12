<?php

declare(strict_types=1);

namespace App\Services\Workspaces;

use App\Contracts\OpenCodeClientFactory;
use App\Contracts\RemoteShell;
use App\Contracts\WorkspaceSourceDriver;
use App\Data\Workspaces\WorkspaceProvisionResult;
use App\Exceptions\WorkspaceCreateFailed;
use App\Models\App;
use App\Models\Node;
use HardImpact\OpenCode\Data\Project;
use HardImpact\OpenCode\OpenCode;
use Throwable;

final readonly class OpenCodeWorkspaceDriver implements WorkspaceSourceDriver
{
    public function __construct(
        private WorktreeWorkspaceDriver $worktreeDriver,
        private OpenCodeClientFactory $clientFactory,
        private RemoteShell $remoteShell,
    ) {}

    public function create(App $app, Node $node, string $name, string $base): WorkspaceProvisionResult
    {
        $client = $this->clientFactory->forApp($app);
        $project = $this->currentProject($client, $app, $node);
        $worktree = $this->worktreeDriver->create($app, $node, $name, $base);

        try {
            $this->registerSandbox($client, $app, $project, $worktree->path);
            $sessionId = $this->createSession($client, $name, $worktree->path);
        } catch (Throwable $exception) {
            $this->cleanupWorktree($node, $worktree->path);

            throw new WorkspaceCreateFailed(
                'workspace.agent_ide_create_failed',
                'OpenCode could not register the workspace.',
                [
                    'adapter' => 'opencode',
                    'node' => $node->name,
                    'app' => $app->name,
                    'workspace' => $name,
                    'path' => $worktree->path,
                    'reason' => $exception->getMessage(),
                ],
            );
        }

        return new WorkspaceProvisionResult(
            name: $worktree->name,
            path: $worktree->path,
            agentIde: 'opencode',
            agentIdeWorkspaceId: $sessionId,
        );
    }

    private function currentProject(OpenCode $client, App $app, Node $node): Project
    {
        try {
            return $client->projects()->current(directory: $app->path);
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

    private function registerSandbox(OpenCode $client, App $app, Project $project, string $path): Project
    {
        $sandboxes = array_values(array_unique([
            ...$project->sandboxes,
            $path,
        ]));

        return $client->projects()->update(
            id: $project->id,
            sandboxes: $sandboxes,
            directory: $app->path,
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

    private function cleanupWorktree(Node $node, string $path): void
    {
        $this->remoteShell->run(
            $node,
            sprintf(
                <<<'SH'
set -Eeuo pipefail
git worktree remove %1$s --force 2>/dev/null || rm -rf %1$s
SH,
                escapeshellarg($path),
            ),
            ['timeout' => 300, 'throw' => false],
        );
    }
}
