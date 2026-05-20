<?php

declare(strict_types=1);

namespace App\Services\Workspaces;

use App\Contracts\RemoteShell;
use App\Exceptions\WorkspaceCreateFailed;
use App\Models\Node;

final readonly class PolyscopeWorkspaceBranchAligner
{
    public function __construct(
        private RemoteShell $remoteShell,
    ) {}

    public function align(Node $node, string $workspaceId, string $path, string $name): void
    {
        $result = $this->remoteShell->run($node, $this->script(), [
            'metadata' => [
                'ORBIT_POLYSCOPE_WORKSPACE_ID' => $workspaceId,
                'ORBIT_POLYSCOPE_WORKSPACE_PATH' => $path,
                'ORBIT_WORKSPACE_NAME' => $name,
            ],
            'timeout' => 30,
        ]);

        if ($result->successful()) {
            return;
        }

        throw new WorkspaceCreateFailed(
            'workspace.agent_ide_create_failed',
            'Polyscope workspace was created but could not be renamed.',
            [
                'adapter' => 'polyscope',
                'node' => $node->name,
                'workspace_id' => $workspaceId,
                'path' => $path,
                'workspace' => $name,
                'reason' => trim($result->stderr) ?: trim($result->stdout),
            ],
        );
    }

    private function script(): string
    {
        return <<<'SH'
python3 - <<'PY'
import json
import os
import pathlib
import sqlite3
import subprocess
import sys

workspace_id = os.environ.get("ORBIT_POLYSCOPE_WORKSPACE_ID", "")
workspace_path = os.environ.get("ORBIT_POLYSCOPE_WORKSPACE_PATH", "")
workspace_name = os.environ.get("ORBIT_WORKSPACE_NAME", "")
database_path = pathlib.Path.home() / ".polyscope" / "polyscope.db"

if not workspace_id or not workspace_path or not workspace_name:
    print("Polyscope workspace id, path, and target name are required.", file=sys.stderr)
    sys.exit(2)

if not pathlib.Path(workspace_path).is_dir():
    print(f"Polyscope workspace path is missing: {workspace_path}", file=sys.stderr)
    sys.exit(2)

if not database_path.exists():
    print(f"Polyscope database is missing: {database_path}", file=sys.stderr)
    sys.exit(2)

current_branch = subprocess.check_output(
    ["git", "-C", workspace_path, "branch", "--show-current"],
    text=True,
).strip()

if current_branch != workspace_name:
    existing = subprocess.run(
        ["git", "-C", workspace_path, "rev-parse", "--verify", "--quiet", workspace_name],
        stdout=subprocess.DEVNULL,
        stderr=subprocess.DEVNULL,
        check=False,
    )

    if existing.returncode == 0:
        print(f"Git branch already exists: {workspace_name}", file=sys.stderr)
        sys.exit(2)

    subprocess.run(
        ["git", "-C", workspace_path, "branch", "-m", workspace_name],
        check=True,
    )

connection = sqlite3.connect(database_path)
try:
    cursor = connection.execute(
        "update worktrees set branch = ?, branch_renamed = 1 where id = ?",
        (workspace_name, workspace_id),
    )
    connection.commit()
finally:
    connection.close()

if cursor.rowcount != 1:
    print(f"Polyscope worktree row not found: {workspace_id}", file=sys.stderr)
    sys.exit(2)

print(json.dumps({"branch": workspace_name}))
PY
SH;
    }
}
