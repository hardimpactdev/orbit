# `orbit schedule:logs [name]`

[Back to Schedule commands.](../README.md)

Show stored output for schedule runs.

Use `schedule:logs` to inspect durable schedule run history captured by the gateway. The command is for past run output, not live scheduler inspection.

## Usage

```bash
orbit schedule:logs [name] [--app=<app>] [--node=<node>] [--run=<id>] [--lines=<count>] [--json]
```

## Examples

```bash
orbit schedule:logs laravel-scheduler --app=docs
orbit schedule:logs backups --node=app-1 --run=19
orbit schedule:logs backups --node=app-1 --lines=200
```

## Arguments and options

- `name`: schedule slug. When omitted in interactive mode, Orbit shows a
  schedule data table.
- `--app`: disambiguate an app-scoped schedule.
- `--node`: disambiguate a node-scoped schedule.
- `--run`: specific run id. Defaults to the latest run.
- `--lines`: maximum captured output lines to render. Defaults to the command renderer limit.
- `--json`: Output JSON.

`--app` and `--node` are mutually exclusive filters.

## What Happens

Run `schedule:logs` when you need to inspect the output of a past schedule run. `schedule:logs` reads the selected schedule and stored run output from gateway history. It does not SSH to nodes, inspect scheduler container logs directly, repair drift, or mutate schedule configuration.

## Output

Pass `--json` to receive machine-readable output; omit it to see run metadata followed by captured stdout and stderr.

JSON output returns the selected run and captured output.

## Requirements

- The CLI caller can reach the Orbit gateway, or the command runs on the gateway.
- The caller is authorized to inspect schedule run history for the selected scope.

## Related

- [`orbit schedule:run`](../5_schedule-run/schedule-run.md)
- [`orbit schedule:show`](../3_schedule-show/schedule-show.md)
- [`doctor --family=schedule`](../schedule-doctor.md)

## Technical Contract

See [`schedule:logs` technical contract](technical/1_schedule-logs.md).
