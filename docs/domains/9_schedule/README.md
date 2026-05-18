# Schedule Commands

Schedule commands manage recurring Orbit-owned work. The command family and durable state family are both `schedule`.

The Orbit Scheduler is the schedule executor. It is a resident `orbit-scheduler` Artisan-command daemon supervised by the process manager (Supervisor) on every gateway and app node. Schedule configuration and durable run history live on the gateway; the scheduler reads configuration, dispatches due runs locally, and reports run history back over the existing CLI-to-gateway HTTPS edge.

The scheduler evaluates due schedules at least once per minute, aligned to wall-clock minute boundaries. Each tick fetches the node's schedule list from the gateway, evaluates which schedules are due in the current minute, and fires them. Schedule expressions remain minute-resolution; the tick interval is an implementation detail. `orbit schedule:run` performs one such tick on demand and shares its evaluation logic with the daemon.

## Domain Rules

These rules define how schedule commands behave and what they own.

### Ownership and scope

These rules describe what the schedule command family owns and where each kind of schedule runs.

#### Command and configuration ownership

- The schedule command family owns the `schedule:*` command prefix.
- The gateway is the source of truth for schedule definitions, targets, intervals, execution sources, enabled state, and run history.

#### Schedule scope and target

Schedules may target an app, a node, or Orbit-owned maintenance work. Each scope determines where the schedule runs.

- App-scoped schedules run in the app context on the owning app node.
- Node-scoped schedules run on the selected node.
- Orbit-scoped maintenance schedules run on the gateway by default.
- A Laravel scheduler is a normal app-scoped schedule that runs `php artisan schedule:run` every minute.

A command may override the Orbit-scoped default by documenting another serving node explicitly.

#### Execution source and intervals

- Scheduled work has exactly one execution source: an inline command or a managed script path.
- Intervals use Orbit's portable interval language, such as `every 5 minutes`, `daily at 09:00`, `weekdays at 09:00`, or `weekly on monday at 09:00`.

### Execution and reads

These rules describe how schedule writes propagate to the Orbit Scheduler and how reads source their data.

#### Write propagation

- Schedule write commands mutate gateway configuration first.
- The Orbit Scheduler on the target node observes the change on its next sync,
  claims due runs with a local schedule lock, and executes them.
- There is no per-schedule node-side artifact to apply. The only applied
  artifact is the `orbit_scheduler` Supervisor program, which is applied once
  per node by node provisioning.

#### Reads and adoption

- Schedule reads use gateway configuration and durable run history by default.
- Live scheduler reality belongs to `doctor --family=schedule`.
- The schedule family does not adopt arbitrary observed processes as schedules. Adoption is reserved for explicitly selected runs reported by the Orbit Scheduler that match an existing or operator-supplied schedule shape.

## Schedule JSON Entity

Schedule JSON renderers that return one schedule entity embed this shape under
`success.data.schedule`, or directly under `success.data.schedules[]` for list
items.

```json
{
  "name": "laravel-scheduler",
  "scope": "app",
  "target": {
    "type": "app",
    "name": "docs",
    "node": "app-1"
  },
  "interval": "every minute",
  "timezone": "Europe/Amsterdam",
  "execution": {
    "type": "command",
    "value": "php artisan schedule:run"
  },
  "enabled": true,
  "status": "expected",
  "scheduler": {
    "node": "app-1",
    "heartbeat_at": "2026-05-02T08:00:01Z",
    "registry_synced_at": "2026-05-02T07:59:50Z"
  },
  "last_run": {
    "id": 12,
    "status": "completed",
    "exit_code": 0,
    "started_at": "2026-05-02T08:00:00Z",
    "finished_at": "2026-05-02T08:00:03Z"
  }
}
```

| Field | Type | Meaning |
| --- | --- | --- |
| `name` | string | Schedule slug, unique within the selected scope. |
| `scope` | `app`, `node`, or `orbit` | Scope that owns the schedule. |
| `target.type` | string | Target kind. |
| `target.name` | string | App, node, or Orbit maintenance target. |
| `target.node` | string | Node where recurring artifacts are expected. |
| `interval` | string | Portable Orbit interval expression. |
| `timezone` | string | Timezone used to interpret the interval. |
| `execution.type` | `command` or `script` | Execution source kind. |
| `execution.value` | string | Inline command or managed script path. |
| `enabled` | boolean | Whether the recurring schedule should run. |
| `status` | string | Gateway-configuration status, not live scheduler verification. |
| `scheduler.node` | string | Node where the Orbit Scheduler responsible for this schedule runs. |
| `scheduler.heartbeat_at` | string \| null | ISO-8601 of the most recent scheduler heartbeat reported to the gateway. `null` until the first heartbeat is recorded. |
| `scheduler.registry_synced_at` | string \| null | ISO-8601 of the most recent schedule-configuration sync the scheduler completed. `null` until the first sync is recorded. |
| `last_run` | object \| null | Latest durable run history when available. |

## Commands

Use these commands to manage schedules across the full lifecycle.

1. [`orbit schedule:add`](1_schedule-add/schedule-add.md)
2. [`orbit schedule:list`](2_schedule-list/schedule-list.md)
3. [`orbit schedule:show`](3_schedule-show/schedule-show.md)
4. [`orbit schedule:remove`](4_schedule-remove/schedule-remove.md)
5. [`orbit schedule:run`](5_schedule-run/schedule-run.md)
6. [`orbit schedule:logs`](6_schedule-logs/schedule-logs.md)

## Related

These references cover schedule diagnostics and the neighboring command families that schedules interact with.

- [`doctor --family=schedule`](schedule-doctor.md)
- [`orbit app:*`](../5_app/README.md)
- [`orbit node:*`](../1_node/README.md)
