<?php

declare(strict_types=1);

namespace App\Services\AgentIde;

use App\Contracts\AgentIdeWorkspacePathResolver;
use App\Contracts\RemoteShell;
use App\Data\AgentIde\WorkspacePathResolution;
use App\Models\App;
use RuntimeException;

final readonly class CoreAgentIdeWorkspacePathResolver implements AgentIdeWorkspacePathResolver
{
    public function __construct(
        private RemoteShell $remoteShell,
    ) {}

    public function resolve(string $adapter, App $app, string $absolutePath): ?WorkspacePathResolution
    {
        return match ($adapter) {
            'opencode' => $this->resolveOpenCode($app, $absolutePath),
            'polyscope' => $this->resolvePolyscope($app, $absolutePath),
            default => null,
        };
    }

    private function resolveOpenCode(App $app, string $absolutePath): ?WorkspacePathResolution
    {
        $app->loadMissing('node');

        if ($app->node === null) {
            return null;
        }

        $result = $this->remoteShell->run($app->node, $this->openCodeScript(), [
            'env' => [
                'ORBIT_WORKSPACE_PATH' => $absolutePath,
                'ORBIT_APP_PATH' => $app->path,
            ],
            'timeout' => 30,
        ]);

        if (! $result->successful()) {
            throw new RuntimeException(trim($result->stderr) ?: trim($result->stdout) ?: 'adapter_unreachable');
        }

        $decoded = json_decode(trim($result->stdout), true);

        if (! is_array($decoded) || ($decoded['match'] ?? null) !== true) {
            return null;
        }

        return new WorkspacePathResolution(
            workspaceName: $this->requiredString($decoded, 'workspace_name'),
            appSlug: $app->name,
            path: $this->requiredString($decoded, 'path'),
            adapterWorkspaceId: $this->requiredString($decoded, 'adapter_workspace_id'),
        );
    }

    private function resolvePolyscope(App $app, string $absolutePath): ?WorkspacePathResolution
    {
        $app->loadMissing('node');

        if ($app->node === null) {
            return null;
        }

        $result = $this->remoteShell->run($app->node, $this->polyscopeScript(), [
            'env' => [
                'ORBIT_WORKSPACE_PATH' => $absolutePath,
                'ORBIT_APP_PATH' => $app->path,
            ],
            'timeout' => 30,
        ]);

        if (! $result->successful()) {
            throw new RuntimeException(trim($result->stderr) ?: trim($result->stdout) ?: 'adapter_unreachable');
        }

        $decoded = json_decode(trim($result->stdout), true);

        if (! is_array($decoded) || ($decoded['match'] ?? null) !== true) {
            return null;
        }

        return new WorkspacePathResolution(
            workspaceName: $this->requiredString($decoded, 'workspace_name'),
            appSlug: $app->name,
            path: $this->requiredString($decoded, 'path'),
            adapterWorkspaceId: $this->requiredString($decoded, 'adapter_workspace_id'),
        );
    }

    private function openCodeScript(): string
    {
        return <<<'SH'
python3 - <<'PY'
import json, os, pathlib, sqlite3, sys
path = os.environ.get("ORBIT_WORKSPACE_PATH", "").rstrip("/")
app_path = os.environ.get("ORBIT_APP_PATH", "").rstrip("/")
db = pathlib.Path.home() / ".local/share/opencode/opencode.db"
if not path:
    print("path_missing", file=sys.stderr); sys.exit(2)
if not app_path:
    print("app_path_missing", file=sys.stderr); sys.exit(2)
if not db.exists():
    print(json.dumps({"match": False})); sys.exit(0)
conn = sqlite3.connect(db)
try:
    rows = conn.execute("""
        select workspace.id, workspace.name, workspace.branch, workspace.directory, project.worktree
        from workspace
        left join project on project.id = workspace.project_id
    """).fetchall()
finally:
    conn.close()
for row_id, name, branch, directory, project_path in rows:
    if (
        isinstance(directory, str)
        and isinstance(project_path, str)
        and path == directory.rstrip("/")
        and app_path == project_path.rstrip("/")
    ):
        print(json.dumps({
            "match": True,
            "workspace_name": branch or name,
            "path": directory.rstrip("/"),
            "adapter_workspace_id": row_id,
        }))
        sys.exit(0)
print(json.dumps({"match": False}))
PY
SH;
    }

    private function polyscopeScript(): string
    {
        return <<<'SH'
python3 - <<'PY'
import json, os, pathlib, sqlite3, sys
path = os.environ.get("ORBIT_WORKSPACE_PATH", "").rstrip("/")
app_path = os.environ.get("ORBIT_APP_PATH", "").rstrip("/")
db = pathlib.Path.home() / ".polyscope/polyscope.db"
if not path:
    print("path_missing", file=sys.stderr); sys.exit(2)
if not app_path:
    print("app_path_missing", file=sys.stderr); sys.exit(2)
if not db.exists():
    print(json.dumps({"match": False})); sys.exit(0)
conn = sqlite3.connect(db)
try:
    rows = conn.execute("""
        select worktrees.id, worktrees.branch, worktrees.path, repositories.path
        from worktrees
        left join repositories on repositories.id = worktrees.repo_id
    """).fetchall()
except sqlite3.Error:
    rows = conn.execute("select id, branch, path, null from workspaces").fetchall()
finally:
    conn.close()
for row_id, branch, workspace_path, repository_path in rows:
    if (
        isinstance(workspace_path, str)
        and isinstance(repository_path, str)
        and path == workspace_path.rstrip("/")
        and app_path == repository_path.rstrip("/")
    ):
        print(json.dumps({
            "match": True,
            "workspace_name": branch,
            "path": workspace_path.rstrip("/"),
            "adapter_workspace_id": row_id,
        }))
        sys.exit(0)
print(json.dumps({"match": False}))
PY
SH;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function requiredString(array $data, string $key): string
    {
        $value = $data[$key] ?? null;

        if (is_string($value) && $value !== '') {
            return $value;
        }

        throw new RuntimeException("invalid_response_{$key}");
    }
}
