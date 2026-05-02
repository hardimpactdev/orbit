# `orbit schedule:show <name>`

[Back to Schedule commands.](../README.md)

Show one schedule's target, interval, execution source, and recent run state.

Use `schedule:show` when inspecting the durable configuration of a specific
recurring task. The command reads gateway intent; live timer drift belongs to
doctor.

## Usage

```bash
orbit schedule:show <name> [--app=<app>] [--node=<node>] [--json]
```

## Examples

```bash
orbit schedule:show laravel-scheduler --app=docs
orbit schedule:show backups --node=app-1
```

## Arguments And Options

- `name`: schedule slug.
- `--app`: disambiguate an app-scoped schedule.
- `--node`: disambiguate a node-scoped schedule.
- `--json`: Output JSON.

`--app` and `--node` are mutually exclusive filters.

## What Happens

`schedule:show` reads one schedule and latest durable run history from the
gateway. It does not SSH to nodes, inspect systemd timers, repair drift, or
adopt backend artifacts.

## Output

Human output renders a detail view for the schedule.

JSON output returns one schedule entity.

## Requirements

- The CLI caller can reach the Orbit gateway, or the command runs on the
  gateway.
- The caller is authorized to inspect the selected schedule scope.

## Related

- [`orbit schedule:list`](../2_schedule-list/schedule-list.md)
- [`orbit schedule:run`](../5_schedule-run/schedule-run.md)
- [`doctor --family=schedule`](../schedule-doctor.md)

## Technical Contract

See [`schedule:show` technical contract](technical/1_schedule-show.md).
