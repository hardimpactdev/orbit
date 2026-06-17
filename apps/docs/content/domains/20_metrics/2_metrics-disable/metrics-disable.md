# `orbit metrics:disable --node=<node> --force`

[Back to Metrics commands.](../README.md)

Disable the metrics role on a node.

## Usage

```bash
orbit metrics:disable --node=<node> --force [--purge-data] [--json]
```

## Examples

```bash
orbit metrics:disable --node=app-1 --force
orbit metrics:disable --node=metrics-1 --force --purge-data --json
```

## Arguments and options

- `--node`: node whose `metrics` role should be removed.
- `--force`: required explicit consent to remove the metrics role and
  Orbit-owned metrics intent.
- `--purge-data`: request deletion of metrics-owned data where the role cleanup
  supports it. Requires `--force`.
- `--json`: output JSON.

## What Happens

Run this command to remove the `metrics` role through the same role removal path as
`node role:remove`. It removes Orbit-owned metrics process intent and the
router-owned `metrics.orbit` route while preserving user data unless
`--purge-data` is supplied. When the removed role is the last active metrics
role, Orbit also removes the workload node-exporter process intent created by
metrics convergence and the matching node-exporter tool intent.

## Output

Human output reports the removed role and whether data purge was requested.
Pass `--json` to receive the gateway role-removal payload.

## Requirements

- The caller can reach the Orbit gateway.
- The caller is authorized for `role:remove` on the target node.
- The target node has a metrics role assignment.
- `--force` is present.

## Related Commands

Use these commands to re-enable metrics or verify cleanup after disabling it.

- [`orbit metrics:enable`](../1_metrics-enable/metrics-enable.md)
- [`orbit metrics:status`](../3_metrics-status/metrics-status.md)
- [`orbit doctor --family=node`](../../1_node/node-doctor.md)
- [Technical contract](technical/1_metrics-disable.md)
