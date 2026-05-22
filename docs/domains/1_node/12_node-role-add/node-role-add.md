# `orbit node role:add [node] [role]`

[Back to Nodes commands.](../README.md)

Add one role assignment to a node.

## Usage

You can run this command to add an additional role assignment to an
existing node.

```bash
orbit node role:add [node] [role] [--tld=] [--json]
```

## Behavior

This command validates the role assignment, applies role-local options, and reports convergence progress.

- `gateway` cannot be added through this command.
- `vpn` and `router` cannot be added through this command. In v1 they are
  gateway-coupled infrastructure roles and normal `node role:*` commands cannot
  manage them independently.
- `agent` cannot be added through this command. The `agent` role is
  exclusive and is only selectable during `node:new`. `node role:add ...
  agent` fails with `validation_failed` and the explanation that
  `node:new --role=agent` is the only path that may create it.
- `app-development` requires `--tld`.
- `app-production` and `database` reject unsupported role-local options.
- Human output shows progress because convergence can be slow.
- Configured non-gateway callers forward through the typed gateway API and need
  `role:add` on the target node.

## Technical Contract

See [technical contract](technical/1_node-role-add.md).
