# workspace:remove overview

Removes a project from a workspace by deleting its symlink.

- Validates workspace exists and project is in workspace
- Removes symlink (does not delete actual project)
- Regenerates .code-workspace file
- Updates CLAUDE.md with updated project list

Failure and recovery paths

- Fails if workspace does not exist
- Fails if project symlink not found in workspace

Inputs and options

- workspace (required): Name of the workspace
- project (required): Name of the project to remove
- --json: Output as JSON

Key integrations

- WorkspaceService for symlink removal and file updates
