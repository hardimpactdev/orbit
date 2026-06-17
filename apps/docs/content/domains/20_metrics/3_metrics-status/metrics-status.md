# `orbit metrics:status`

[Back to Metrics commands.](../README.md)

Show metrics role status from gateway configuration.

## Usage

```bash
orbit metrics:status [--node=<node>] [--json]
```

## Examples

```bash
orbit metrics:status
orbit metrics:status --node=metrics-1
orbit metrics:status --json
```

## Arguments and options

- `--node`: optional active metrics node to inspect.
- `--json`: output JSON.

## What Happens

Run this command to read gateway-owned metrics role and process intent. It returns the
private Grafana URL and the metrics process definitions recorded for each
visible metrics node. It does not SSH to nodes, probe live process managers, or
check whether Grafana or Prometheus is currently healthy.

## Output

Human output is a table with node, URL, and process names. Pass `--json` for
the full process name/runtime payload.

## Requirements

- The caller can reach the Orbit gateway.
- The caller is authorized for `process:read` on each returned metrics node.
- If `--node` is supplied, it must select an active metrics node visible to the
  caller.

## Related Commands

Use these commands when status output points at missing runtime or credential state.

- [`orbit metrics:enable`](../1_metrics-enable/metrics-enable.md)
- [`orbit metrics:credentials`](../4_metrics-credentials/metrics-credentials.md)
- [`orbit process:list`](../../7_process/4_process-list/process-list.md)
- [`orbit doctor --family=process`](../../7_process/process-doctor.md)
- [Technical contract](technical/1_metrics-status.md)
