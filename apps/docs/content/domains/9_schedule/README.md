# Schedule Commands

Schedule commands manage recurring Orbit-owned work. The command family and durable state family are both `schedule`.

The Orbit Scheduler is the schedule executor. It is a resident
`orbit-scheduler` Swarm service using the Orbit gateway image. Schedule
definitions, locks, heartbeat, and durable run history all live in the gateway
database.

The scheduler evaluates due schedules at least once per minute, aligned to wall-clock minute boundaries. Each tick reads every enabled schedule from the gateway database, claims a per-schedule lock, then dispatches each due schedule. Schedules whose target resolves to the gateway run locally; schedules targeting any other node execute on that node through the signed `internal:schedule:run` local-executor command over agent-push.

User authorization is checked when a caller manages a schedule or manually
requests a run. Recurring execution is initiated by the gateway and does not
re-check a user permission. The gateway resolves the persisted target, then
delivers the schedule payload through the authenticated typed local-executor
surface. The result of every run — success, failure, exit code, captured
output, dispatch failure — is recorded centrally in `schedule_runs`. Schedule
expressions remain minute-resolution; the tick interval is an implementation
detail. `orbit schedule:run` performs one such tick on demand and shares its
evaluation logic with the daemon.

## Domain Rules

These rules define how schedule commands behave and what they own.

### Ownership and scope

These rules describe what the schedule command family owns and where each kind of schedule runs.

#### Command and configuration ownership

- The schedule command family owns the `schedule:*` command prefix.
- The gateway is the source of truth for schedule definitions, targets, intervals, execution sources, enabled state, and run history.

#### Schedule scope and target

Schedules may target one concrete instance, a node, or Orbit-owned
maintenance work. The scope determines which node physically runs the command;
the gateway always dispatches.

- Instance-scoped schedules belong to exactly one instance and execute on that
  instance's serving node and path using its host PHP toolchain (the same
  placement contract deploy steps use).
- Node-scoped schedules execute on the selected node.
- Orbit-scoped maintenance schedules execute inside the gateway container
  boundary by default. A command may override that default by documenting
  another serving node explicitly.
- A Laravel scheduler is a normal instance-scoped schedule that runs `php artisan schedule:run` every minute.

Instance selectors use `app.instance`, such as `docs.production`. A bare app
name is shorthand only when exactly one eligible instance is visible to the
caller for the requested schedule permission. Ambiguity fails before reads,
writes, dispatch, or destructive side effects. Schedule names are unique within
the concrete target, so two instances of one app may own schedules with
the same name.

When the target is not the gateway itself, the gateway dispatches the run through `internal:schedule:run` over agent-push. The scheduled command executes on the target node, but the gateway records every result centrally.

Doctor eligibility follows schedule ownership rather than a workload-role
allowlist. `Scheduling` is available for the gateway and for every node targeted
by at least one gateway schedule definition. Singleton scheduler
service/heartbeat/lock checks run only at gateway scope; target reachability and
recent-run checks run for each selected scheduled node.

#### Execution source and intervals

- Scheduled work has exactly one execution source: an inline command or a managed script path.
- Intervals use Orbit's portable interval language, such as `every 5 minutes`, `daily at 09:00`, `weekdays at 09:00`, or `weekly on monday at 09:00`.

### Execution and reads

These rules describe how schedule writes propagate to the Orbit Scheduler and how reads source their data.

#### Write propagation

- Schedule write commands mutate gateway configuration. There is no node-side
  schedule mirror to sync; the gateway scheduler reads every tick from the
  gateway database directly.
- The Orbit Scheduler claims due-run locks in the gateway database and
  dispatches each due schedule to its target on the next tick.
- The only applied artifact is the `orbit-scheduler` one-replica Swarm service,
  prepared by gateway provisioning. Non-gateway nodes do not run an Orbit
  Scheduler instance.

#### Update and recovery behavior

`orbit update:all` updates the scheduler with stop-first ordering. The durable
runner scales `orbit-scheduler` to zero before gateway migrations, applies
migrations through the target gateway image, verifies the updated
`orbit-gateway` service, then starts `orbit-scheduler` on the matching image.
If migrations or gateway health fail, the runner records a terminal failure
event and restores the scheduler to one replica on the previous known-good
image when that image is still usable. If recovery cannot start the scheduler,
the terminal event must name that recovery failure so the operator can run
gateway/schedule recovery explicitly.

#### Reads and adoption

- Schedule reads use gateway configuration and durable run history by default.
- Live scheduler reality belongs to `doctor --family=schedule`.
- The schedule family does not adopt observed artifacts as schedules. Schedules are gateway configuration with no observed-artifact-as-configuration path; create a schedule from an observed candidate with `schedule:add` directly.

### Permissions

Schedule API requests are authorized against the schedule target node.
Instance-scoped schedules use the selected instance's serving node, node-scoped
schedules use the selected node, and Orbit-scoped schedules use the gateway
node.

- `schedule:read` covers `schedule:list`, `schedule:show`, and
  `schedule:logs`.
- `schedule:add` covers creating schedule intent.
- `schedule:run` covers one-off schedule execution.
- `schedule:remove` covers destructive schedule removal.

Authorization failures use `authorization_failed` with standard
`missing_permission` metadata.

These checks apply to user-facing add, list, show, remove, logs, and manual run
requests. Once a request is accepted—or a recurring schedule becomes
due—the gateway dispatches under gateway implicit authority. Internal
gateway-to-node authentication protects transport; it is not a second user
permission check. Moving an instance changes the serving node resolved at
execution time and does not reauthorize each stored schedule.

## Schedule JSON Entity

Schedule JSON renderers that return one schedule entity embed this shape under
`success.data.schedule`, or directly under `success.data.schedules[]` for list
items.

```json
{
  "name": "laravel-scheduler",
  "scope": "instance",
  "target": {
    "type": "instance",
    "name": "docs.production",
    "node": "app-1"
  },
  "interval": "every minute",
  "timezone": "Europe/Amsterdam",
  "execution": {
    "type": "command",
    "value": "php artisan schedule:run",
    "timeout_seconds": 900
  },
  "enabled": true,
  "status": "expected",
  "scheduler_heartbeat_at": "2026-05-02T08:00:01Z",
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
| `name` | string | Schedule slug, unique within the selected concrete target. |
| `scope` | `instance`, `node`, or `orbit` | Scope that owns the schedule. Public `SchedulePayload` can render stored `orbit` rows. Creatable scopes through `schedule:add` remain `instance` and `node` only. |
| `target.type` | string | Target kind. |
| `target.name` | string | Concrete `app.instance` selector, node, or Orbit maintenance target. |
| `target.node` | string | Node the dispatched command executes on. The gateway scheduler dispatches over agent-push when the target is not the gateway. |
| `interval` | string | Portable Orbit interval expression. |
| `timezone` | string | Timezone used to interpret the interval. |
| `execution.type` | `command` or `script` | Execution source kind. |
| `execution.value` | string | Inline command or managed script path. |
| `execution.timeout_seconds` | integer | Maximum execution time in seconds. |
| `enabled` | boolean | Whether the recurring schedule should run. |
| `status` | string | Gateway-configuration status, not live scheduler verification. |
| `scheduler_heartbeat_at` | string \| null | ISO-8601 of the most recent gateway-scheduler heartbeat. `null` until the first heartbeat is recorded. The Orbit Scheduler is gateway-only; there is no per-target scheduler. |
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
- [`orbit app:*` and `orbit instance:*`](../5_app/README.md)
- [`orbit node:*`](../1_node/README.md)
