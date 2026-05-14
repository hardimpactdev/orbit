# `orbit schedule:remove <name>`

[Back to Schedule commands.](../README.md)

Remove a recurring Orbit-managed schedule.

Use `schedule:remove` when a recurring task is no longer managed by Orbit. The command removes gateway configuration. The target node's Orbit Scheduler stops evaluating the schedule on its next sync.

## Usage

```bash
orbit schedule:remove <name> [--app=<app>] [--node=<node>] [--force] [--json]
```

## Examples

```bash
orbit schedule:remove laravel-scheduler --app=docs
orbit schedule:remove backups --node=app-1 --force
```

## Arguments and options

- `name`: schedule slug.
- `--app`: disambiguate an app-scoped schedule.
- `--node`: disambiguate a node-scoped schedule.
- `--force`: Skip destructive confirmation.
- `--json`: Output JSON.

`--app` and `--node` are mutually exclusive filters.

## What Happens

Run `schedule:remove` when a recurring task no longer needs to be managed by Orbit. `schedule:remove` resolves the schedule, records removal on the gateway, and confirms the target node's Orbit Scheduler is reachable so the removal is picked up promptly.

It does not remove app code, app process definitions, nodes, scripts outside the managed schedule policy, or past run-history records.

## Output

Run without `--json` to see a progress tree while the command confirms removal, writes gateway configuration, and notifies the target node's Orbit Scheduler.

JSON output returns the removed schedule entity and scheduler-pickup result.

## Requirements

- The CLI caller can reach the Orbit gateway, or the command runs on the gateway.
- The caller is authorized to manage the selected schedule scope.
- The target node's Orbit Scheduler is registered and the process manager (Supervisor) is reachable.
- Destructive consent is required: confirmation in interactive mode or `--force` in non-interactive mode.

## Related

- [`orbit schedule:list`](../2_schedule-list/schedule-list.md)
- [`orbit schedule:add`](../1_schedule-add/schedule-add.md)
- [`doctor --family=schedule`](../schedule-doctor.md)

## Technical Contract

See [`schedule:remove` technical contract](technical/1_schedule-remove.md).
