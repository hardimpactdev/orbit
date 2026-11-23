---
title: "Analytics role"
description: "How the analytics role runs Plausible Community Edition, where it keeps its data, and how an Instance publishes a tracking host."
covers:
  - apps/gateway/app/{Domain,Infrastructure,Actions}/Analytics/**
  - apps/gateway/app/Actions/Routes/ConvergeRouteAction.php
  - swamp/extensions/behaviors/operations/analytics/**
  - apps/cli/app/Commands/Analytics/**
  - apps/cli/app/Commands/Instances/*InstanceAnalyticsCommand.php
---

# Analytics role

The `analytics` role runs Plausible Community Edition for the fleet. Instances publish first-party tracking hosts that forward visits to Plausible, and the Gateway reads visitor counts back for the [Instance analytics panel](/reference/instance-analytics-stats). [`analytics`](/cli/analytics) lists the CLI commands.

## Prepare the database Processes

Plausible stores its data in two Docker Node Processes on an active `database` Node:

| Process | Image | Holds |
| --- | --- | --- |
| PostgreSQL | `postgres:16`, or a managed `postgres:18` [Database server](/reference/database-servers) | Plausible accounts and site settings |
| ClickHouse | `clickhouse/clickhouse-server` | Tracked events |

Publish unmanaged PostgreSQL port `5432` and ClickHouse HTTP port `8123` on the database Node's WireGuard address only (managed PostgreSQL 18 may also publish its private LAN address). For unmanaged Processes, Plausible reads `POSTGRES_USER` (default `postgres`), `POSTGRES_PASSWORD`, `CLICKHOUSE_USER`, `CLICKHOUSE_PASSWORD`, and `CLICKHOUSE_DB` from each Process's environment. For managed PostgreSQL 18, create one canonical `plausible_db` database connection on the server before assigning Analytics; Orbit resolves that owner's encrypted credentials automatically and blocks `database:destroy` while Analytics uses it. Plan roughly 2 GB of memory for the three services.

## Assign the role

Assign the singleton role to a Node (conflicts with `gateway`, combines with `database`, cannot be assigned during `node:add` or relocated):

```bash
orbit node:role:add <node> analytics --postgres-process=<id> --clickhouse-process=<id>
```

Assignment refuses Processes attached as an Instance Node service target (`process.node_service_ownership_conflict`) or storage Processes failing validation:

| Error code | Cause |
| --- | --- |
| `analytics.postgres_process_missing`, `analytics.clickhouse_process_missing` | Process does not exist. |
| `analytics.process_not_docker`, `analytics.process_not_node` | Process is not a Docker Node Process. |
| `analytics.process_not_database_node` | Process's Node is not an active `database` Node. |
| `analytics.postgres_unsupported`, `analytics.clickhouse_unsupported` | Image is not a supported PostgreSQL or ClickHouse engine. |
| `analytics.storage_credentials_missing` | Required credentials or canonical `plausible_db` connection are missing or ambiguous. |
| `analytics.storage_port_missing` | Process does not publish port `5432` or `8123`. |
| `process.wireguard_ip_missing` | Process's Node has no WireGuard address. |

Role convergence publishes ClickHouse configuration files and adds local Docker-bridge firewall rules (`orbit:analytics-postgres-local` and `orbit:analytics-clickhouse-local`) when storage shares the Analytics Node. It then initializes the database (`createdb` and `migrate`), starts the `plausible` Docker Process at `ORBIT_ANALYTICS_PLAUSIBLE_VERSION` (default `3.2.1`), waits up to 60 seconds for `/api/health`, and publishes `https://analytics.orbit` with an Orbit CA certificate, Caddy site, and private DNS record.

Do not create an unrelated Process named `plausible` on the Analytics Node, and do not remove `plausible` or either storage Process while the role is assigned (`process.required_by_analytics`). Open `https://analytics.orbit` over WireGuard right after setup to register the owner account, then store a Stats API key on the Gateway with `orbit analytics:credentials --set`.

## ClickHouse configuration

To prevent ClickHouse's default system log tables and background merges from exhausting memory on small Nodes, role convergence writes Plausible's four standard ClickHouse configuration files (`0644`, `root`) on the ClickHouse Node:

| Host file | Container path |
| --- | --- |
| `/etc/orbit/analytics/clickhouse/config.d/logs.xml` | `/etc/clickhouse-server/config.d/logs.xml` |
| `/etc/orbit/analytics/clickhouse/config.d/ipv4-only.xml` | `/etc/clickhouse-server/config.d/ipv4-only.xml` |
| `/etc/orbit/analytics/clickhouse/config.d/low-resources.xml` | `/etc/clickhouse-server/config.d/low-resources.xml` |
| `/etc/orbit/analytics/clickhouse/users.d/default-profile-low-resources-overrides.xml` | `/etc/clickhouse-server/users.d/default-profile-low-resources-overrides.xml` |

`logs.xml` retains only `query_log` for 30 days and disables other system log tables. Mount all four files read-only into your ClickHouse Process and restart the Process after any file changes. To republish files on an existing install, re-run `orbit node:role:add <node> analytics --converge --postgres-process=<id> --clickhouse-process=<id>`.

## Update and remove the role

### Update Plausible

`orbit analytics:update <version>` validates `X.Y.Z` (`analytics.version_invalid`), requires an active role and running `plausible` Process (`analytics.role_missing`, `analytics.runtime_missing`, `analytics.runtime_stopped`, 409), runs `createdb` and `migrate`, replaces the container, and checks `/api/health`. If replacement or health fails, Orbit restores the previous container definition (database migrations are not reversed) and leaves the version pin unchanged.

### Remove the role

`orbit node:role:remove <node> analytics` removes the `plausible` Process, Caddy site, certificate, DNS record, firewall rules, and stored `SECRET_KEY_BASE`. It never touches PostgreSQL or ClickHouse Processes or data. Removal is refused while any Instance still has a tracking host (`analytics.tracking_hosts_exist`).

## Publish a tracking host

A tracking host is a Route of kind `analytics_tracking` belonging to one Instance. Enable it with `orbit instance:analytics:enable <instance>`, which publishes `analytics.<instance domain>` (or up to 10 explicit `--host` values, rejecting IPs and `.orbit` domains), configures the Plausible site, timezone (default `Europe/Amsterdam`), and up to 100 goals (`Goal.CustomEvent`, `Goal.Pageview`, `Goal.Revenue`, with up to 3 `custom_props`), and returns an Operation (202).

Tracking Routes mirror the Instance's authoritative Route (Node-scoped, private Cluster Router, or public Cluster Ingress) and move automatically with Cluster transitions. They proxy only two paths over WireGuard to Plausible and return `404` on everything else so the dashboard stays private:

| Path | Target |
| --- | --- |
| `/js/*` | Plausible tracking script (`/js/script.js`) |
| `/api/event` | Plausible event ingestion |

Direct `route:create`, `route:update`, `route:target:set`, `route:target:unset`, `route:converge`, and `route:destroy` refuse `analytics_tracking` Routes (`route.kind_unsupported`, `route.analytics_native_required`, `route.tracking_managed`). Use `orbit instance:analytics:show <instance>` to inspect the CNAME record and script tag, and `orbit instance:analytics:disable <instance> [--yes]` to detach the site and remove tracking Routes while preserving Plausible event history. Disable analytics before destroying an Instance (`analytics.tracking_hosts_exist`).

### Tracking Operation errors

Analytics enable and disable Operations report these error codes:

| Error code | Step |
| --- | --- |
| `analytics.site_failed` | Converge Plausible site |
| `app-dev.certificate_request_failed`, `app-dev.certificate_sign_failed` | Tracking certificate CSR / signing |
| `route.publish_failed`, `route.probe_failed` | Caddy publish / TLS probe |
| `private_dns.publication_failed` | Private DNS update |
| `route.withdraw_failed`, `route.removal_check_failed` | Withdraw tracking sites / verify removal |
| `analytics.enable_failed`, `analytics.disable_failed` | Any other failure |

## Why it works this way

Keeping PostgreSQL and ClickHouse on `database` Nodes lets operators size and back up storage independently, while running Plausible as an ordinary Docker Process reuses Process logs, restarts, and Doctor checks. Exposing only `/js/*` and `/api/event` on a first-party tracking Route keeps the Plausible dashboard private on WireGuard and avoids content blockers without colliding with application routes. Reading Stats API counts from the Gateway keeps the API key off browsers and prevents missing keys from appearing as zero traffic.
