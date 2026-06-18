# Metrics Concepts

This document defines metrics-domain vocabulary and invariants. It supports the
metrics command contracts. It does not override the
[Architecture](../../architecture.md).

## Vocabulary

Use these terms when reading or writing metrics command contracts.

- **Metrics command domain:** Command family for role-backed host-resource
  observability workflows. It owns `metrics:*` command contracts but does not
  own a state family.
- **Metrics role:** Optional private workload role that records and starts
  Prometheus and Grafana process runtimes for the metrics node and records,
  installs, and starts node-exporter tool/process runtime for the metrics node
  plus active workload nodes. It can be dedicated or co-located with any
  non-agent role, including a Debian gateway.
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
- **Orbit node resources dashboard:** Grafana dashboard named
  `Orbit Node Resources`. The metrics role provisions it with the Orbit
  Prometheus datasource and a `node` variable sourced from node-exporter target
  labels so operators can switch between metrics and workload nodes.
- **node-exporter host process:** Systemd process definition named
  `node-exporter`; depends on the `node-exporter` host binary tool and exposes
  host resource metrics for the metrics node and active workload nodes.
- **Exporter firewall rule:** Protected firewall intent named
  `orbit-metrics-node-exporter`; allows the metrics node to scrape TCP port
  9100 on Ubuntu exporter hosts through the private WireGuard interface.
- **Grafana admin credentials:** Generated `admin` username and password for
  Grafana, returned by `metrics:credentials` and rotated by
  `metrics:credentials --reset`.
- **Metrics-domain boundaries:** Metrics commands coordinate node role
  assignment, Docker capability, node-exporter host binary capability, process
  definitions, firewall intent for private node-exporter scrape access,
  immediate runtime convergence, and private proxy route intent. Later drift and
  repair remain with `node`, `tool`, `process`, `firewall_rule`, and `proxy`.
- **Metrics-domain exclusions:** Metrics does not own app metrics, container
  metrics, database metrics, alerting, public Grafana ingress, dynamic scrape
  discovery, or a `metrics` doctor state family.
