# Schedule Doctor

[Back to Schedule commands.](README.md)

`doctor --family=schedule` verifies whether gateway schedule configuration is being executed by a live Orbit Scheduler on the target node. It covers Orbit-owned schedules only.

The schedule family owns these facts:

- gateway-owned schedule rows: scope, target, interval, timezone, execution source, enabled state, and scheduler metadata;
- the `orbit_scheduler` Supervisor program on every gateway and app node that targets schedules;
- Orbit Scheduler liveness, heartbeat freshness, registry-sync freshness, and schedule lock health;
- the hooks needed to capture run history for schedule observability;
- drift between gateway schedule configuration and scheduler-side execution reality.

App source, app PHP-FPM, process units, proxy routes, tools, firewall rules, and node reachability belong to their own families. The schedule family verifies the Orbit Scheduler and its run-history hooks, not application health.

## Probe Layers

The schedule probe reads gateway schedule configuration and checks these layers:

1. **Registry configuration:** every selected schedule has valid scope, target, interval, timezone, execution source, enabled state, and scheduler metadata.
2. **Target eligibility:** the app, node, or Orbit maintenance target resolves and is visible to the caller.
3. **Node eligibility:** the target node resolves to a visible active gateway or app node with schedule capability.
4. **Process manager availability:** the target node has Supervisor installed and reachable. When this layer fails, the probe reports `schedule.runtime_backend_unavailable` and skips all downstream scheduler layers.

**Scheduler layers** (skipped when layer 4 fails):

5. **Orbit Scheduler presence:** the `orbit_scheduler` Supervisor program exists on the target node.
6. **Orbit Scheduler liveness:** the `orbit_scheduler` program is in a running state and the daemon's local heartbeat is fresh enough to be considered live.
7. **Heartbeat freshness:** the most recent heartbeat reported to the gateway is within the configured threshold.
8. **Registry sync freshness:** the scheduler's most recent schedule-configuration sync is within the configured threshold.
9. **Schedule lock health:** no schedule lock exceeds the configured stale-lock threshold.
10. **Run-history hook material:** the hook material required to capture stdout and exit status for the selected schedules exists on the scheduler side and matches gateway configuration.

## Schedule Issue Codes

The table below lists every issue code the schedule probe may emit and the condition that triggers it.

| Code | Detected when |
| --- | --- |
| `schedule.record_incomplete` | A selected gateway schedule lacks scope, target, interval, timezone, execution source, enabled state, or scheduler metadata required for comparison. |
| `schedule.target_invalid` | The schedule points at a missing, unauthorized, inactive, unsupported, or role-incompatible target. |
| `schedule.runtime_backend_unavailable` | The target node's process manager (Supervisor) is not reachable. Downstream scheduler layer checks are skipped while this code is active. |
| `schedule.scheduler_missing` | The process manager has no `orbit_scheduler` Supervisor program. |
| `schedule.scheduler_stopped` | The `orbit_scheduler` Supervisor program is registered but not running. |
| `schedule.heartbeat_stale` | The most recent scheduler heartbeat reported to the gateway is older than the configured threshold. |
| `schedule.registry_sync_stale` | The scheduler has not synced schedule configuration within the configured threshold. |
| `schedule.lock_stuck` | A schedule lock exceeds the configured stale-lock threshold. |
| `schedule.run_history_hook_missing` | Scheduler-side run-history hook material is absent for a selected schedule. |
| `schedule.run_history_hook_mismatch` | Scheduler-side run-history hook material differs from gateway configuration. |

## Schedule Fix Map

The table below lists what `doctor --fix --restore` does for each issue code.

| Code | `doctor --fix --restore` behavior |
| --- | --- |
| `schedule.runtime_backend_unavailable` | No `doctor --fix --restore` action. Process manager recovery belongs to `tool` family doctor and node operations. |
| `schedule.scheduler_missing` | Re-render and load the `orbit_scheduler` Supervisor program from node-level scheduler configuration. |
| `schedule.scheduler_stopped` | Start the `orbit_scheduler` Supervisor program through the process manager. |
| `schedule.heartbeat_stale` | No `doctor --fix --restore` action. Stale heartbeat is a runtime symptom; restart the scheduler explicitly with `process:restart orbit_scheduler` or investigate the daemon. |
| `schedule.registry_sync_stale` | No `doctor --fix --restore` action. Sync is restored when scheduler-to-gateway connectivity recovers. |
| `schedule.lock_stuck` | Release the stale lock on the target node and record the affected run as `failed`. |
| `schedule.run_history_hook_missing` | Recreate run-history hook material for the selected schedule. |
| `schedule.run_history_hook_mismatch` | Replace run-history hook material with the gateway-configured hook. |

`doctor --fix --restore` does not handle `schedule.record_incomplete`,
`schedule.target_invalid`, `schedule.runtime_backend_unavailable`,
`schedule.heartbeat_stale`, or `schedule.registry_sync_stale`.

## Schedule Adopt Map

The table below lists what `doctor --fix --adopt` does for each issue code.

| Code | `doctor --fix --adopt` behavior |
| --- | --- |
| (no codes adopt by default) | Schedules are gateway configuration. There is no observed-artifact-as-configuration path. Use `schedule:add` directly to create a schedule from an observed candidate. |

`doctor --fix --adopt` does not scan arbitrary hosts or import scheduler-local state into gateway schedule configuration.

## Test Mapping

Required test files:

| Path | Coverage |
| --- | --- |
| `tests/Feature/Doctor/ScheduleFamilyDoctorContractTest.php` | Schedule-family dispatch, probe-layer selection, schedule issue codes, fix map, denied adopt cases, scope filtering, and assertion that `schedule.runtime_backend_unavailable` short-circuits downstream scheduler layer checks. |
| `tests/Unit/Services/Schedules/ScheduleProbeTest.php` | In-memory schedule probe diff behavior for registry configuration, target eligibility, node eligibility, process manager availability, scheduler presence, scheduler liveness, heartbeat freshness, registry sync freshness, schedule lock health, and run-history hook material. |
| `tests/E2E/Read/ScheduleDoctorTest.php` | Real read-only `doctor --family=schedule --json` against a topology with the Orbit Scheduler running. Docker-eligible. |
| `tests/E2E/Ephemeral/ScheduleDoctorFixTest.php` | Real `doctor --fix --family=schedule --restore` repair for `scheduler_missing`, `scheduler_stopped`, `lock_stuck`, and `run_history_hook_*` codes. Docker-eligible. |
