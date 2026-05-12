<?php

declare(strict_types=1);

namespace App\Services\Workspaces;

use App\Contracts\RemoteShell;
use App\Contracts\WorkspaceSourceDriver;
use App\Data\Workspaces\WorkspaceProvisionResult;
use App\Exceptions\WorkspaceCreateFailed;
use App\Models\App;
use App\Models\Node;
use Polyscope\Laravel\Polyscope;
use Throwable;

final readonly class PolyscopeWorkspaceDriver implements WorkspaceSourceDriver
{
    public function __construct(
        private RemoteShell $remoteShell,
    ) {}

    public function create(App $app, Node $node, string $name, string $base): WorkspaceProvisionResult
    {
        $config = $this->resolveConfig($app, $node);
        $client = new Polyscope($config['api_token'], baseUrl: $config['base_url']);

        try {
            $workspace = $client->createWorkspace([
                'server_id' => $config['server_id'],
                'repository_id' => $config['repository_id'],
                'branch' => $name,
                'base_branch' => $base,
            ]);
        } catch (Throwable $exception) {
            throw new WorkspaceCreateFailed(
                'workspace.agent_ide_create_failed',
                'Polyscope could not create the workspace.',
                [
                    'adapter' => 'polyscope',
                    'node' => $node->name,
                    'app' => $app->name,
                    'reason' => $exception->getMessage(),
                ],
            );
        }

        if (! is_string($workspace->id) || $workspace->id === '') {
            throw new WorkspaceCreateFailed(
                'workspace.agent_ide_create_failed',
                'Polyscope did not return a workspace id.',
                ['adapter' => 'polyscope', 'node' => $node->name, 'app' => $app->name],
            );
        }

        if (! is_string($workspace->path) || $workspace->path === '') {
            throw new WorkspaceCreateFailed(
                'workspace.agent_ide_create_failed',
                'Polyscope did not return a workspace path.',
                ['adapter' => 'polyscope', 'node' => $node->name, 'app' => $app->name],
            );
        }

        return new WorkspaceProvisionResult(
            name: $name,
            path: $workspace->path,
            agentIde: 'polyscope',
            agentIdeWorkspaceId: $workspace->id,
        );
    }

    /**
     * @return array{api_token: string, server_id: string, repository_id: string, base_url: string|null}
     */
    private function resolveConfig(App $app, Node $node): array
    {
        $nodeConfig = is_array($node->agent_ide_config) ? $node->agent_ide_config : [];
        $appConfig = is_array($app->agent_ide_config) ? $app->agent_ide_config : [];
        $polyscopeNodeConfig = is_array($nodeConfig['polyscope'] ?? null) ? $nodeConfig['polyscope'] : [];
        $polyscopeAppConfig = is_array($appConfig['polyscope'] ?? null) ? $appConfig['polyscope'] : [];

        $config = [
            'api_token' => $this->stringValue($polyscopeNodeConfig['api_token'] ?? null)
                ?? $this->stringValue($polyscopeNodeConfig['api_key'] ?? null)
                ?? $this->stringValue($polyscopeNodeConfig['auth_token'] ?? null),
            'server_id' => $this->stringValue($polyscopeNodeConfig['server_id'] ?? null),
            'repository_id' => $this->stringValue($polyscopeAppConfig['repository_id'] ?? null),
            'base_url' => $this->stringValue($polyscopeNodeConfig['base_url'] ?? null),
        ];

        if ($config['api_token'] !== null && $config['server_id'] !== null && $config['repository_id'] !== null) {
            return $config;
        }

        $remoteConfig = $this->readRemoteConfig($app, $node);

        $config = [
            'api_token' => $config['api_token'] ?? $remoteConfig['api_token'],
            'server_id' => $config['server_id'] ?? $remoteConfig['server_id'],
            'repository_id' => $config['repository_id'] ?? $remoteConfig['repository_id'],
            'base_url' => $config['base_url'] ?? $remoteConfig['base_url'],
        ];

        if ($config['api_token'] === null || $config['server_id'] === null || $config['repository_id'] === null) {
            throw new WorkspaceCreateFailed(
                'workspace.agent_ide_not_configured',
                'Polyscope is not configured for this app node and repository.',
                [
                    'adapter' => 'polyscope',
                    'node' => $node->name,
                    'app' => $app->name,
                    'missing' => array_values(array_filter([
                        $config['api_token'] === null ? 'api_token' : null,
                        $config['server_id'] === null ? 'server_id' : null,
                        $config['repository_id'] === null ? 'repository_id' : null,
                    ])),
                ],
            );
        }

        return $config;
    }

    /**
     * @return array{api_token: string|null, server_id: string|null, repository_id: string|null, base_url: string|null}
     */
    private function readRemoteConfig(App $app, Node $node): array
    {
        $result = $this->remoteShell->run($node, $this->remoteConfigScript(), [
            'env' => ['ORBIT_APP_PATH' => $app->path],
            'timeout' => 30,
        ]);

        if (! $result->successful()) {
            throw new WorkspaceCreateFailed(
                'workspace.agent_ide_not_configured',
                'Polyscope configuration could not be read from the app node.',
                [
                    'adapter' => 'polyscope',
                    'node' => $node->name,
                    'app' => $app->name,
                    'reason' => trim($result->stderr) ?: trim($result->stdout),
                ],
            );
        }

        $decoded = json_decode(trim($result->stdout), true);

        if (! is_array($decoded)) {
            throw new WorkspaceCreateFailed(
                'workspace.agent_ide_not_configured',
                'Polyscope configuration returned by the app node was invalid.',
                ['adapter' => 'polyscope', 'node' => $node->name, 'app' => $app->name],
            );
        }

        return [
            'api_token' => $this->stringValue($decoded['api_token'] ?? null),
            'server_id' => $this->stringValue($decoded['server_id'] ?? null),
            'repository_id' => $this->stringValue($decoded['repository_id'] ?? null),
            'base_url' => $this->stringValue($decoded['base_url'] ?? null),
        ];
    }

    private function remoteConfigScript(): string
    {
        return <<<'SH'
python3 - <<'PY'
import json
import os
import pathlib
import sqlite3
import sys

app_path = os.environ.get("ORBIT_APP_PATH", "").rstrip("/")
home = pathlib.Path.home()
settings_path = home / ".polyscope" / "settings.json"
database_path = home / ".polyscope" / "polyscope.db"

if not app_path:
    print("ORBIT_APP_PATH is missing", file=sys.stderr)
    sys.exit(2)

if not settings_path.exists():
    print(f"Polyscope settings file is missing: {settings_path}", file=sys.stderr)
    sys.exit(2)

if not database_path.exists():
    print(f"Polyscope database is missing: {database_path}", file=sys.stderr)
    sys.exit(2)

settings = json.loads(settings_path.read_text())
api_token = settings.get("authToken")
server_id = settings.get("serverId")
base_url = settings.get("backendUrl") or settings.get("backend")

connection = sqlite3.connect(database_path)
try:
    rows = connection.execute("select id, path from repositories").fetchall()
finally:
    connection.close()

repository_id = None
for row_id, row_path in rows:
    if isinstance(row_path, str) and row_path.rstrip("/") == app_path:
        repository_id = row_id
        break

print(json.dumps({
    "api_token": api_token,
    "server_id": server_id,
    "repository_id": repository_id,
    "base_url": base_url,
}))
PY
SH;
    }

    private function stringValue(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
