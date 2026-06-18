# Metrics

## Purpose

Metrics is Orbit's optional host-resource observability surface. Enable the
`metrics` role on a node when you want Prometheus and Grafana on that node,
node-exporter on metrics and workload nodes, a Grafana dashboard named
`Orbit Node Resources`, and the private `metrics.orbit` route.

## Responsibilities

Metrics commands own the operator workflow for enabling metrics, disabling it,
reading status, and retrieving or rotating Grafana admin credentials.

Use the command pages below for the stable operator contract.

- [`metrics:enable`](1_metrics-enable/metrics-enable.md)
- [`metrics:disable`](2_metrics-disable/metrics-disable.md)
- [`metrics:status`](3_metrics-status/metrics-status.md)
- [`metrics:credentials`](4_metrics-credentials/metrics-credentials.md)

## Boundaries

Metrics does not own a state family. Node role readiness, Docker and
node-exporter host binary capabilities, process runtimes, private scrape
firewall rules, and proxy routes remain owned by `node`, `tool`, `process`,
`firewall_rule`, and `proxy`.
