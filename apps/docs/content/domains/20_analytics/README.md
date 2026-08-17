# Analytics Commands

Analytics commands manage Orbit's fleet analytics surface. The command family is
`analytics:*`.

The backend technology is Plausible CE. Plausible is not the product model.

## Domain Rules

These rules define the analytics command domain and its role boundary.

- The analytics command family owns the `analytics:*` command prefix.
- The `analytics` role is a private workload role. It runs Plausible CE as a
  process-owned Docker container, publishes only on the node's WireGuard
  address, and receives traffic through router-owned analytics routes.
- The private dashboard and admin endpoint is `https://analytics.orbit`.
- Exactly one `analytics` role assignment may exist fleet-wide. Pending,
  active, errored, and removing assignments reserve that singleton slot so a
  failed deployment or cleanup is completed instead of duplicated on another
  node.
- Public instance analytics hosts, such as `https://analytics.example.com`, are
  tracking-only routes created for a selected concrete instance. They use
  that instance's public domain, proxy Plausible script and event paths only,
  and must not expose the dashboard publicly.
- Enabling instance analytics resolves one concrete instance and its serving-node
  authorization boundary before route effects. That instance must have a
  configured public domain. Orbit defaults the tracking host to
  `analytics.<instance-domain>`, enacts the router and ingress artifacts before
  reporting success, and returns the script base URL for that instance plus the
  event endpoint operators use to adapt the Plausible-generated snippet.
  Public domains, hosts, route targets, and serving-node authorization are
  instance-owned placement facts; the app never owns them.
- Plausible version, environment, lifecycle, logs, and endpoint state belong to
  the process row generated for the analytics role. There is no
  `--plausible-version` option; the command option is `--requested-version`
  (`orbit analytics:update --help`). The native launcher may rewrite a
  convenience `--version=` flag to that option; global `-V/--version` is
  framework version display.
- PostgreSQL and ClickHouse are service processes on active `database` role
  nodes. The analytics role stores the selected PostgreSQL process identity as
  well as the backing node identities and does not install or own either
  database. Assignment-time creation requires that stored identity. A one-time
  migration may backfill an unambiguous assignment. Multiple candidates without
  a stored identity fail clearly. Runtime resolution now requires a stored
  `postgres_process_id` and fails closed when it is missing. Both services use
  generated credentials encrypted in gateway storage and publish only on
  WireGuard.
- The default deployment follows the official Plausible CE 3.2.1 composition:
  `postgres:16-alpine` and
  `clickhouse/clickhouse-server:24.12-alpine`. Plausible reads the selected
  process endpoints over their WireGuard addresses with authentication;
  Docker-local service aliases are not cross-node dependency addresses.
- Analytics role convergence installs and verifies Docker, applies and starts
  Plausible, then creates and enacts the router-owned `analytics.orbit` route
  and Orbit-managed TLS before the role assignment succeeds. Runtime, route,
  certificate, or Caddy reload failure leaves the assignment in `error` and
  keeps provisioning incomplete.
- Removing the analytics role removes its live Plausible Docker container, the
  `analytics.orbit` route row, rendered Caddy site, certificate, and key before
  completing role removal.
- The analytics command family coordinates node, process, proxy, and
  instance-owned binding state. It does not own an independent
  `doctor --family=analytics` state family in v1.

## State Ownership

The `analytics` command domain does not own a state family. It coordinates
state owned by node, instance, process, and proxy families.

- [`node`](../1_node/README.md) owns the `analytics` role assignment and its
  `postgres_node_id`, `postgres_process_id`, and `clickhouse_node_id` settings.
  [`doctor --family=node`](../1_node/node-doctor.md) owns role assignment
  drift.
- [`process`](../7_process/README.md) owns the Plausible CE, PostgreSQL, and
  ClickHouse service rows, runtime configuration, versions, lifecycle, logs, and
  endpoints. [`doctor --family=process`](../7_process/process-doctor.md) owns
  service runtime drift.
- [`proxy`](../8_proxy/README.md) owns `analytics.orbit`, public instance
  analytics route rows, route artifacts, TLS material, and analytics backend
  pools. [`doctor --family=proxy`](../8_proxy/proxy-doctor.md) owns route
  artifact drift and restores a missing or divergent private analytics route
  from the singleton active role assignment.
- Instance analytics bindings are instance-owned placement state (public domain,
  tracking host, route target, serving-node authorization).
  [`doctor --family=instance`](../5_app/instance-doctor.md) owns instance
  binding drift.

There is no `doctor --family=analytics` contract in v1.

## Concepts

This concept page defines the vocabulary used by analytics command contracts.

- [Analytics Concepts](analytics-concepts.md)

## Commands

The analytics family provides the following command.

1. [`orbit analytics:update --requested-version=<version>`](1_analytics-update/analytics-update.md)

## Non-Goals

V1 does not inject tracking scripts, provision Plausible sites, expose public
dashboards, create Plausible API tokens for each app, provide analytics data
export, or implement a separate analytics doctor family. Plausible site
creation remains manual; Orbit returns proxy endpoint guidance after binding
convergence.

## Related

- [`orbit instance:analytics enable`](../5_app/16_instance-analytics-enable/instance-analytics-enable.md)
- [`orbit node role:add`](../1_node/12_node-role-add/node-role-add.md)
- [`orbit process:*`](../7_process/README.md)
- [`orbit proxy:*`](../8_proxy/README.md)
