# `orbit node role:add [node] [role]`

[Back to Nodes commands.](../README.md)

Add one hosted role assignment to a node.

## Usage

You can run this command to add an additional hosted role assignment to an
existing node.

```bash
orbit node role:add [node] [role] [--tld=] [--json]
```

## Behavior

This command validates the role assignment, applies role-local options, and reports convergence progress.

- `gateway` cannot be added through this command.
- `agent` cannot be added through this command. The `agent` role is
  exclusive and is only selectable during `node:new`. `node role:add ...
  agent` fails with `validation_failed` and the explanation that
  `node:new --role=agent` is the only path that may create it.
- `app-development` requires `--tld`.
- `app-production` and `database` reject unsupported role-local options.
- Human output shows progress because convergence can be slow.
- Joined callers forward through the typed gateway API.

## Technical Contract

See [technical contract](technical/1_node-role-add.md).
