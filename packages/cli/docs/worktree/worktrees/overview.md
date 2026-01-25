# worktrees

Lists all git worktrees with their subdomains.

## What It Does

1. Scans for git worktrees across all sites (or a specific site)
2. Displays worktree information including domain, branch, and path

## Arguments

| Argument | Required | Description |
|----------|----------|-------------|
| `site` | No | Filter worktrees by site name |

## Options

| Option | Description |
|--------|-------------|
| `--json` | Output result as JSON with `worktrees` array |

## Output Table

| Column | Description |
|--------|-------------|
| Site | Parent site name |
| Worktree | Worktree name |
| Domain | Subdomain URL (e.g., feature-branch.site.ccc) |
| Branch | Git branch name |
| Path | Filesystem path (truncated) |

## Examples

```bash
# List all worktrees
orbit worktrees

# List worktrees for a specific site
orbit worktrees mysite
```

## How Worktrees Work

Git worktrees allow multiple working directories for the same repository. Orbit automatically detects these and creates subdomains for each worktree, enabling parallel development on multiple branches.

## Related Commands

- `worktree:refresh` - Scan and auto-link new worktrees
- `worktree:unlink` - Remove a worktree from Caddy routing
