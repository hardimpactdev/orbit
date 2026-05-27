# `orbit node role:list [node]`

[Back to Nodes commands.](../README.md)

List the role assignments for one node.

## Usage

```bash
orbit node role:list [node] [--json]
```

## Behavior

This command summarizes role state for one node and forwards remote calls through the gateway.

- Lists the role assignments for exactly one node.
- Gateway callers read locally; configured non-gateway callers forward to the
  gateway over typed HTTPS and need `role:read` on the target node.
- Each assignment includes `role`, `status`, `settings`, `last_error`, and `converged_at`.
- `--json` forces non-interactive mode.

## Technical Contract

See [technical contract](technical/1_node-role-list.md).
