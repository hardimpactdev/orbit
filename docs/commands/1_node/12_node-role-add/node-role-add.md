# `orbit node role:add [node] [role]`

[Back to Nodes commands.](../README.md)

Add one hosted role assignment to a node.

## Usage

```bash
orbit node role:add [node] [role] [--tld=] [--json]
```

## Behavior

- `gateway` cannot be added through this command.
- `app-development` requires `--tld`.
- `app-production` and `database` reject unsupported role-local options.
- Human output shows progress because convergence can be slow.
- Joined callers forward through the typed gateway API.

## Technical Contract

See [technical contract](technical/1_node-role-add.md).
