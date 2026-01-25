# project:scan overview

Scans configured paths (or a specific path) to find git repositories and reports them as projects.

- Builds a list of paths from the CLI argument or config paths.
- Recursively scans directories to a configurable depth.
- Detects git repositories by presence of a .git directory.
- Extracts project metadata (name, path, GitHub URL, project type).
- Sorts results alphabetically before output.

Failure and recovery paths

- If no paths are configured, the command fails with an error.
- Missing base paths are skipped silently during scanning.

Inputs and options

- path (optional): override scan base path
- --depth: max recursion depth (default 2)
- --json

Key integrations

- git for resolving remote origin URLs
