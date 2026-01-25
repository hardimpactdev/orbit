# worktree:unlink

Unlinks a worktree from a site, removing its Caddy routing.

## What It Does

1. Validates the site and worktree exist
2. Removes the worktree's subdomain from Caddy configuration
3. Reloads Caddy to apply changes

## Arguments

| Argument | Description |
|----------|-------------|
| `site` | The parent site name |
| `worktree` | The worktree name to unlink |

## Options

| Option | Description |
|--------|-------------|
| `--json` | Output result as JSON |

## What Gets Removed

- Caddy route for the worktree subdomain
- Does NOT delete the git worktree itself

## Example

```bash
# Unlink a worktree
orbit worktree:unlink mysite feature-branch
```

## Note

This only removes the Caddy routing. The git worktree remains on disk. To fully remove a worktree, use `git worktree remove` first, then run `worktree:refresh --cleanup`.

## Related Commands

- `worktrees` - List all worktrees
- `worktree:refresh` - Re-scan and re-link worktrees
