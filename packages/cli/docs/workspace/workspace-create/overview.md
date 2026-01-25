# workspace:create overview

Creates a new workspace directory with initial scaffolding for grouping related projects.

- Creates a directory under ~/workspaces/{name}
- Generates initial CLAUDE.md with workspace description
- Creates VS Code .code-workspace file for multi-root workspace support
- Returns workspace info on success

Failure and recovery paths

- Fails if workspace with same name already exists
- RuntimeException caught and formatted as error output

Inputs and options

- name (required): Name of the workspace
- --json: Output as JSON

Key integrations

- WorkspaceService for directory and file operations
- File system for directory creation
