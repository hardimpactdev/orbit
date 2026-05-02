# Schedule Commands

Schedule commands manage recurring Orbit-owned work. The command family and
durable state family are both `schedule`.

Systemd timers and services are the current backend on supported Ubuntu nodes.
They are not the product model.

## Domain Rules

- The schedule command family owns the `schedule:*` command prefix.
- The gateway is the source of truth for schedule definitions, targets,
  intervals, execution sources, enabled state, and run history.
- Schedules may target an app, a node, or Orbit-owned maintenance work.
- App-scoped schedules run in the app context on the owning app node.
- Node-scoped schedules run on the selected node.
- Orbit-scoped maintenance schedules run on the gateway unless a command
  explicitly documents another serving node.
- A Laravel scheduler is a normal app-scoped schedule that runs
  `php artisan schedule:run` every minute.
- Scheduled work has exactly one execution source: an inline command or a
  managed script path.
- Intervals use Orbit's portable interval language, such as `every 5 minutes`,
  `daily at 09:00`, `weekdays at 09:00`, or
  `weekly on monday at 09:00`.
- Schedule write commands mutate gateway intent first, then enact timer and
  service artifacts on the target node through the gateway.
- Schedule reads use gateway intent and durable run history by default. Live
  timer/service reality belongs to `doctor --family=schedule`.
- Backend discovery/import is not part of the schedule command surface.
  Adoption of observed schedule artifacts must use explicit
  `doctor --family=schedule --adopt` semantics.

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
| `status` | string | Gateway-intent status, not live backend verification. |
| `last_run` | object \| null | Latest durable run history when available. |

## Commands

1. [`orbit schedule:add`](1_schedule-add/schedule-add.md)
2. [`orbit schedule:list`](2_schedule-list/schedule-list.md)
3. [`orbit schedule:show`](3_schedule-show/schedule-show.md)
4. [`orbit schedule:remove`](4_schedule-remove/schedule-remove.md)
5. [`orbit schedule:run`](5_schedule-run/schedule-run.md)
6. [`orbit schedule:logs`](6_schedule-logs/schedule-logs.md)

## Related

- [`doctor --family=schedule`](schedule-doctor.md)
- [`orbit app:*`](../5_app/README.md)
- [`orbit node:*`](../1_node/README.md)
