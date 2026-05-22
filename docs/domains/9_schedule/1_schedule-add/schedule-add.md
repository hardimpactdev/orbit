# `orbit schedule:add [name]`

[Back to Schedule commands.](../README.md)

Create a recurring Orbit-managed schedule.

Use `schedule:add` when an app or node needs recurring work managed by Orbit. The command records schedule configuration on the gateway. The Orbit Scheduler on the target node picks up the new schedule on its next sync.

## Usage

```bash
orbit schedule:add [name] (--command=<command>|--script=<path>) --interval=<expression> [--app=<app>|--node=<node>] [--timezone=<timezone>] [--json]
orbit schedule:add
```

## Examples

```bash
orbit schedule:add laravel-scheduler --app=docs --command="php artisan schedule:run" --interval="every minute"
orbit schedule:add backups --node=app-1 --script=/opt/orbit/schedules/backup.sh --interval="daily at 02:00" --timezone=Europe/Amsterdam
```

## Arguments and options

- `name`: schedule slug, unique within the selected app or node scope.
- `--app`: app target for an app-scoped schedule.
- `--node`: node target for a node-scoped schedule.
- `--command`: inline command to run as the scheduled work.
- `--script`: managed script path to run as the scheduled work.
- `--interval`: portable Orbit interval expression, such as `every 5 minutes`, `daily at 09:00`, `weekdays at 09:00`, or `weekly on monday at 09:00`.
- `--timezone`: timezone used to interpret the interval. Defaults to the target app, node, or gateway timezone.
- `--json`: Output JSON.

Exactly one target selector is required after defaults are applied: `--app` or `--node`. Exactly one execution source is required: `--command` or `--script`.

Orbit-owned maintenance schedules may be created by lifecycle commands, but this public command only creates app-scoped or node-scoped schedules.

## What Happens

Use `schedule:add` when you need to define a new recurring task for an app or node. `schedule:add` validates the target, validates the execution source and interval, and writes gateway schedule configuration. The Orbit Scheduler (gateway-only) reads the gateway database every tick and dispatches due schedules to the resolved target via `RemoteShell`; target node SSH reachability is verified at dispatch time, not at `schedule:add` time.

It does not create apps, nodes, app process definitions, proxy routes, firewall rules, or schedules that exist only on the scheduler side outside gateway configuration.

## Output

Run without `--json` to see progress while the command validates input and writes gateway configuration.

JSON output returns the created schedule entity.

## Requirements

- The CLI caller can reach the Orbit gateway, or the command runs on the gateway.
- The caller is authorized to manage schedules for the resolved app or node.
- The gateway Orbit Scheduler is registered in `orbit-runtime`, and the target
  node is reachable for dispatch when the schedule does not run on the gateway.
- Script sources must resolve to readable script files according to the gateway-owned schedule policy.

## Related

- [`orbit schedule:list`](../2_schedule-list/schedule-list.md)
- [`orbit schedule:show`](../3_schedule-show/schedule-show.md)
- [`doctor --family=schedule`](../schedule-doctor.md)

## Technical Contract

See [`schedule:add` technical contract](technical/1_schedule-add.md).
