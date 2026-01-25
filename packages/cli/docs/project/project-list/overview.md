# project:list overview

Lists all directories in configured paths as projects, including metadata such as PHP version and domain.

- Uses ProjectScanner::scan to load all projects (custom overrides first, then all directories).
- Resolves TLD and default PHP version from configuration.
- Outputs JSON with projects, count, TLD, and default PHP version, or renders a table.
- Indicates whether each project has a public folder, domain, and custom PHP version.

Failure and recovery paths

- If no projects are found, warns and exits successfully.

Inputs and options

- --json

Key integrations

- ProjectScanner (filesystem + config + database)
- ConfigManager (TLD, default PHP version)
