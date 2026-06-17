# Metrics Commands

Metrics commands manage Orbit's optional host-resource observability surface.
Spec: [`apps/docs/content/domains/20_metrics/`](../../../apps/docs/content/domains/20_metrics/).

The `metrics` role is disabled by default. Enabling it records Prometheus and
Grafana as Docker Swarm process definitions on the selected metrics node,
records node-exporter host binary tool intent and systemd process intent on the
metrics node and active workload nodes, and exposes Grafana through the private
router-owned `https://metrics.orbit` route.

Metrics is not its own doctor state family. State is coordinated through:
`node` for the role assignment, `tool` for Docker and node-exporter host
binary capabilities, `process` for Prometheus/Grafana/node-exporter lifecycle,
and `proxy` for `metrics.orbit`.

## Commands

```bash
orbit metrics:enable --node=<node> [--json]
orbit metrics:disable --node=<node> --force [--purge-data] [--json]
orbit metrics:status [--node=<node>] [--json]
orbit metrics:credentials [--node=<node>] [--reset] [--json]
```

Examples:

```bash
orbit metrics:enable --node=gateway --json
orbit doctor --family=tool --node=gateway --fix --restore
orbit doctor --family=process --node=gateway --fix --restore
orbit metrics:status --json
orbit metrics:credentials --node=gateway --json
```

`metrics:enable` records desired state only; it does not synchronously start
Prometheus, Grafana, or node-exporter. Use process commands or process doctor
for runtime lifecycle. Use tool doctor when the `node_exporter` binary or
Docker substrate is missing.

V1 tracks host resources only. It does not include alerting, app metrics,
container metrics, database metrics, public Grafana ingress, or per-user
Grafana accounts.
