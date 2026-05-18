# `orbit node role:update [node] [role]`

[Back to Nodes commands.](../README.md)

Update the desired settings for one hosted role assignment.

## Usage

```bash
orbit node role:update [node] [role] [--tld=] [--json]
```

## Behavior

This command rewrites desired role settings and re-runs convergence for the target node.

- `gateway` cannot be updated through this command.
- Updating role settings triggers convergence again.
- Human output shows progress while convergence runs.

## Technical Contract

See [technical contract](technical/1_node-role-update.md).
