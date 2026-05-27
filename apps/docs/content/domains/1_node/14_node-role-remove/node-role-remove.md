# `orbit node role:remove [node] [role]`

[Back to Nodes commands.](../README.md)

Remove one role assignment from a node.

## Usage

```bash
orbit node role:remove [node] [role] [--force] [--purge-data] [--json]
```

## Behavior

This command removes one role assignment from a node and gates destructive cleanup behind explicit flags.

- `gateway` cannot be removed through this command.
- `vpn` and `router` cannot be removed through this command. In v1 they are
  gateway-coupled infrastructure roles and normal `node role:*` commands cannot
  manage them independently.
- `--purge-data` requires `--force`.
- Removal blocks when dependents exist.
- `--force` removes Orbit-owned dependents while preserving user data.
- `--force --purge-data` also requests purge cleanup.
- Configured non-gateway callers forward through the typed gateway API and need
  `role:remove` on the target node.

## Technical Contract

See [technical contract](technical/1_node-role-remove.md).
