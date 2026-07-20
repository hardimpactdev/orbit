# `orbit instance:list`

[Back to project and instance commands.](../README.md)

List concrete instances across projects, optionally filtered to one project.

## Usage

```bash
orbit instance:list [--project=<project>] [--json]
```

## Examples

```bash
orbit instance:list
orbit instance:list --project=billing --json
```

## Arguments and options

- `--project`: Limit the inventory to one project.
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
- [`project:list`](../3_project-list/project-list.md)
- [`doctor --family=instance`](../instance-doctor.md)

## Technical Contract

See [`instance:list` technical contract](technical/1_instance-list.md).
