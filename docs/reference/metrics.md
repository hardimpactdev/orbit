---
title: "Metrics role"
description: "What the metrics role runs, how to enable, inspect, and disable it, and how Grafana access is authorized."
covers:
  - apps/gateway/app/Infrastructure/Metrics/**
  - apps/gateway/app/Domain/Metrics/**
  - apps/gateway/app/Actions/Nodes/ShowNodeMetricsAction.php
  - apps/gateway/app/Domain/Nodes/ManagedNodeEligibility.php
  - apps/gateway/app/Http/{Controllers/Api/MetricsController,Controllers/Api/GrafanaAccessAuthorizationController,Controllers/Api/TaskInsightsMetricsController,Middleware/RequireMetricsNode}.php
  - apps/gateway/app/Infrastructure/Processes/PrometheusProcessRuntimeStatusIndex.php
  - apps/gateway/app/Infrastructure/Caddy/Build/Sources/MetricsCaddySiteSource.php
  - swamp/{workflows/workflow-operation-metrics-*.yaml,extensions/behaviors/operations/integrations/metrics_*.ts}
---

# Metrics role

The `metrics` role runs Prometheus and Grafana on one managed Linux Node and collects host and container metrics from selected managed Linux Nodes. Open dashboards at `https://metrics.orbit` (or `/grafana/` on the Gateway origin). [`metrics`](/cli/metrics) lists the CLI commands, and [Service metrics](/reference/service-metrics) adds Caddy and PHP-FPM monitoring on the same role.

## What runs where

The role runs two Docker containers on the Metrics Node and two systemd units on each selected exporter Node:

| Part | Where | Listens on | Image / Binary |
| --- | --- | --- | --- |
| `orbit-metrics-prometheus` | Metrics Node | `127.0.0.1:9090` (no firewall rule) | `prom/prometheus:v3.5.0`, volume `orbit-metrics-prometheus-data` |
| `orbit-metrics-grafana` | Metrics Node | WireGuard address, port `3000` | `grafana/grafana:12.1.1`, volume `orbit-metrics-grafana-data`, UID/GID `472` (`orbit-grafana`) |
| `prometheus-node-exporter` | Selected Nodes | WireGuard address, port `9100` | Packaged `prometheus-node-exporter` |
| `orbit-cadvisor` | Selected Nodes | WireGuard address, port `9102` | Static binary `/usr/local/bin/orbit-cadvisor` (`v0.60.5`) |

Both containers use host networking, `json-file` logging (3 × 10 MB), and Docker health checks (`--health-start-period 30s --health-interval 5s --health-retries 12`). Prometheus scrapes node exporters and cAdvisor every 10 seconds, retains samples for 7 days, and labels each target with `node`. Grafana ships with one Prometheus datasource and three dashboards: `Orbit Node Resources`, `Orbit Caddy Traffic`, and `Orbit PHP Capacity`. None of these create Orbit `Process` or `Tool` records.

## Enable and recover

Enable the singleton role on an active managed Linux Node (`metrics` can share a Node with any other role):

```bash
orbit metrics:enable <node>
```

The API (`POST /api/v1/metrics`) returns HTTP 202 with an [Operation](/reference/operations). Enable installs Docker, validates `prometheus.yml` with `promtool`, writes the generated Grafana `admin` password (`0600`), starts both containers, verifies Prometheus `/-/ready` and Grafana login, configures fleet exporters, signs the `metrics.orbit` certificate, updates firewall rules, and publishes `metrics.orbit` on the Gateway's Caddy and private DNS.

If a step fails, the role assignment and step history stay recorded so you can resume or retry. Move the role to another Node with `orbit node:role:relocate <node> metrics --force` ([Native role relocation](/reference/node-provisioning#native-role-relocation)); relocation keeps the `metrics.orbit` certificate and copies missing Grafana credentials to the target without overwriting existing ones.

## Exporter selection

A Node can host an exporter only when it is an active managed Linux Node with a WireGuard address and stored SSH host fingerprint. Within that eligible set, Orbit combines the Node's exporter preference and active/provisioning roles:

| Preference | Node | Selected |
| --- | --- | --- |
| none | Has an active or provisioning role other than `swamp` | Yes |
| none | Roleless or only `swamp` | No |
| `enabled` | Any eligible Node | Yes |
| `disabled` | Any eligible Node except the Metrics Node | No |
| any | Eligible Metrics Node | Yes |

`orbit metrics:exporter:enable <node>` and `orbit metrics:exporter:disable <node>` (`PUT`/`DELETE /api/v1/metrics/exporters/{node}`) store the preference and return HTTP 202 with an Operation. Preferences survive role changes and a disabled Metrics role. Enable installs exporters, allows the Metrics Node on ports `9100` and `9102`, checks both endpoints (up to 30 seconds), reloads Prometheus via `SIGHUP` (keeping the mounted config inode intact), and waits up to 60 seconds for the target to appear active. Disable reloads Prometheus first so scraping stops before removing exporters.

Each selected Node installs `/etc/systemd/system/prometheus-node-exporter.service.d/orbit.conf` binding to its WireGuard address with `Restart=always`, `RestartSec=2`, `--collector.systemd --collector.systemd.unit-include=orbit-node-worker[.]service`, and textfile directory `/var/lib/prometheus/node-exporter` (used by [`ci-runner`](/reference/node-provisioning#runner-state-for-the-runner-view)).

`orbit metrics:status` queries Prometheus through Grafana and reports each exporter as `active` (both targets up), `inactive`, or `unknown` (Prometheus unreachable). Any non-active or skipped selected exporter marks `reconcile_status` as degraded without mutating the Node.

## cAdvisor

Because exporter Nodes may not run Docker, cAdvisor `v0.60.5` runs as a checksum-verified static binary (`/usr/local/bin/orbit-cadvisor`, `orbit-cadvisor.service`, `Restart=always`) reading cgroups directly for both systemd and Docker Processes. It collects CPU and memory only and drops Docker container labels to avoid series cardinality explosions. `GET /api/v1/processes` reads `orbit-process-{id}-{name}` (Docker) and `orbit-process-{id}-{name}.service` (systemd), returning `null` rather than `0` when no series exists.

## Task insights scrape

Prometheus scrapes `/api/v1/metrics/task-insights` on the active Gateway every 60 seconds (`job="orbit-task-insights"`, 10-second timeout, 20,000-sample limit) over the Gateway's WireGuard IP on port 443 with SNI `gateway.orbit` and the inline Orbit root CA. Only the active Metrics Node is authorized (`metrics.scraper_required`, 403, for any other peer). If no single active Gateway with a readable root CA exists, rendering logs `metrics.task_insights_job_skipped` and omits this job.

## Process runtime status

Process lists resolve `runtime_status` in priority order:

1. Recent `process.status` broadcast from a Gateway start, stop, or restart (valid for 30 seconds on host/checkout Processes).
2. Fresh [Node agent view](/reference/node-agent#gateway-view).
3. Prometheus fallback (cached 10 seconds), falling back to SSH if Prometheus is unreachable.
4. For [container Processes](/reference/development-containers#processes), one batched `incus exec` SSH call per Node (4-second budget) returns `runtime_status`, `observed_at`, and `runtime_error_code`.

On a container Gateway, Horizon and scheduler Process status reads the serving release's unit and verifies its revision. Sampled status is not a health check; [Doctor](/cli/doctor/doctor#what-each-family-checks) detects `process.crash_loop` directly on the Node.

## Grafana access

Open Grafana at `https://metrics.orbit` or `/grafana/` on the Gateway origin from the active Gateway Node or an active WireGuard peer with an access grant to the Gateway Node (a grant to only the Metrics Node is insufficient).

Before every request, Gateway Caddy calls `GET /api/v1/metrics/grafana/authorize` using the peer's WireGuard connection IP (ignoring forwarding headers) and proxies authorized requests to port 3000 on the Metrics Node. Removing a Node or its Gateway access grant reloads Gateway Caddy so open streaming connections close immediately (`metrics.caddy_publication_failed` on reload failure). On the Metrics Node, UFW rules `orbit:metrics-grafana-upstream` (allow Gateway) and `orbit:metrics-grafana-isolation` (deny others) restrict port 3000 to the Gateway Node ahead of broad WireGuard rules.

## Authorization and credentials

All Metrics API routes require exactly one active Gateway Node and an access grant to that Gateway Node (`node_access.required`, 403, otherwise).

The Grafana user is `admin` with a random password generated on first enable and stored encrypted on the Metrics Node. `orbit metrics:credentials` (`GET /api/v1/metrics/credentials`, `Cache-Control: no-store`) reads the stored password without contacting the Node. `orbit metrics:credentials --reset` (`POST /api/v1/metrics/credentials/reset`) starts an Operation (202) that updates the password via Grafana's API, verifies login, and promotes the new password only on HTTP 200.

## Disable and purge

`orbit metrics:disable --force` (`DELETE /api/v1/metrics`) requires `--force` (`metrics.force_required`, 422) and returns an Operation (202). It withdraws `metrics.orbit`, removes the Prometheus and Grafana containers, unit files, `/etc/orbit/metrics`, fleet exporters, and Grafana firewall rules, and deletes the role assignment on success.

By default, named volumes, stored Grafana credentials, Docker, and exporter preferences are preserved so re-enabling keeps data. Pass `--purge-data --force` to also remove both named volumes and stored credentials after native cleanup succeeds.

## Read Node metrics

[`orbit node:metrics`](/cli/node/metrics) returns the latest metrics snapshot reported by the [Node agent](/reference/node-agent#facts) with `possibly_outdated` when stale (`node.metrics_unavailable`, 422, if no snapshot exists). The [web app](/reference/web-app) queries Prometheus every 10 seconds through `/grafana/` using a 40-second rate window.

## Errors

Metrics endpoints return these error codes:

| Code | HTTP | Meaning |
| --- | --- | --- |
| `node_access.required` | 403 | Caller lacks Gateway Node access or no single active Gateway exists. |
| `metrics.scraper_required` | 403 | Non-Metrics peer requested `/api/v1/metrics/task-insights`. |
| `metrics.assignment_missing` | 409 | No active Metrics role assignment exists. |
| `metrics.assignment_drift` | 409 | Recorded Metrics assignment state does not match expected state. |
| `metrics.node_ineligible`, `metrics.node_inactive` | 409 | Target Node is inactive or ineligible for Metrics. |
| `metrics.exporter_node_inactive`, `metrics.exporter_node_ineligible` | 409 | Target exporter Node is inactive or ineligible. |
| `metrics.credentials_missing` | 409 / 422 | Stored Grafana credentials are missing (422 on read, 409 on reset). |
| `node.role_conflict` | 409 / 422 | Metrics role is already assigned, or attempted to disable the Metrics Node's own exporter. |
| `metrics.platform_unsupported` | 422 | Target Node is not Linux. |
| `metrics.force_required` | 422 | `--force` was omitted on `metrics:disable`. |
| `node.metrics_unavailable` | 422 | No agent metrics snapshot exists yet for the Node. |

## Why it works this way

Running Prometheus and Grafana as standalone Docker containers on one Node keeps placement and upgrades simple without Swarm or Compose, while running cAdvisor as a static binary lets exporter Nodes without Docker report Process usage. Authorizing Grafana through the Gateway Node keeps one access boundary even when Metrics moves, and proxying web metrics through `/grafana/` keeps Prometheus unpublished on `127.0.0.1:9090`.
