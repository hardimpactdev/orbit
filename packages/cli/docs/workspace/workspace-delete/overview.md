# workspace:delete overview

Deletes a workspace directory and all its symlinks.

- Prompts for confirmation unless --force or --json
- Removes entire workspace directory including symlinks
- Does NOT delete actual project directories (only symlinks)

Failure and recovery paths

- Fails if workspace does not exist
- Can be cancelled via confirmation prompt

Inputs and options

- name (required): Name of the workspace
- --force: Skip confirmation prompt
- --json: Output as JSON (also skips confirmation)

Key integrations

- WorkspaceService for directory deletion
