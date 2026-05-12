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
use HardImpact\OpenCode\Data\Workspace as OpenCodeWorkspace;
use HardImpact\OpenCode\OpenCode;
use Throwable;

final readonly class OpenCodeWorkspaceDriver implements WorkspaceSourceDriver
{
    public function __construct(
        private OpenCodeClientFactory $clientFactory,
        private RemoteShell $remoteShell,
    ) {}

    public function create(App $app, Node $node, string $name, string $base): WorkspaceProvisionResult
    {
        $client = $this->clientFactory->forApp($app);
        $this->currentProject($client, $app, $node);

        try {
            $workspace = $this->createOpenCodeWorkspace($client, $app->path, $base);
            $path = $this->workspacePath($workspace->directory);
            $this->alignBranch($node, $path, $name, $base);
            $this->syncWorkspaceMetadata($node, $workspace->id, $name);
            $sessionId = $this->createSession($client, $name, $path, $workspace->id);
        } catch (Throwable $exception) {
            if (isset($workspace)) {
                $this->cleanupWorkspace($client, $workspace->id, $app->path);
            }

            throw new WorkspaceCreateFailed(
                'workspace.agent_ide_create_failed',
                'OpenCode could not create the workspace.',
                [
                    'adapter' => 'opencode',
                    'node' => $node->name,
                    'app' => $app->name,
                    'workspace' => $name,
                    'path' => $workspace->directory ?? null,
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

    private function createOpenCodeWorkspace(OpenCode $client, string $projectPath, string $base): OpenCodeWorkspace
    {
        $knownWorkspaceIds = array_map(
            fn (OpenCodeWorkspace $workspace): string => $workspace->id,
            $client->workspaces()->list(directory: $projectPath),
        );

        try {
            return $client->workspaces()->create(type: 'worktree', branch: $base, directory: $projectPath);
        } catch (Throwable $exception) {
            $createdWorkspaces = array_values(array_filter(
                $client->workspaces()->list(directory: $projectPath),
                fn (OpenCodeWorkspace $workspace): bool => ! in_array($workspace->id, $knownWorkspaceIds, true),
            ));

            $workspace = array_pop($createdWorkspaces);

            if ($workspace instanceof OpenCodeWorkspace) {
                return $workspace;
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
        $result = $this->remoteShell->run(
            $node,
            $this->alignBranchScript(),
            [
                'env' => [
                    'ORBIT_WORKSPACE_PATH' => $path,
                    'ORBIT_WORKSPACE_NAME' => $name,
                    'ORBIT_WORKSPACE_BASE' => $base,
                ],
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

    private function alignBranchScript(): string
    {
        return <<<'SH'
set -Eeuo pipefail
workspace_path="${ORBIT_WORKSPACE_PATH:?}"
workspace_name="${ORBIT_WORKSPACE_NAME:?}"
base_ref="${ORBIT_WORKSPACE_BASE:?}"

if [ ! -d "$workspace_path/.git" ] && [ ! -f "$workspace_path/.git" ]; then
    echo "workspace path is not a git worktree: $workspace_path" >&2
    exit 2
fi

current_branch="$(git -C "$workspace_path" branch --show-current)"

if [ "$current_branch" != "$workspace_name" ]; then
    if git -C "$workspace_path" rev-parse --verify --quiet "$workspace_name" >/dev/null; then
        echo "git branch already exists: $workspace_name" >&2
        exit 2
    fi

    git -C "$workspace_path" branch -m "$workspace_name"
fi

git -C "$workspace_path" reset --hard "$base_ref"
SH;
    }

    private function syncWorkspaceMetadata(Node $node, string $workspaceId, string $name): void
    {
        $result = $this->remoteShell->run(
            $node,
            $this->syncWorkspaceMetadataScript(),
            [
                'env' => [
                    'ORBIT_OPENCODE_WORKSPACE_ID' => $workspaceId,
                    'ORBIT_WORKSPACE_NAME' => $name,
                ],
                'timeout' => 30,
            ],
        );

        if ($result->successful()) {
            return;
        }

        throw new WorkspaceCreateFailed(
            'workspace.agent_ide_create_failed',
            'OpenCode workspace was created but could not be named for the UI.',
            [
                'adapter' => 'opencode',
                'node' => $node->name,
                'workspace_id' => $workspaceId,
                'workspace' => $name,
                'reason' => trim($result->stderr) ?: trim($result->stdout),
            ],
        );
    }

    private function syncWorkspaceMetadataScript(): string
    {
        return <<<'SH'
set -Eeuo pipefail
workspace_id="${ORBIT_OPENCODE_WORKSPACE_ID:?}"
workspace_name="${ORBIT_WORKSPACE_NAME:?}"
database_path="${HOME}/.local/share/opencode/opencode.db"

if [ ! -f "$database_path" ]; then
    echo "OpenCode database is missing: $database_path" >&2
    exit 2
fi

php -r '
$database = new SQLite3(getenv("HOME")."/.local/share/opencode/opencode.db");
$statement = $database->prepare("update workspace set name = :name, branch = :branch where id = :id");
$statement->bindValue(":name", getenv("ORBIT_WORKSPACE_NAME"), SQLITE3_TEXT);
$statement->bindValue(":branch", getenv("ORBIT_WORKSPACE_NAME"), SQLITE3_TEXT);
$statement->bindValue(":id", getenv("ORBIT_OPENCODE_WORKSPACE_ID"), SQLITE3_TEXT);
$result = $statement->execute();
if ($result === false || $database->changes() !== 1) {
    fwrite(STDERR, "OpenCode workspace row not found: ".getenv("ORBIT_OPENCODE_WORKSPACE_ID").PHP_EOL);
    exit(2);
}
'
SH;
    }

    private function createSession(OpenCode $client, string $name, string $path, string $workspaceId): ?string
    {
        try {
            return $client->sessions()->create(directory: $path, title: $name, workspaceID: $workspaceId)->id;
        } catch (Throwable) {
            return null;
        }
    }

    private function cleanupWorkspace(OpenCode $client, string $workspaceId, string $projectPath): void
    {
        try {
            $client->workspaces()->remove($workspaceId, directory: $projectPath);
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
