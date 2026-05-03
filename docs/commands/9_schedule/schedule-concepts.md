# Schedule Concepts

This document defines schedule-family vocabulary and invariants. It supports
the schedule command contracts and the
[schedule doctor](schedule-doctor.md); it does not override the
[Blueprint](../../BLUEPRINT.md).

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
- **Schedule run:** Durable execution record with id, status, exit code, start,
  and finish time. The latest entry surfaces as `last_run` on the schedule
  entity; full history is read through `schedule:logs`.

## Boundaries

- **Schedule-family boundaries:** Schedule commands own schedule definitions,
  scopes, intervals, execution sources, enabled state, and run history. They do
  not own the underlying timer or service backend, app or node identity, or
  process intent. Live timer/service reality belongs to
  `doctor --family=schedule`.
