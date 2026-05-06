# 9_schedule — Schedule Workstream

Detail file for the schedule command family. Top-level command status lives in
[`PORTING.md`](PORTING.md). Doc authority: `docs/commands/9_schedule/`.

## Workstream

- [x] Convert schedule command docs into current format.
- [x] Reshape schedule docs around the Orbit Scheduler resident daemon. See
  [`../2026-05-05-supervisor-runtime-backend-plan.md`](../2026-05-05-supervisor-runtime-backend-plan.md).
- [x] Port schedule schema, models, and run-history table.
  - [x] Added gateway-owned `schedules` intent storage for app, node, and
    Orbit scoped schedules, including stable schedule keys, execution source,
    interval, timezone, enabled/status fields, model relationships, factory
    states, and focused model coverage.
  - [x] Reconciled with existing scheduler local state and run-history work:
    `scheduler_states`, `schedule_locks`, `schedule_runs`, authenticated
    run-history intake, typed gateway request/response DTOs, and focused API /
    gateway-client coverage were already present from the scheduler foundation
    slices.
- [x] Port schedule add/list/show/remove commands.
  - [x] `schedule:list` and `schedule:show` gateway-local registry reads,
    typed gateway API forwarding, app/node filter validation, latest durable
    run summary projection, human/JSON renderers, read-only boundary tests, and
    focused Pest coverage.
  - [x] `schedule:add` gateway-owned intent write, execution-source validation,
    scheduler reachability warning/error handling, human/JSON renderers, and
    focused Pest coverage.
  - [x] `schedule:remove` gateway-owned destructive intent removal,
    destructive consent, human/JSON renderers, and focused Pest coverage.
- [x] Port schedule run command (manual fire / on-demand tick).
  - [x] `schedule:run` gateway-owned manual schedule execution, typed gateway
    API forwarding, app/node filter validation, target-node execution through
    the existing remote-shell abstraction, durable run-history persistence,
    scheduled process failure reporting with captured output, human/JSON
    renderers, and focused Pest coverage.
- [x] Port schedule logs command against scheduler-captured stdout/stderr.
  - [x] `schedule:logs` gateway-owned durable run-history read, typed gateway
    API forwarding, app/node filter validation, run-id selection, independent
    stdout/stderr line limiting, human/JSON renderers, read-only boundary
    tests, and focused Pest coverage.
- [x] Port `orbit-scheduler` Artisan-command daemon and Supervisor program
  rendering.
  - [x] Hidden `orbit-scheduler` daemon command, one-tick test path,
    scheduler-specific Supervisor program definition/render/install helper on
    top of the shared runtime-backend renderer, and focused renderer coverage.
- [x] Port scheduler heartbeat reporting and run-history intake endpoint.
  - [x] Added authenticated scheduler heartbeat intake keyed to the caller's
    WireGuard node identity, `scheduler_states` upsert behavior, typed
    `StoreSchedulerHeartbeatRequest` / `SchedulerHeartbeatResponse` client
    DTOs, and focused API / gateway-client coverage.
  - [x] Run-history intake was already present through the authenticated
    `POST /api/schedules/runs` endpoint and typed scheduler gateway request.
- [x] Port schedule doctor probe and fix map.
  - [x] Schedule abstraction seed exists at `docs/abstractions/9_schedule.md`.
  - [x] Global `doctor` command/family dispatcher and doctor API transport are
    now available through the State Families And Doctor Workstream.
  - [x] Read-only schedule doctor probe foundation covers registry intent,
    target eligibility, runtime-backend short-circuiting, scheduler program
    presence/liveness, heartbeat freshness, and registry-sync freshness.
  - [x] Verify-mode doctor dispatcher/API integration is ported for
    `--family=schedule`.
  - [x] Fix map handles `schedule.scheduler_missing` and
    `schedule.scheduler_stopped` through `OrbitSchedulerProgramRenderer` and
    Supervisor control.
  - [x] Stale schedule lock drift and `--fix` cleanup are ported for
    `schedule.lock_stuck`.
  - [x] Run-history hook drift is ported for
    `schedule.run_history_hook_missing` and
    `schedule.run_history_hook_mismatch`, using deterministic scheduler-side
    hook paths under `/opt/orbit/schedules/hooks/` and hash-based content
    comparison.

## Schedule family

- [x] Port schedule family.
  - Schedule command-family behavior is ported through logs/run/CRUD, scheduler
    daemon rendering, heartbeat intake, and run-history intake.
  - [x] Schedule abstraction seed exists at `docs/abstractions/9_schedule.md`.
  - [x] Read-only schedule doctor probe foundation and verify-mode doctor
    dispatcher/API integration are ported.
  - [x] Schedule doctor fix map is ported for documented safe fixes:
    `schedule.scheduler_missing` re-renders and loads the Orbit Scheduler
    Supervisor program, `schedule.scheduler_stopped` starts the program, and
    `schedule.lock_stuck` releases stale locks.
    - [x] Run-history hook drift and repair are ported for
      `schedule.run_history_hook_missing` and
      `schedule.run_history_hook_mismatch`.
    - [x] Resident `orbit-scheduler` tick execution: local scheduler ticks
      record heartbeat/registry sync state, evaluate due local schedules from
      gateway intent, claim local overlap locks, execute the rendered
      run-history hook material through the local `RemoteShell` edge, and
      write/report durable run history.
    - [x] Gateway-to-node schedule registry sync: scheduler-authenticated nodes
      can fetch the enabled schedule intent targeting their node through a
      typed sync request, app-node scheduler ticks upsert that local intent
      before due evaluation, stale local schedule intent is pruned, and
      heartbeat sync timestamps are reported after refresh.
    - [x] E2E scheduler tick gate: Docker app-node scheduler syncs gateway
      schedule intent, executes the schedule hook locally, and reports durable
      run history through the gateway intake.
