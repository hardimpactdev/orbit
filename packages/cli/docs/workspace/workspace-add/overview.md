# workspace:add overview

Adds a project to an existing workspace by creating a symlink.

- Validates workspace and project exist
- Creates symlink from workspace to project in ~/projects
- Regenerates .code-workspace file with new project
- Updates CLAUDE.md with project list

Failure and recovery paths

- Fails if workspace does not exist
- Fails if project does not exist in ~/projects
- Fails if project already in workspace

Inputs and options

- workspace (required): Name of the workspace
- project (required): Name of the project to add
- --json: Output as JSON

Key integrations

- WorkspaceService for symlink and file operations
