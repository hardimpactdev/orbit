# worktree:refresh

Refreshes worktree detection and auto-links new worktrees to Caddy.

## What It Does

1. Optionally cleans up orphaned worktree entries (deleted from git but still tracked)
2. Scans all sites for git worktrees
3. Auto-links new worktrees to Caddy for subdomain routing

## Options

| Option | Description |
|--------|-------------|
| `--cleanup` | Also remove orphaned worktree entries |
| `--json` | Output result as JSON |

## Use Cases

- After creating new git worktrees manually
- After deleting worktrees to clean up Caddy config
- As part of regular maintenance

## Examples

```bash
# Refresh worktree detection
orbit worktree:refresh

# Refresh and cleanup orphaned entries
orbit worktree:refresh --cleanup
```

## Output

- Number of worktrees found
- Number of orphaned entries removed (if --cleanup)
- List of active worktrees with domains

## Related Commands

- `worktrees` - List all worktrees
- `worktree:unlink` - Manually unlink a specific worktree
