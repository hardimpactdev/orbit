# Schedule Concepts

This document defines schedule-family vocabulary and invariants. It supports the schedule command contracts and the [schedule doctor](schedule-doctor.md); it does not override the [Architecture](../../architecture.md).

## Identity

These terms define the core entities in the schedule domain.

- **Schedule:** Gateway-owned record of one piece of recurring work, with a
  scope, a target, an interval, an execution source, an enabled flag, and
  durable run history.
- **Schedule scope:** Ownership scope of the schedule. One of `instance`, `node`, or
  `orbit`.
- **Instance-scoped schedule:** Schedule whose scope is `instance` and whose durable
  owner is exactly one concrete instance. Executes on that instance's
  serving node and path; the gateway dispatches through the signed
  `internal:schedule:run` local-executor command over agent-push when the target
  is not the gateway itself.
- **Schedule instance selector:** Concrete `app.instance` identity such as
  `docs.production`. A bare project is shorthand only when exactly one
  eligible instance is visible for the requested permission; otherwise
  resolution fails without side effects.
- **Node-scoped schedule:** Schedule whose scope is `node`. Executes on the
  selected node, dispatched by the gateway scheduler.
- **Orbit-scoped schedule:** Schedule whose scope is `orbit` (not creatable through
  `schedule:add`; creatable scopes are instance and node only). Used for
  Orbit-owned maintenance work; runs on the gateway unless a command
  documents another serving node.
- **Laravel scheduler:** Conventional instance-scoped schedule that runs
  `php artisan schedule:run` every minute on one concrete instance's
  serving node.

## Execution

These terms describe how schedules run and how their history is captured.

- **Execution source:** The single command or script the schedule runs.
  Exposed in JSON as `execution.type` (`command` or `script`) and
  `execution.value`.
- **Portable interval expression:** Orbit's interval language used by all
  schedules, such as `every 5 minutes`, `daily at 09:00`,
  `weekdays at 09:00`, or `weekly on monday at 09:00`.
- **Schedule timezone:** IANA timezone identifier (such as `Europe/Amsterdam`
  or `UTC`) used to evaluate the schedule's interval expression. Stored on the
  schedule row and exposed in JSON as `timezone`.
- **Schedule configuration status:** Gateway-tracked status of the schedule
  definition, exposed in JSON as `status`. Reports whether gateway
  configuration is `expected`, `disabled`, or in another configuration state.
  This is distinct from live scheduler verification, which belongs to
  `doctor --family=schedule`.
- **Orbit Scheduler:** Resident `orbit-scheduler` Swarm service using the
  Orbit gateway image.
  - Owns schedule evaluation, due-run dispatch, lock claim, and overlap policy for every schedule across the fleet.
  - Dispatches to non-gateway targets through the typed `internal:schedule:run` local-executor command over agent-push. The scheduled command runs on the target, but the gateway orchestrates and tracks every result.
  - Records run history and writes its own heartbeat directly to the gateway database.
- **Scheduler heartbeat:** Periodic timestamp the Orbit Scheduler writes to
  the gateway database so doctor can verify daemon liveness.
- **Schedule run:** One execution of a schedule. Recorded as durable
  gateway history with `started_at`, `finished_at`, `exit_code`, and
  `status` (`completed`, `failed`, `skipped`, `missed`). The latest entry
  surfaces as `last_run` on the schedule entity; full history is read
  through `schedule:logs`. Dispatch failures (agent-push unavailable, target
  down) are recorded as failed runs.
- **Schedule lock:** Per-schedule lock that prevents overlapping runs.
  Lock state lives in the gateway database (`schedule_locks`), not on the
  target node — the gateway is the only place that needs to know what is
  currently executing.
- **Run-history hook:** Orbit-managed material the scheduler uses to
  capture stdout/exit-status from a schedule run. For gateway-target runs
  this is local; for non-gateway-target runs the captured output is returned
  through the local-executor result envelope before history is finalized.

## Boundaries

These terms define what the schedule family owns and what belongs elsewhere.

- **Schedule-family boundaries:** Schedule commands own schedule definitions, scopes, intervals, execution sources, enabled state, the Orbit Scheduler daemon shape, and run history.
  - They do not own app or node identity or process configuration.
  - Live scheduler reality belongs to `doctor --family=schedule`.
- **Gateway-only scheduler invariant:** All schedule evaluation, dispatch, locking, and history live on the gateway.
- **No node-side scheduler:** Targets receive dispatched commands via the signed `internal:schedule:run` local-executor command at execution time and hold no local mirror.
