# `orbit analytics:update`

[Back to Analytics commands.](../README.md)

Update the Plausible CE version used by an active analytics role node.

## Usage

```bash
orbit analytics:update [--node=<node>] [--version=<version>] [--json]
```

## Arguments and options

- `--version`: Plausible CE version to apply. Required. Use a plain semantic
  version such as `3.2.2`.
- `--node`: active analytics role node to update. Optional when exactly one
  visible active analytics node exists.
- `--json`: Output JSON.

## Behavior Summary

`analytics:update` resolves the active analytics node, updates the Plausible CE
process row to the requested version, and asks the process family to apply the
runtime change. Version tracking stays on the process row and its runtime
configuration; there is no tool-owned Plausible lifecycle and no
`--plausible-version` option.

The command does not change app analytics bindings, public tracking hosts,
Plausible site configuration, or tracking script installation.

## Requirements

- The CLI caller can reach the Orbit gateway.
- The caller is authorized for `process:update` on the selected analytics node.
- The selected node has an active `analytics` role.
- The Plausible CE process row exists for the selected analytics node.

## Output Summary

Human output reports the selected node, previous version, requested version,
and process status. JSON output returns the same update payload in the standard
machine-readable envelope.

## Examples

```bash
orbit analytics:update --version=3.2.2
orbit analytics:update --node=analytics-1 --version=3.2.2
orbit analytics:update --version=3.2.2 --json
```

## Related

- [`orbit app:analytics enable`](../../5_app/16_app-analytics-enable/app-analytics-enable.md)
- [`orbit process:list`](../../7_process/4_process-list/process-list.md)
- [`orbit process:restart`](../../7_process/7_process-restart/process-restart.md)
- [Technical contract](technical/1_analytics-update.md)
