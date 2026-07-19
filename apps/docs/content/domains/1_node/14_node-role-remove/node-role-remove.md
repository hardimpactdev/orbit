# `orbit node role:remove [node] [role]`

[Back to Nodes commands.](../README.md)

Remove one role assignment from a node.

## Usage

```bash
orbit node role:remove [node] [role] [--force] [--purge-data] [--json]
```

## Behavior

This command removes one role assignment from a node. Every role removal is
destructive and requires confirmation or `--force`, even when no dependents
exist.

- `gateway` cannot be removed through this command.
- `vpn` and `router` cannot be removed through this command. In v1 they are
  gateway-coupled infrastructure roles and normal `node role:*` commands cannot
  manage them independently.
- Interactive mode previews and lists Orbit-owned dependent resources, then
  asks for confirmation unless `--force` is present.
- Non-interactive mode, including `--json`, requires `--force` before any
  removal side effect.
- Confirmed removal cleans up Orbit-owned dependents while preserving user
  data. `--purge-data` also requests purge cleanup.
- Removing `app-dev` or `agent` changes only wildcard eligibility in the
  node-owned DNS projection. It reconciles `10-node-records.conf` without
  touching proxy-owned records or tool-owned base configuration.
- Configured non-gateway callers forward through the typed gateway API and need
  `role:remove` on the target node.

## Technical Contract

See [technical contract](technical/1_node-role-remove.md).
