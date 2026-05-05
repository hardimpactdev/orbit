# Schedule Doctor

[Back to Schedule commands.](README.md)

`doctor --family=schedule` verifies whether gateway schedule intent is being
executed by a live Orbit Scheduler on the target node. It covers Orbit-owned
schedules only.

The schedule family owns these facts:

- gateway-owned schedule rows: scope, target, interval, timezone, execution
  source, enabled state, and scheduler metadata;
- the `orbit_scheduler` Supervisor program on every gateway and app node
  that targets schedules;
- Orbit Scheduler liveness, heartbeat freshness, registry-sync freshness,
  and schedule lock health;
- run-history capture hooks needed for schedule observability;
- drift between gateway schedule intent and scheduler-side execution
  reality.

App source, app PHP-FPM, process units, proxy routes, tools, firewall rules,
and node reachability belong to their own families. The schedule family
verifies the Orbit Scheduler and its run-history hooks, not application
health.

## Probe Layers

The schedule probe reads gateway schedule intent and checks these layers:

1. **Registry intent:** every selected schedule has valid scope, target,
   interval, timezone, execution source, enabled state, and scheduler
   metadata.
2. **Target eligibility:** the app, node, or Orbit maintenance target
   resolves and is visible to the caller.
3. **Node eligibility:** the target node resolves to a visible active
   gateway or app node with schedule capability.
4. **Runtime backend availability:** the target node has Supervisor
   installed and reachable. When this layer fails, the probe reports
   `schedule.runtime_backend_unavailable` and skips downstream scheduler
   layers.
5. **Orbit Scheduler presence:** the `orbit_scheduler` Supervisor program
   exists on the target node.
6. **Orbit Scheduler liveness:** the `orbit_scheduler` program is in a
   running state and the daemon's local heartbeat is fresh enough to be
   considered live.
7. **Heartbeat freshness:** the most recent heartbeat reported to the
   gateway is within the configured threshold.
8. **Registry sync freshness:** the scheduler's most recent
   schedule-intent sync is within the configured threshold.
9. **Schedule lock health:** no schedule lock exceeds the configured
   stale-lock threshold.
10. **Run-history hook material:** scheduler-side hook material required
    to capture stdout/exit-status for the selected schedules exists and
    matches gateway intent.

## Schedule Issue Codes

| Code | Detected when |
| --- | --- |
| `schedule.record_incomplete` | A selected gateway schedule lacks scope, target, interval, timezone, execution source, enabled state, or scheduler metadata required for comparison. |
| `schedule.target_invalid` | The schedule points at a missing, unauthorized, inactive, unsupported, or role-incompatible target. |
| `schedule.runtime_backend_unavailable` | The target node's runtime backend is not reachable. Downstream scheduler layer checks are skipped while this code is active. |
| `schedule.scheduler_missing` | The runtime backend has no `orbit_scheduler` Supervisor program. |
| `schedule.scheduler_stopped` | The `orbit_scheduler` Supervisor program is registered but not running. |
| `schedule.heartbeat_stale` | The most recent scheduler heartbeat reported to the gateway is older than the configured threshold. |
| `schedule.registry_sync_stale` | The scheduler has not synced schedule intent within the configured threshold. |
| `schedule.lock_stuck` | A schedule lock exceeds the configured stale-lock threshold. |
| `schedule.run_history_hook_missing` | Scheduler-side run-history hook material is absent for a selected schedule. |
| `schedule.run_history_hook_mismatch` | Scheduler-side run-history hook material differs from gateway intent. |

## Schedule Fix Map

| Code | `--fix` behavior |
| --- | --- |
| `schedule.runtime_backend_unavailable` | No `--fix` action. Runtime backend recovery belongs to `tool` family doctor and node operations. |
| `schedule.scheduler_missing` | Re-render and load the `orbit_scheduler` Supervisor program from node-level scheduler intent. |
| `schedule.scheduler_stopped` | Start the `orbit_scheduler` Supervisor program through the runtime backend. |
| `schedule.heartbeat_stale` | No `--fix` action. Stale heartbeat is a runtime symptom; restart the scheduler explicitly with `process:restart orbit_scheduler` or investigate the daemon. |
| `schedule.registry_sync_stale` | No `--fix` action. Sync is restored when scheduler-to-gateway connectivity recovers. |
| `schedule.lock_stuck` | Release the stale lock on the target node and record the affected run as `failed`. |
| `schedule.run_history_hook_missing` | Recreate run-history hook material for the selected schedule. |
| `schedule.run_history_hook_mismatch` | Replace run-history hook material with the gateway-intended hook. |

`--fix` does not handle `schedule.record_incomplete`,
`schedule.target_invalid`, `schedule.runtime_backend_unavailable`,
`schedule.heartbeat_stale`, or `schedule.registry_sync_stale`.

## Schedule Adopt Map

| Code | `--adopt` behavior |
| --- | --- |
| (no codes adopt by default) | Schedules are gateway intent. There is no observed-artifact-as-intent path. Adoption candidates that an operator wants to materialize as schedules must use `schedule:add` directly. |

`--adopt` does not scan arbitrary hosts or import scheduler-local state into
gateway schedule intent.

## Test Mapping

Required test files:

| Path | Coverage |
| --- | --- |
| `tests/Feature/Doctor/ScheduleFamilyDoctorContractTest.php` | Schedule-family dispatch, probe-layer selection, schedule issue codes, fix map, denied adopt cases, scope filtering, and assertion that `schedule.runtime_backend_unavailable` short-circuits downstream scheduler layer checks. |
| `tests/Unit/Services/Schedules/ScheduleProbeTest.php` | In-memory schedule probe diff behavior for registry intent, target eligibility, node eligibility, runtime backend availability, scheduler presence, scheduler liveness, heartbeat freshness, registry sync freshness, schedule lock health, and run-history hook material. |
| `tests/E2E/Read/ScheduleDoctorTest.php` | Real read-only `doctor --family=schedule --json` against a topology with the Orbit Scheduler running. Docker-eligible. |
| `tests/E2E/Ephemeral/ScheduleDoctorFixTest.php` | Real `doctor --family=schedule --fix` repair for `scheduler_missing`, `scheduler_stopped`, `lock_stuck`, and `run_history_hook_*` codes. Docker-eligible. |
