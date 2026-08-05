# `orbit instance:list`

[Back to app and instance commands.](../README.md)

List concrete instances across apps, optionally filtered to one app.

## Usage

```bash
orbit instance:list [--app<project>] [--json]
```

## Examples

```bash
orbit instance:list
orbit instance:list --appbilling --json
```

## Arguments and options

- `--app`: Limit the inventory to one app.
- `--json`: Emit the shared machine-readable envelope.

## What Happens

Orbit reads visible instances from the gateway, filters them through
`instance:read` on each Orbit serving node, and returns each concrete placement
once. Laravel Cloud instances are visible only through the gateway authority
path.

## Output

Human output is a table with project, instance, driver, runtime mode, PHP
version, extensions, and deployment status. The
[JSON renderer contract](technical/6.2_instance-list_output-render_json.md)
defines the machine-readable inventory.

## Requirements

- The CLI caller can reach the Orbit gateway.
- The caller can read at least one selected instance.

## Related Commands

- [`instance:show`](../26_instance-show/instance-show.md)
- [`app:list`](../3_app-list/app-list.md)
- [`doctor --family=instance`](../instance-doctor.md)

## Technical Contract

See [`instance:list` technical contract](technical/1_instance-list.md).
