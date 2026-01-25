# update overview

Checks for available Orbit CLI updates without installing them.

- Fetches latest release info from GitHub API
- Compares current version with latest available
- Displays version information and update availability
- Suggests running `orbit upgrade` if update is available

Use cases

- Check if updates are available before upgrading
- Verify current installed version
- Scripting and automation (with --json flag)

Inputs and options

- --json: Output as JSON

Example output

```
Current version: v0.1.34
Latest version:  v0.1.35

A new version is available: v0.1.35
Run `orbit upgrade` to install the update.
```

Key integrations

- GitHub API for release info
- GitHubReleasesStrategy for version checking
