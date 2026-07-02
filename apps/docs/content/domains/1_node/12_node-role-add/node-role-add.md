# `orbit node role:add [node] [role]`

[Back to Nodes commands.](../README.md)

Add one role assignment to a node.

## Usage

You can run this command to add an additional role assignment to an
existing node.

```bash
orbit node role:add [node] [role] [--tld=] [--redis-node=] [--postgres-node=<node>] [--clickhouse-node=<node>] [--s3-data-path=<path>] [--json]
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
  `node:new --template=agent` is the preferred path that may create it.
- `app-dev` requires `--tld`.
- On an opted-in macOS/Darwin `app-dev` node, the gateway queues a fixed
  `app-dev-convergence` Orbit Agent job after the role assignment is stored.
  Nodes remain opt-in: no Orbit Agent job is queued until
  `node:update <node> --orbit-agent-capable` has marked the node capable.
- `websocket` requires `--redis-node`; the selected node must have an active
  `database` role and Redis expected or installed.
- `analytics` requires `--postgres-node` and `--clickhouse-node`; the selected
  nodes must have active `database` roles and PostgreSQL or ClickHouse expected
  or installed. The same database node can satisfy both options.
- `s3` accepts optional `--s3-data-path`; it defaults to
  `/srv/orbit/s3/data`, must be absolute, and is mounted into SeaweedFS as
  `/data`.
- `metrics` has no role-local options.
- `app-prod`, `database`, and `metrics` reject unsupported role-local options.
- Human output shows progress because convergence can be slow.
- Configured non-gateway callers forward through the typed gateway API and need
  `role:add` on the target node.

## Technical Contract

See [technical contract](technical/1_node-role-add.md).
