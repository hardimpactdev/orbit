# `orbit schedule:list`

[Back to Schedule commands.](../README.md)

List gateway-owned schedule configuration visible to the caller.

Use `schedule:list` to audit recurring app, node, and Orbit-owned maintenance
schedules without probing live scheduler state.

## Usage

```bash
orbit schedule:list [--app=<app>] [--node=<node>] [--json]
```

## Examples

```bash
orbit schedule:list
orbit schedule:list --app=docs
orbit schedule:list --node=app-1
```

## Arguments And Options

- `--app`: show schedules for one app.
- `--node`: show schedules for one node.
- `--json`: Output JSON.

`--app` and `--node` are mutually exclusive filters.

## What Happens

`schedule:list` reads schedule configuration and latest durable run history from the gateway. It does not SSH to nodes, inspect Orbit Scheduler state, repair drift, or adopt scheduler-side state.

## Output

Human output renders a schedule table grouped by target.

JSON output returns a list of schedule entities with filter metadata.

## Requirements

- The CLI caller can reach the Orbit gateway, or the command runs on the gateway.
- The caller is authorized to inspect schedules for the selected scope.

## Related

- [`orbit schedule:show`](../3_schedule-show/schedule-show.md)
- [`doctor --family=schedule`](../schedule-doctor.md)

## Technical Contract

See [`schedule:list` technical contract](technical/1_schedule-list.md).
