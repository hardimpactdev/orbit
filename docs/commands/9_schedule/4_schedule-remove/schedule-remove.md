# `orbit schedule:remove <name>`

[Back to Schedule commands.](../README.md)

Remove a recurring Orbit-managed schedule.

Use `schedule:remove` when a recurring task is no longer managed by Orbit. The
command removes gateway intent and cleans up the target node's timer and service
artifacts through the gateway.

## Usage

```bash
orbit schedule:remove <name> [--app=<app>] [--node=<node>] [--force] [--json]
```

## Examples

```bash
orbit schedule:remove laravel-scheduler --app=docs
orbit schedule:remove backups --node=app-1 --force
```

## Arguments And Options

- `name`: schedule slug.
- `--app`: disambiguate an app-scoped schedule.
- `--node`: disambiguate a node-scoped schedule.
- `--force`: Skip destructive confirmation.
- `--json`: Output JSON.

`--app` and `--node` are mutually exclusive filters.

## What Happens

`schedule:remove` resolves the schedule, records removal intent on the gateway,
removes timer and service artifacts from the target node, and finalizes the
schedule removal.

It does not remove app code, app process definitions, nodes, scripts outside
the managed schedule policy, or past run-history records.

## Output

Human output renders a progress tree while the command confirms removal, writes
gateway intent, and cleans up artifacts.

JSON output returns the removed schedule entity and cleanup result.

## Requirements

- The CLI caller can reach the Orbit gateway, or the command runs on the
  gateway.
- The caller is authorized to manage the selected schedule scope.
- The gateway can reach the target node to remove timer and service artifacts.
- Destructive consent is required: confirmation in interactive mode or
  `--force` in non-interactive mode.

## Related

- [`orbit schedule:list`](../2_schedule-list/schedule-list.md)
- [`orbit schedule:add`](../1_schedule-add/schedule-add.md)
- [`doctor --family=schedule`](../schedule-doctor.md)

## Technical Contract

See [`schedule:remove` technical contract](technical/1_schedule-remove.md).
