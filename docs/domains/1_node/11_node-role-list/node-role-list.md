# `orbit node role:list [node]`

[Back to Nodes commands.](../README.md)

List the hosted role assignments for one node.

## Usage

```bash
orbit node role:list [node] [--json]
```

## Behavior

This command summarizes hosted role state for one node and forwards remote calls through the gateway.

- Lists the role assignments for exactly one node.
- Uses the existing gateway read path: gateway callers read locally; joined callers forward to the gateway over typed HTTPS.
- Each assignment includes `role`, `status`, `settings`, `last_error`, and `converged_at`.
- `--json` forces non-interactive mode.

## Technical Contract

See [technical contract](technical/1_node-role-list.md).
