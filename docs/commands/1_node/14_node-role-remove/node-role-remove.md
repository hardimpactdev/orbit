# `orbit node role:remove [node] [role]`

[Back to Nodes commands.](../README.md)

Remove one hosted role assignment from a node.

## Usage

```bash
orbit node role:remove [node] [role] [--force] [--purge-data] [--json]
```

## Behavior

- `gateway` cannot be removed through this command.
- `--purge-data` requires `--force`.
- Removal blocks when dependents exist.
- `--force` removes Orbit-owned dependent cleanup while preserving user data.
- `--force --purge-data` also requests purge cleanup.

## Technical Contract

See [technical contract](technical/1_node-role-remove.md).
