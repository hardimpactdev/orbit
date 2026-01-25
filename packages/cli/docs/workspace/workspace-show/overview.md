# workspace:show overview

Displays details about a specific workspace including its projects.

- Retrieves workspace info from WorkspaceService
- Shows path, project list with symlink targets
- Indicates if .code-workspace and CLAUDE.md exist

Failure and recovery paths

- Fails if workspace directory does not exist

Inputs and options

- name (required): Name of the workspace
- --json: Output as JSON

Key integrations

- WorkspaceService for workspace info retrieval
