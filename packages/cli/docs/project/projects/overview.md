# projects overview

Lists projects that have a public folder (real web projects), including domain, PHP version, and path.

- Uses ProjectScanner::scanProjects to filter to projects with a public/ directory.
- Resolves default PHP version from configuration.
- Outputs JSON with display name, repo, domain, path, and PHP version, or renders a table.

Failure and recovery paths

- If no projects are found, warns and exits successfully.

Inputs and options

- --json

Key integrations

- ProjectScanner (filesystem + config + database)
- ConfigManager (default PHP version)
