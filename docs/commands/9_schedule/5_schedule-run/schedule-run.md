# `orbit schedule:run [name]`

[Back to Schedule commands.](../README.md)

Run a schedule once immediately.

Use `schedule:run` to test a recurring schedule, run a missed task, or execute a scheduled maintenance command on demand. The recurring interval is not changed.

## Usage

```bash
orbit schedule:run [name] [--app=<app>] [--node=<node>] [--json]
```

## Examples

```bash
orbit schedule:run laravel-scheduler --app=docs
orbit schedule:run backups --node=app-1
```

## Arguments and options

- `name`: schedule slug. When omitted in interactive mode, Orbit shows a
  schedule data table.
- `--app`: disambiguate an app-scoped schedule.
- `--node`: disambiguate a node-scoped schedule.
- `--json`: Output JSON.

`--app` and `--node` are mutually exclusive filters.

## What Happens

Use `schedule:run` when you want to test a schedule or run a missed task on demand. `schedule:run` resolves the schedule from gateway configuration, executes its stored command or script once on the target node through the gateway, and records the run output in gateway schedule history.

It does not change the recurring interval, enabled state, app process definitions, or schedule ownership.

## Output

Run without `--json` to see progress and stream the scheduled command output.

JSON output returns a bounded run result with captured output. Scheduled command
failure is represented as an Orbit command failure with the scheduled process
result captured in machine-readable data.

## Requirements

- The CLI caller can reach the Orbit gateway, or the command runs on the gateway.
- The caller is authorized to run the selected schedule.
- The gateway can reach the target node to execute the scheduled command or script.

## Related

- [`orbit schedule:logs`](../6_schedule-logs/schedule-logs.md)
- [`orbit schedule:show`](../3_schedule-show/schedule-show.md)
- [`doctor --family=schedule`](../schedule-doctor.md)

## Technical Contract

See [`schedule:run` technical contract](technical/1_schedule-run.md).
