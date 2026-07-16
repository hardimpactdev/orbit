# Analytics Commands

Analytics commands manage Orbit's fleet analytics surface. The command family is
`analytics:*`.

The backend technology is Plausible CE. Plausible is not the product model.

## Domain Rules

These rules define the analytics command domain and its role boundary.

- The analytics command family owns the `analytics:*` command prefix.
- The `analytics` role is a private workload role. It runs Plausible CE as a
  process-owned Docker/Swarm service, binds only to the node's WireGuard
  address, and receives traffic through router-owned analytics routes.
- The private dashboard and admin endpoint is `https://analytics.orbit`.
- Public app analytics hosts, such as `https://analytics.example.com`, are
  tracking-only routes created from app analytics bindings. They proxy Plausible
  script and event paths only and must not expose the dashboard publicly.
- Plausible version, environment, lifecycle, logs, and endpoint state belong to
  the process row generated for the analytics role. There is no
  `--plausible-version` option; commands use the generic `--version` field.
- PostgreSQL and ClickHouse are service processes on active `database` role
  nodes. The analytics role selects those backing nodes by role settings and
  does not install or own either database.
- The default deployment follows the official Plausible CE 3.2.1 composition:
  `postgres:16-alpine` and
  `clickhouse/clickhouse-server:24.12-alpine`. Plausible reads the selected
  process endpoints over their WireGuard addresses; Docker-local service
  aliases are not cross-node dependency addresses.
- Analytics role convergence installs and verifies Docker. It initializes
  Docker Swarm with the node's WireGuard address as its advertised address, or
  reuses an active manager after verifying manager control. It then applies
  and starts the Plausible service before the role assignment becomes active.
  Initialization, apply, or start failure keeps provisioning incomplete.
- Removing the analytics role removes its live Plausible Swarm service before
  deleting the process and role records.
- The analytics command family coordinates node, process, proxy, and app-owned
  binding state. It does not own an independent `doctor --family=analytics`
  state family in v1.

## State Ownership

The `analytics` command domain does not own a state family. It coordinates
state owned by node, app, process, and proxy families.

- [`node`](../1_node/README.md) owns the `analytics` role assignment and its
  `postgres_node_id` and `clickhouse_node_id` settings.
  [`doctor --family=node`](../1_node/node-doctor.md) owns role assignment
  drift.
- [`process`](../7_process/README.md) owns the Plausible CE, PostgreSQL, and
  ClickHouse service rows, runtime configuration, versions, lifecycle, logs, and
  endpoints. [`doctor --family=process`](../7_process/process-doctor.md) owns
  service runtime drift.
- [`proxy`](../8_proxy/README.md) owns `analytics.orbit`, public app analytics
  route rows, route artifacts, TLS material, and analytics backend pools.
  [`doctor --family=proxy`](../8_proxy/proxy-doctor.md) owns route artifact
  drift.
- [`app`](../5_app/README.md) owns binding state for each app and public
  tracking host intent. [`doctor --family=app`](../5_app/app-doctor.md) owns
  app binding drift.

There is no `doctor --family=analytics` contract in v1.

## Concepts

This concept page defines the vocabulary used by analytics command contracts.

- [Analytics Concepts](analytics-concepts.md)

## Commands

The analytics family provides the following command.

1. [`orbit analytics:update --version=<version>`](1_analytics-update/analytics-update.md)

## Non-Goals

V1 does not inject tracking scripts, provision Plausible sites, expose public
dashboards, create Plausible API tokens for each app, provide analytics data
export, or implement a separate analytics doctor family.

## Related

- [`orbit app:analytics enable`](../5_app/16_app-analytics-enable/app-analytics-enable.md)
- [`orbit node role:add`](../1_node/12_node-role-add/node-role-add.md)
- [`orbit process:*`](../7_process/README.md)
- [`orbit proxy:*`](../8_proxy/README.md)
