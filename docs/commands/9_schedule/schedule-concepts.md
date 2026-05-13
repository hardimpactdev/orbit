# Schedule Concepts

This document defines schedule-family vocabulary and invariants. It supports the schedule command contracts and the [schedule doctor](schedule-doctor.md); it does not override the [Architecture](../../ARCHITECTURE.md).

## Identity

- **Schedule:** Gateway-owned record of one piece of recurring work, with a
  scope, a target, an interval, an execution source, an enabled flag, and
  durable run history.
- **Schedule scope:** Ownership scope of the schedule. One of `app`, `node`, or
  `orbit`.
- **App-scoped schedule:** Schedule whose scope is `app`. Runs in the app
  context on the owning app node.
- **Node-scoped schedule:** Schedule whose scope is `node`. Runs on the
  selected node.
- **Orbit-scoped schedule:** Schedule whose scope is `orbit`. Used for
  Orbit-owned maintenance work; runs on the gateway unless a command
  documents another serving node.
- **Laravel scheduler:** Conventional app-scoped schedule that runs
  `php artisan schedule:run` every minute.

## Execution

- **Execution source:** The single command or script the schedule runs.
  Exposed in JSON as `execution.type` (`command` or `script`) and
  `execution.value`.
- **Portable interval expression:** Orbit's interval language used by all
  schedules, such as `every 5 minutes`, `daily at 09:00`,
  `weekdays at 09:00`, or `weekly on monday at 09:00`.
- **Orbit Scheduler:** Resident `orbit-scheduler` Artisan-command daemon supervised by the process manager (Supervisor) on every gateway and app node. Owns schedule evaluation, due-run dispatch, overlap policy, run history, and heartbeat for schedules whose target resolves to the local node.
- **Scheduler heartbeat:** Periodic local state the Orbit Scheduler writes
  so doctor can verify liveness without a long-lived inbound channel.
- **Schedule run:** One execution of a schedule. Recorded as durable
  gateway history with `started_at`, `finished_at`, `exit_code`, and
  `status` (`completed`, `failed`, `skipped`, `missed`). The latest entry
  surfaces as `last_run` on the schedule entity; full history is read
  through `schedule:logs`.
- **Schedule lock:** Local node lock that prevents overlapping runs of the same schedule. Lock state lives in the node's local Orbit SQLite, not in gateway configuration.
- **Run-history hook:** Orbit-managed material the scheduler uses to
  capture stdout/exit-status from a schedule run before reporting it to the
  gateway run-history intake.

## Boundaries

- **Schedule-family boundaries:** Schedule commands own schedule definitions, scopes, intervals, execution sources, enabled state, the Orbit Scheduler daemon shape, and run history. They do not own app or node identity, or process configuration. Live scheduler reality belongs to `doctor --family=schedule`.
