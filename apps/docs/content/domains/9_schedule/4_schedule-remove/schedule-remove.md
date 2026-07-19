# `orbit schedule:remove [name]`

[Back to Schedule commands.](../README.md)

Remove a recurring Orbit-managed schedule.

Use `schedule:remove` when a recurring task should be removed from Orbit management. The command removes gateway configuration. The Orbit Scheduler (gateway-only) stops dispatching the schedule on its next tick; there is no node-side scheduler state to clean up.

## Usage

```bash
orbit schedule:remove [name] [--app=<app>] [--node=<node>] [--force] [--json]
```

## Examples

```bash
orbit schedule:remove laravel-scheduler --app=docs.production
orbit schedule:remove backups --node=app-1 --force
```

## Arguments and options

- `name`: schedule slug. When omitted in interactive mode, Orbit shows a
  schedule data table.
- `--app`: select the owning `app.instance`. A bare app name is shorthand only
  when exactly one eligible instance is visible.
- `--node`: disambiguate a node-scoped schedule.
- `--force`: Skip destructive confirmation.
- `--json`: Output JSON.

`--app` and `--node` are mutually exclusive filters.

## What Happens

Run `schedule:remove` when a recurring task should be removed from Orbit
management. `schedule:remove` resolves one concrete schedule owner before
asking for or applying destructive consent, then removes the gateway schedule
row. Ambiguous app selectors fail without deletion. Subsequent
gateway-scheduler ticks skip the removed schedule.

It does not remove app code, app-instance process definitions, nodes, scripts outside the managed schedule policy, or past run-history records.

## Output

Run without `--json` to see progress while the command confirms removal and writes gateway configuration.

JSON output returns the removed schedule entity.

## Requirements

- The CLI caller can reach the Orbit gateway, or the command runs on the gateway.
- The caller is authorized to manage the selected schedule scope.
- Destructive consent is required: confirmation in interactive mode or `--force` in non-interactive mode.

## Related

- [`orbit schedule:list`](../2_schedule-list/schedule-list.md)
- [`orbit schedule:add`](../1_schedule-add/schedule-add.md)
- [`doctor --family=schedule`](../schedule-doctor.md)

## Technical Contract

See [`schedule:remove` technical contract](technical/1_schedule-remove.md).
