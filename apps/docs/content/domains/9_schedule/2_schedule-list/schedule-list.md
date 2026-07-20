# `orbit schedule:list`

[Back to Schedule commands.](../README.md)

List schedule configuration stored on the gateway and visible to the caller.

Use `schedule:list` to audit recurring app, node, and Orbit-owned maintenance
schedules without probing live scheduler state.

## Usage

```bash
orbit schedule:list [--instance=<project.instance>] [--node=<node>] [--json]
```

## Examples

```bash
orbit schedule:list
orbit schedule:list --instance=docs.production
orbit schedule:list --node=app-1
```

## Arguments and options

- `--instance`: show schedules for one concrete `project.instance`. A bare project name is
  shorthand only when exactly one eligible instance is visible.
- `--node`: show schedules for one node.
- `--json`: Output JSON.

`--instance` and `--node` are mutually exclusive filters.

## What Happens

Run `schedule:list` when you need to audit what recurring work is configured
for an instance or node. An app filter resolves one concrete instance;
ambiguous bare project filters fail rather than aggregating instance-owned
schedules. `schedule:list` reads schedule configuration and latest durable run
history from the gateway. It does not SSH to nodes, inspect Orbit Scheduler
state, repair drift, or adopt scheduler-side state.

## Output

Pass `--json` to receive machine-readable output; omit it to see a human-readable schedule table grouped by target.

JSON output returns a list of schedule entities with filter metadata.

## Requirements

- The CLI caller can reach the Orbit gateway, or the command runs on the gateway.
- The caller is authorized to inspect schedules for the selected scope.

## Related

- [`orbit schedule:show`](../3_schedule-show/schedule-show.md)
- [`doctor --family=schedule`](../schedule-doctor.md)

## Technical Contract

See [`schedule:list` technical contract](technical/1_schedule-list.md).
