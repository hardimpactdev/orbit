# Metrics Concepts

This document defines metrics-domain vocabulary and invariants. It supports the
metrics command contracts. It does not override the
[Architecture](../../architecture.md).

## Vocabulary

Use these terms when reading or writing metrics command contracts.

- **Metrics command domain:** Command family for role-backed host-resource
  observability workflows. It owns `metrics:*` command contracts but does not
  own a state family.
- **Metrics role:** Optional private workload role that records Prometheus and
  Grafana process intent for the metrics node and node-exporter tool/process
  intent for the metrics node plus active workload nodes. It can be dedicated or
  co-located with any non-agent role, including a Debian gateway.
- **Metrics backend:** The process-owned runtime set coordinated by the metrics
  role baseline: Prometheus, Grafana, and node-exporter. The node-exporter host
  binary capability is recorded as tool intent.
- **Metrics service endpoint:** Stable private HTTPS endpoint
  `https://metrics.orbit`, served by router-owned proxy state and targeting
  Grafana.
- **Prometheus service process:** Docker Swarm process definition named
  `prometheus`; stores local host-resource time series with 15 day retention.
- **Grafana service process:** Docker Swarm process definition named `grafana`;
  serves dashboards behind `metrics.orbit` and stores generated admin
  credentials in process runtime configuration.
- **node-exporter host process:** Systemd process definition named
  `node-exporter`; depends on the `node-exporter` host binary tool and exposes
  host resource metrics for the metrics node and active workload nodes.
- **Grafana admin credentials:** Generated `admin` username and password for
  Grafana, returned by `metrics:credentials` and rotated by
  `metrics:credentials --reset`.
- **Metrics-domain boundaries:** Metrics commands coordinate node role
  assignment, Docker capability, node-exporter host binary capability, process
  definitions, and private proxy route intent. Drift and repair remain with
  `node`, `tool`, `process`, and `proxy`.
- **Metrics-domain exclusions:** Metrics does not own app metrics, container
  metrics, database metrics, alerting, public Grafana ingress, dynamic scrape
  discovery, or a `metrics` doctor state family.
