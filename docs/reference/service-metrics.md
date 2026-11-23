---
title: "Service metrics"
description: "Caddy proxy traffic and dedicated production PHP-FPM capacity monitoring."
covers:
  - apps/gateway/app/Infrastructure/Metrics/ServiceMetrics*.php
  - apps/gateway/app/Infrastructure/Metrics/PrometheusConfigRenderer.php
  - apps/gateway/app/Infrastructure/Caddy/Build/Sources/ServiceMetricsCaddySiteSource.php
  - apps/gateway/app/Infrastructure/Instances/ProductionPhpRuntimeConfigRenderer.php
---

# Service metrics

The [Metrics role](/reference/metrics) extends host monitoring with Caddy proxy traffic metrics on selected `ingress` Nodes and PHP-FPM capacity metrics via Cbox FPM Exporter on selected `app-prod` Nodes.

## Selection

Service metrics follow each Node's existing exporter selection without a separate command:

| Selected Node | Added monitoring |
| --- | --- |
| `ingress` with an active public Route | Caddy metrics on WireGuard port `9103` |
| `app-prod` with dedicated PHP-FPM production Instances | Cbox FPM Exporter on WireGuard port `9114` |
| Both roles | Both |
| Neither role | Node exporter and cAdvisor only |

## Caddy traffic

The **Orbit Caddy Traffic** dashboard displays scrape health, request rate, 5xx responses, p95 request duration, p95 time-to-first-byte, and requests in flight.

Orbit's [Caddy global options](/reference/caddy-configuration#published-layout) enable per-host metrics on every Caddy Node. On selected `ingress` Nodes, Orbit adds a WireGuard scrape site on port `9103` serving `/metrics` only to the active Metrics Node (`remote_ip <metrics_wg_ip>`, returning `403` to all other peers) without exposing the Caddy admin API. Prometheus retains `caddy_http_*` series for active public hosts, hostless series, and `caddy_reverse_proxy_upstreams_healthy`. Because `ingress` proxies to the Cluster Router, upstream health reflects Router reachability rather than individual Instances.

## PHP capacity

Orbit installs Cbox FPM Exporter `v3.1.1` (verified by checksum) at `/usr/local/bin/orbit-fpm-exporter` with config `/etc/orbit/service-metrics/fpm.yml` (`0600`) and unit `orbit-fpm-exporter.service` (`After=wg-quick@orbit.service`, `Restart=always`, `RestartSec=5` because Cbox exits `0` if binding fails before WireGuard is up). Pool discovery, CLI PHP monitoring, and Laravel collectors stay disabled; Orbit configures each production Instance's dedicated pool explicitly and Prometheus relabels series with `instance_id`, `node`, `node_id`, and `service`.

The **Orbit PHP Capacity** dashboard shows pool health, active workers, `pm.max_children`, waiting requests, worker-limit events, slow requests (populated only when a slowlog threshold is configured in `local.conf`), and OPcache memory, hit rate, and resets. Prometheus drops per-worker `phpfpm_process_*` series before storage. Note that Cbox's worker memory metric reflects PHP memory from the last request, not OS RSS.

### Runtime behavior

Status scrapes use a separate local socket (`<socket>.status`) and status path `/orbit-fpm-status` so busy application workers never block metrics collection. Monitoring never resets OPcache and never edits `local.conf`. When enabling or disabling status lines changes a running production pool's `pool.conf`, Orbit validates and reloads only that master (stopped masters stay stopped; conflicting operator status directives fail convergence without overwriting them). For [web-root Routes](/reference/routes#web-roots-on-production), metrics cover the default pool while OPcache metrics cover the shared master.

## Private access

Caddy serves metrics on WireGuard port `9103` (restricted by source IP in Caddy) and Cbox listens on WireGuard port `9114`. Prometheus scrapes service jobs every 15 seconds with a 12-second timeout and 20,000-sample limit; Cbox enforces a 10-second collection deadline and 3-second per-pool timeout.

## Missing data

`up` reflects exporter reachability, while `phpfpm_up` reflects individual pools. In Cbox `v3.1.1`, a single unreachable pool can disappear while healthy pools continue reporting, so compare active series against expected Instances. Cache panels filter on `phpfpm_opcache_enabled == 1` so failed probes show as gaps rather than zero usage.

## Lifecycle

Enabling or disabling Metrics or a Node's exporter converges Caddy scrape sites, Cbox configuration, pool status lines, firewall rules, and Prometheus targets automatically. Removing Metrics or disabling an exporter removes the listeners, exporter unit and binary, pool status lines, and firewall rules while keeping application runtimes, `local.conf`, and `/etc/orbit/service-metrics` intact. Failed Operations preserve step history for recovery without rolling back unrelated Nodes; [Doctor](/cli/doctor/doctor#what-each-family-checks) checks role-service liveness.

## Why it works this way

Scraping Caddy's built-in metrics avoids running a second proxy exporter, and one Cbox service per `app-prod` Node monitors every named production pool without per-Instance daemons or fragile auto-discovery. Monitoring never auto-tunes PHP-FPM or resets OPcache so observation stays free of runtime side effects.
