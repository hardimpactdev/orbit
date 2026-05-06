# Schedule Implementation Patterns

Read this with `docs/abstractions/cross-cutting.md` before implementing
schedule command ports.

Product behavior remains owned by `docs/commands/9_schedule/**` and the
top-level product docs.

## Domain Constraints

- The gateway is the source of truth for schedule intent and durable run
  history.
- A schedule targets exactly one app, node, or Orbit maintenance scope.
- Scheduled execution is performed by the resident `orbit-scheduler` daemon on
  the resolved target node.
- Schedule commands own schedule definitions, scheduler heartbeat intake,
  schedule run history, scheduler runtime hooks, and scheduler daemon drift.
- Schedule commands do not own app source, PHP-FPM pools, process definitions,
  tool installation, proxy routes, firewall rules, or node reachability.
- Runtime backend recovery belongs to the `tool` family when Supervisor is
  unavailable. Schedule doctor must report
  `schedule.runtime_backend_unavailable` and skip downstream scheduler checks.
- Schedule adoption is intentionally closed by default. Operators create desired
  schedule intent with `schedule:add` rather than importing scheduler-local
  artifacts.

## Schema And Model Pattern

- `schedules`
  - schedule identity and unique key
  - scope type and target references
  - interval, timezone, enabled state, and execution source
  - scheduler metadata needed for deterministic daemon behavior
- `schedule_runs`
  - schedule/node references
  - status, exit code, stdout/stderr, started/finished timestamps
  - payload needed for durable command rendering
- `scheduler_states`
  - node reference
  - heartbeat timestamp
  - registry sync timestamp
  - daemon metadata reported by scheduler heartbeat intake

Schedule rows remain gateway intent. `ScheduleRun` and `SchedulerState` are
durable observations reported through gateway-owned authenticated endpoints.

## Runtime Backend Pattern

- Use `App\Services\Schedules\OrbitSchedulerProgramRenderer` for the
  `orbit_scheduler` Supervisor program name, rendered content, source hash, and
  install script.
- Use `App\Services\RuntimeBackend\RuntimeBackendProbe` to check Supervisor
  availability before scheduler presence, liveness, or hook checks.
- The scheduler daemon runs as an Artisan command and reports heartbeat and run
  history through typed scheduler gateway requests.
- Schedule run-history hook material is rendered by
  `App\Services\Schedules\ScheduleRunHistoryHookRenderer`.
- Hook files live at `/opt/orbit/schedules/hooks/{sha256(schedule_key)}.sh`.
  The hash-based filename keeps app, node, and Orbit scopes in one scheduler
  hook directory without leaking shell-sensitive schedule names into paths.
- Hook content is derived from gateway schedule intent: schedule key, app cwd
  when the schedule targets an app, and the stored execution value. Doctor
  compares the file by SHA-256 content hash, not by ad hoc string fragments.

## Command Pattern

- `schedule:list`, `schedule:show`, and `schedule:logs` are gateway registry /
  history reads and do not inspect live node runtime state.
- `schedule:add` and `schedule:remove` mutate gateway intent first. Scheduler
  pickup is a warning or error boundary that points operators to
  `doctor --family=schedule`.
- `schedule:run` creates a durable manual run record and executes through the
  gateway-owned target-node execution edge.
- Control and app callers use typed gateway API requests. Gateway callers may
  use local gateway state plus the gateway-owned `RemoteShell` edge.

## Doctor Pattern

- `SchedulesProbe` should check registry intent first, then target and node
  eligibility, then runtime backend availability.
- If the runtime backend is unavailable, downstream scheduler checks must be
  skipped for that target node.
- Scheduler presence and liveness are based on the `orbit_scheduler` Supervisor
  program rendered by `OrbitSchedulerProgramRenderer`.
- Heartbeat and registry sync freshness are gateway durable state checks against
  `scheduler_states`.
- Lock health and run-history hook material are schedule-family drift because
  they affect whether schedule execution can be observed and safely repeated.
- `schedule.run_history_hook_missing` and
  `schedule.run_history_hook_mismatch` are safe `--fix` cases: they install the
  gateway-intended hook material and do not change schedule intent.
- Schedule doctor has no adoption path by default.

## Evidence Pointers

- `docs/commands/9_schedule/README.md`
- `docs/commands/9_schedule/schedule-concepts.md`
- `docs/commands/9_schedule/schedule-doctor.md`
- `docs/commands/9_schedule/1_schedule-add`
- `docs/commands/9_schedule/2_schedule-list`
- `docs/commands/9_schedule/3_schedule-show`
- `docs/commands/9_schedule/4_schedule-remove`
- `docs/commands/9_schedule/5_schedule-run`
- `docs/commands/9_schedule/6_schedule-logs`
- `docs/abstractions/cross-cutting.md`
- Old evidence: `../orbit-old-may/app/Services/AppSchedulers/AppSchedulerProbe.php`
- Old evidence: `../orbit-old-may/app/Services/AppSchedulers/AppSchedulerRenderer.php`
- Old evidence: `../orbit-old-may/app/Services/AppSchedulers/AppSchedulerEnactor.php`
- Old evidence: `../orbit-old-may/tests/Unit/Services/AppSchedulers/AppSchedulerProbeTest.php`
- Old evidence: `../orbit-old-may/tests/Feature/DoctorFixAppSchedulersTest.php`
