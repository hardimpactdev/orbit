# `orbit metrics:credentials`

[Back to Metrics commands.](../README.md)

Show or reset Grafana admin credentials for the metrics role.

## Usage

```bash
orbit metrics:credentials [--node=<node>] [--reset] [--json]
```

## Examples

```bash
orbit metrics:credentials
orbit metrics:credentials --node=metrics-1
orbit metrics:credentials --node=metrics-1 --reset --json
```

## Arguments and options

- `--node`: optional active metrics node. Required when more than one active
  metrics node is visible.
- `--reset`: rotate the Grafana admin password before returning credentials.
- `--json`: output JSON.

## What Happens

Run this command to read the generated Grafana admin credentials stored in the selected
metrics node's `grafana` process runtime configuration. With `--reset`, it
generates a new password, writes it into the Grafana process runtime
configuration, refreshes the process spec hash, and returns the new value.

The command does not log secret values to activity properties.

## Output

Human output renders the selected node, private Grafana URL, admin username,
and admin password. Pass `--json` for the same values in a machine-readable
envelope.

## Requirements

- The caller can reach the Orbit gateway.
- The caller is authorized for `tool:credentials` on the selected active metrics
  node.
- At least one active metrics node exists, or `--node` selects an active metrics
  node.
- The selected metrics node has a `grafana` process with credentials in runtime
  configuration.

## Related Commands

Use these commands to inspect metrics state before or after reading credentials.

- [`orbit metrics:status`](../3_metrics-status/metrics-status.md)
- [`orbit process:list`](../../7_process/4_process-list/process-list.md)
- [`orbit doctor --family=process`](../../7_process/process-doctor.md)
- [Technical contract](technical/1_metrics-credentials.md)
