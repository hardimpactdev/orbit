# `orbit schedule:show [name]`

[Back to Schedule commands.](../README.md)

Show one schedule's target, interval, execution source, and recent run state.

Use `schedule:show` when inspecting the durable configuration of a specific recurring task. The command reads gateway configuration; live scheduler drift belongs to doctor.

## Usage

```bash
orbit schedule:show [name] [--app=<app>] [--node=<node>] [--json]
```

## Examples

```bash
orbit schedule:show laravel-scheduler --app=docs.production
orbit schedule:show backups --node=app-1
```

## Arguments and options

- `name`: schedule slug. When omitted in interactive mode, Orbit shows a
  schedule data table.
- `--app`: select the owning `app.instance`. A bare app name is shorthand only
  when exactly one eligible instance is visible.
- `--node`: disambiguate a node-scoped schedule.
- `--json`: Output JSON.

`--app` and `--node` are mutually exclusive filters.

## What Happens

Run `schedule:show` when you need the full detail view for a specific schedule. `schedule:show` reads one schedule and latest durable run history from the gateway. It does not SSH to nodes, inspect Orbit Scheduler state, repair drift, or adopt scheduler-side state.

## Output

Pass `--json` to receive machine-readable output; omit it to see a detail view for the schedule.

JSON output returns one schedule entity.

## Requirements

- The CLI caller can reach the Orbit gateway, or the command runs on the gateway.
- The caller is authorized to inspect the selected schedule scope.

## Related

- [`orbit schedule:list`](../2_schedule-list/schedule-list.md)
- [`orbit schedule:run`](../5_schedule-run/schedule-run.md)
- [`doctor --family=schedule`](../schedule-doctor.md)

## Technical Contract

See [`schedule:show` technical contract](technical/1_schedule-show.md).
