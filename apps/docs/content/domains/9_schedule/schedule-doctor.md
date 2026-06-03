# Schedule Doctor

[Back to Schedule commands.](README.md)

The schedule family doctor implements the
[Family Doctor Implementation Contract](../11_operation/3_doctor/technical/1_doctor.md#family-doctor-implementation-contract).
`key()` returns `schedule`.

`doctor --family=schedule` verifies whether gateway schedule configuration is being executed by the gateway-resident Orbit Scheduler and that every schedule's target is reachable for dispatch. It covers Orbit-owned schedules only.

The schedule family owns these facts:

- gateway-owned schedule rows: scope, target, interval, timezone, execution source, enabled state, and scheduler metadata;
- the `orbit-scheduler` Swarm service using the Orbit gateway image;
- Orbit Scheduler liveness, heartbeat freshness, and schedule lock health (all gateway-side);
- SSH reachability from the gateway to each schedule's target node so dispatches can succeed;
- drift between gateway schedule configuration and observed run history.

App source, app runtime containers, process units, proxy routes, tools, firewall rules, and node reachability belong to their own families. The schedule family verifies the gateway-resident scheduler and per-target dispatch reachability, not application health.

## Probe Layers

The schedule probe reads gateway schedule configuration and checks these layers:

1. **Registry configuration:** every selected schedule has valid scope, target, interval, timezone, execution source, and enabled state.
2. **Target eligibility:** the app, node, or Orbit maintenance target resolves and is visible to the caller.

**Gateway scheduler layers** (verified once per doctor run, not per schedule):

3. **Scheduler runtime availability:** the `orbit_orbit-scheduler` Swarm service
   exists and exposes service state.
4. **Orbit Scheduler desired state:** the service uses the configured
   `orbit-gateway` image and has exactly one desired/running replica.
5. **Orbit Scheduler liveness:** the scheduler service is in a running state.
6. **Heartbeat freshness:** the most recent scheduler heartbeat is within the configured threshold.
7. **Schedule lock health:** no schedule lock in `schedule_locks` exceeds the configured stale-lock threshold.

If any of layers 3–7 fail, the corresponding issue code is emitted and downstream per-target dispatch checks are still attempted (so the operator sees both the scheduler-side problem and any reachability problems).

**Per-target dispatch layers** (one set per schedule whose target is not the gateway):

8. **Target SSH reachability:** the gateway can open a `RemoteShell` connection to the target node. Required for the gateway to dispatch the scheduled command.
9. **Recent run health:** recent `schedule_runs` rows exist for enabled schedules and the latest status is healthy. Failures and stuck runs beyond the configured threshold surface as drift.

## Schedule Issue Codes

The table below lists every issue code the schedule probe may emit and the condition that triggers it.

| Code | Detected when |
| --- | --- |
| `schedule.record_incomplete` | A selected gateway schedule lacks scope, target, interval, timezone, execution source, or enabled state. |
| `schedule.target_invalid` | The schedule points at a missing, unauthorized, inactive, unsupported, or role-incompatible target. |
| `schedule.runtime_backend_unavailable` | The gateway Swarm runtime or gateway image cannot run the scheduler daemon. |
| `schedule.scheduler_missing` | The `orbit_orbit-scheduler` Swarm service has no desired scheduler replica. |
| `schedule.scheduler_stopped` | The `orbit_orbit-scheduler` Swarm service is configured but not running. |
| `schedule.scheduler_image_mismatch` | The scheduler service image differs from the configured `orbit-gateway` image. |
| `schedule.scheduler_replicas_mismatch` | The scheduler service is running but is not a singleton `1/1` service. |
| `schedule.heartbeat_stale` | The most recent scheduler heartbeat is older than the configured threshold. |
| `schedule.lock_stuck` | A row in `schedule_locks` exceeds the configured stale-lock threshold. |
| `schedule.target_unreachable` | The gateway cannot open a `RemoteShell` connection to the schedule's target node. Dispatch will fail until reachability is restored. |
| `schedule.run_stuck` | The latest `schedule_runs` row for an enabled schedule has been in `running` state past the configured threshold. |

## Schedule Fix Map

The table below lists what `doctor --restore` does for each issue code.

| Code | `doctor --restore` behavior |
| --- | --- |
| `schedule.runtime_backend_unavailable` | No `doctor --restore` action. Gateway service recovery belongs to node operations. |
| `schedule.scheduler_missing` | Scale `orbit_orbit-scheduler` to one replica. |
| `schedule.scheduler_stopped` | Scale `orbit_orbit-scheduler` to one replica. |
| `schedule.scheduler_image_mismatch` | Update `orbit_orbit-scheduler` to the configured gateway image with stop-first order, then scale it to one replica. |
| `schedule.scheduler_replicas_mismatch` | Scale `orbit_orbit-scheduler` back to one replica. |
| `schedule.heartbeat_stale` | No `doctor --restore` action. Stale heartbeat is a runtime symptom; restart the scheduler daemon or investigate the gateway service. |
| `schedule.lock_stuck` | Release the stale lock row in `schedule_locks` and record the affected run as `failed`. |

`doctor --restore` does not handle `schedule.record_incomplete`,
`schedule.target_invalid`, `schedule.runtime_backend_unavailable`,
`schedule.heartbeat_stale`, `schedule.target_unreachable`, or
`schedule.run_stuck`. `schedule.target_unreachable` is a downstream symptom of
node or network drift; resolve through `doctor --family=node` and SSH-path
diagnostics. `schedule.run_stuck` is observable history that points operators
to `schedule:logs` for the affected run.

## Schedule Adopt Map

The table below lists what `doctor --adopt` does for each issue code.

| Code | `doctor --adopt` behavior |
| --- | --- |
| (no codes adopt by default) | Schedules are gateway configuration. There is no observed-artifact-as-configuration path. Use `schedule:add` directly to create a schedule from an observed candidate. |

`doctor --adopt` does not scan arbitrary hosts or import scheduler-local state into gateway schedule configuration.

## Test Mapping

Required test files:

| Path | Coverage |
| --- | --- |
| `apps/gateway/tests/Feature/Doctor/ScheduleFamilyDoctorContractTest.php` | Schedule-family dispatch, probe-layer selection, schedule issue codes, fix map, denied adopt cases, scope filtering, and assertion that `schedule.runtime_backend_unavailable` short-circuits downstream scheduler layer checks. |
| `apps/gateway/tests/Unit/Services/Schedules/ScheduleProbeTest.php` | In-memory probe diff behavior across registry, eligibility, runtime, scheduler, and history layers (scope below). |
| `apps/gateway/tests/E2E/Read/ScheduleDoctorTest.php` | Real read-only `doctor --family=schedule --json` against a topology with the Orbit Scheduler running. Docker-eligible. |
| `apps/gateway/tests/E2E/Ephemeral/ScheduleDoctorFixTest.php` | Real `doctor --family=schedule --restore` repair for `scheduler_missing`, `scheduler_stopped`, `scheduler_image_mismatch`, `scheduler_replicas_mismatch`, and `lock_stuck` codes. Docker-eligible. |

`ScheduleProbeTest` covers registry configuration, target eligibility, gateway
scheduler process manager availability, scheduler presence, scheduler
liveness, heartbeat freshness, schedule lock health, per-target SSH
reachability, and stuck-run detection.
