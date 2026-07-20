# `orbit node role:add [node] [role]`

[Back to Nodes commands.](../README.md)

Add one role assignment to a node.

## Usage

You can run this command to add an additional role assignment to an
existing node.

```bash
orbit node role:add [node] [role] [--valkey-node=] [--postgres-node=<node>] [--postgres-process=<process>] [--clickhouse-node=<node>] [--s3-data-path=<path>] [--json]
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
- Role assignment never changes or duplicates the node-owned TLD.
- Adding `app-dev` changes only wildcard eligibility in the node-owned DNS
  projection. It reconciles `10-node-records.conf` without touching proxy-owned
  records or tool-owned base configuration.
- `app-dev` convergence uses direct gateway-pushed command envelopes.
  An active workload role supplies Agent intent; no duplicated capability flag
  is required.
- `websocket` requires `--valkey-node`; the selected node must have an active
  `database` role and Valkey expected or installed.
- `analytics` requires `--postgres-node`, `--postgres-process`, and
  `--clickhouse-node`; the selected PostgreSQL process must be a `postgres`
  service process with version family `16` owned by the selected database
  node. Other PostgreSQL families are rejected because Plausible requires
  PostgreSQL 16. The selected
  nodes must have active `database` roles and PostgreSQL or ClickHouse expected
  or installed. The same database node can satisfy both options. A second
  fleet analytics role is rejected before provisioning. Success means both
  Plausible and the private `analytics.orbit` route with TLS converged.
- `s3` accepts optional `--s3-data-path`; it defaults to
  `/srv/orbit/s3/data`, must be a canonical path under an approved persistent
  data root (`/media`, `/mnt`, `/opt/orbit`, `/srv`, or `/var/lib/orbit`), and
  is mounted into SeaweedFS as `/data`.
- `metrics` has no role-local options.
- `app-prod`, `database`, and `metrics` reject unsupported role-local options.
- Human output shows progress because convergence can be slow.
- Configured non-gateway callers forward through the typed gateway API and need
  `role:add` on the target node.

## Technical Contract

See [technical contract](technical/1_node-role-add.md).
