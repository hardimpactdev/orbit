# `orbit schedule:add [name]`

[Back to Schedule commands.](../README.md)

Create a recurring Orbit-managed schedule.

Use `schedule:add` when an app or node needs recurring work managed by Orbit. The command records schedule configuration on the gateway. The gateway-only Orbit Scheduler reads the gateway database each tick and dispatches the schedule to its resolved target through `internal:schedule:run` over agent-push, running locally only when the target is the gateway.

## Usage

```bash
orbit schedule:add [name] (--command=<command>|--script=<path>) --interval=<expression> [--instance=<project.instance>|--node=<node>] [--timezone=<timezone>] [--timeout=<seconds>] [--json]
orbit schedule:add
```

## Examples

```bash
orbit schedule:add laravel-scheduler --instance=docs.production --command="php artisan schedule:run" --interval="every minute"
orbit schedule:add backups --node=app-1 --script=/opt/orbit/schedules/backup.sh --interval="daily at 02:00" --timezone=Europe/Amsterdam
orbit schedule:add catalogue-sync --instance=mealou.production --command="php artisan food-catalog:sync --json" --interval="weekly on monday at 02:30" --timeout=7200
```

## Arguments and options

- `name`: schedule slug, unique within the selected concrete instance or node target.
- `--instance`: concrete `app.instance` target for an instance-scoped schedule. A bare
  project name is shorthand only when exactly one eligible instance is visible.
- `--node`: node target for a node-scoped schedule.
- `--command`: inline command to run as the scheduled work.
- `--script`: managed script path to run as the scheduled work.
- `--interval`: portable Orbit interval expression, such as `every 5 minutes`, `daily at 09:00`, `weekdays at 09:00`, or `weekly on monday at 09:00`.
- `--timezone`: timezone used to interpret the interval. Defaults to the target instance, node, or gateway timezone.
- `--timeout`: maximum execution time in seconds. Defaults to `900`; accepts `1` through `86400`.
- `--json`: Output JSON.

Exactly one target selector is required after defaults are applied: `--instance` or `--node`. Exactly one execution source is required: `--command` or `--script`.

Orbit-owned maintenance schedules may be created by lifecycle commands, but this public command only creates instance-scoped or node-scoped schedules.

## What Happens

Use `schedule:add` when you need to define a new recurring task for one app
instance or node. `schedule:add` resolves concrete ownership before writing,
validates the target, execution source, interval, and timeout, and writes gateway
schedule configuration. Ambiguous bare project selectors fail before the schedule
row is created. The Orbit Scheduler (gateway-only) reads the gateway database
every tick and dispatches due schedules to the resolved target through
agent-push; target node agent-push reachability is verified at dispatch time,
not at `schedule:add` time.

It does not create apps, nodes, instance process definitions, proxy routes, firewall rules, or schedules that exist only on the scheduler side outside gateway configuration.

## Output

Run without `--json` to see progress while the command validates input and writes gateway configuration.

JSON output returns the created schedule entity.

## Requirements

- The CLI caller can reach the Orbit gateway, or the command runs on the gateway.
- The caller is authorized to manage schedules on the selected instance's
  serving node or selected node.
- The gateway Orbit Scheduler is registered as `orbit-scheduler`, and the target
  node is reachable for dispatch when the schedule does not run on the gateway.
- Script sources must resolve to readable script files according to the gateway-owned schedule policy.

## Related

- [`orbit schedule:list`](../2_schedule-list/schedule-list.md)
- [`orbit schedule:show`](../3_schedule-show/schedule-show.md)
- [`doctor --family=schedule`](../schedule-doctor.md)

## Technical Contract

See [`schedule:add` technical contract](technical/1_schedule-add.md).
