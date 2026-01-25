# workspaces overview

Lists all workspaces with their project counts and paths.

- Scans ~/workspaces directory for subdirectories
- Retrieves info for each workspace including project count
- Displays as table or JSON

Failure and recovery paths

- Returns empty list if no workspaces found
- Creates workspaces directory if it does not exist

Inputs and options

- --json: Output as JSON

Key integrations

- WorkspaceService for listing and info retrieval
