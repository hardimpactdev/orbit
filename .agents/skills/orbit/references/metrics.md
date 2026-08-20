# Metrics Commands

Metrics commands manage Orbit's optional host-resource observability surface.
Spec: [`apps/docs/content/domains/19_metrics/`](../../../../apps/docs/content/domains/19_metrics/).

The `metrics` role is disabled by default. Enabling it records and starts
Prometheus and Grafana as Docker Swarm process definitions on the selected
metrics node, records and starts node-exporter host binary/tool-backed systemd
processes on the metrics node and active workload nodes, and exposes Grafana
through the private router-owned `https://metrics.orbit` route.

Grafana is provisioned with the Orbit Prometheus datasource and the built-in
`Orbit Node Resources` dashboard. The dashboard has a `node` selector populated
from node-exporter target labels so operators can switch between the metrics
node and scraped workload nodes. Metrics reconvergence also migrates older
Grafana volumes by deleting a stale `Prometheus` datasource in organization 1
before provisioning the pinned `orbit-prometheus` datasource UID.

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
orbit metrics:status --json
orbit metrics:credentials --node=gateway --json
orbit doctor --family=process --node=gateway --restore
```

`metrics:enable` converges the runtime units it owns immediately. Use
`process:*` for deliberate lifecycle actions and process doctor for later
runtime drift. Use tool doctor when the `node_exporter` binary or Docker
substrate is missing. If synchronous convergence leaves the role assignment in
`error`, the command fails with `node_role.convergence_failed` and the errored
assignment remains repairable through node doctor.

V1 tracks host resources only. It does not include alerting, app metrics,
container metrics, database metrics, public Grafana ingress, or per-user
Grafana accounts.
