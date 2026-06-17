# `orbit metrics:enable --node=<node>`

[Back to Metrics commands.](../README.md)

Enable the metrics role on an existing node.

## Usage

```bash
orbit metrics:enable --node=<node> [--json]
```

## Examples

```bash
orbit metrics:enable --node=app-1
orbit metrics:enable --node=gateway-1 --json
```

## Arguments and options

- `--node`: existing active node that should receive the `metrics` role.
- `--json`: output JSON.

## What Happens

Run this command to add the `metrics` role to the target node through the same
role assignment path as `node role:add`.

Role convergence records:

- Docker substrate and node-exporter host binary tool intent.
- Prometheus and Grafana Docker Swarm process definitions on the metrics node.
- node-exporter systemd process definitions on metrics and active workload nodes.
- The router-owned `metrics.orbit` route and Grafana admin credentials.

The command immediately converges and starts Prometheus, Grafana, and
node-exporter. Later runtime drift is repaired through
`doctor --family=process`; direct lifecycle actions still belong to `process:*`.

## Output

Human output reports that the metrics role was assigned. Pass `--json` to
receive the role assignment payload returned by the gateway.

## Requirements

- The caller can reach the Orbit gateway.
- The caller is authorized for `role:add` on the target node.
- The target node is active, supports the metrics role platform, and does not
  carry the `agent` role.

## Related Commands

Use these commands to inspect or repair metrics after enabling the role.

- [`orbit metrics:status`](../3_metrics-status/metrics-status.md)
- [`orbit metrics:credentials`](../4_metrics-credentials/metrics-credentials.md)
- [`orbit process:list --node=<node>`](../../7_process/4_process-list/process-list.md)
- [`orbit process:restart --node=<node>`](../../7_process/7_process-restart/process-restart.md)
- [`orbit doctor --family=process`](../../7_process/process-doctor.md)
- [Technical contract](technical/1_metrics-enable.md)
