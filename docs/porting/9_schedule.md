# 9_schedule — Schedule Workstream

Detail file for the schedule command family. Top-level status lives in
[`PORTING.md`](PORTING.md). Doc authority: `docs/commands/9_schedule/`.

All commands ported with gateway-local + Saloon forwarding + activity
contracts. Schedule docs are reshaped around the Orbit Scheduler resident
daemon; see
[`../2026-05-05-supervisor-runtime-backend-plan.md`](../2026-05-05-supervisor-runtime-backend-plan.md).

## Commands

- [x] `schedule:list` / `schedule:show` — registry reads + Saloon
  forwarding + app/node filter validation + latest run summary projection.
- [x] `schedule:add` — gateway-owned intent write + execution-source
  validation + scheduler reachability warnings.
- [x] `schedule:remove` — destructive intent removal + Saloon forwarding.
- [x] `schedule:run` — manual execution + Saloon forwarding + target-node
  execution via `RemoteShell` + durable run-history persistence + captured
  output on failure.
- [x] `schedule:logs` — durable run-history read + run-id selection +
  independent stdout/stderr line limiting.

Pest under `tests/Feature/Commands/Schedule/`. Docker feature E2E
`tests/E2E/ScheduleSchedulerTickTest.php` exercises gateway intent sync,
local hook execution, and run-history reporting.

## Daemon and intake

- [x] Hidden `orbit-scheduler` Artisan daemon with one-tick test path and
  scheduler-specific Supervisor program rendering on top of the shared
  runtime-backend renderer.
- [x] Authenticated scheduler heartbeat intake (`StoreSchedulerHeartbeatRequest`)
  keyed to caller WireGuard identity; `scheduler_states` upsert.
- [x] Authenticated run-history intake (`POST /api/schedules/runs` +
  `StoreScheduleRunRequest`).
- [x] Resident tick execution: heartbeat + registry sync state, due-schedule
  evaluation, overlap locks, hook execution via local `RemoteShell`, durable
  run-history report.
- [x] Gateway-to-node registry sync: scheduler-authenticated nodes fetch
  enabled schedule intent targeting their node, upsert locally before
  due evaluation, prune stale local intent, and report heartbeat sync
  timestamps.

## Family doctor

`SchedulesProbe` covers registry intent, target eligibility, runtime-backend
short-circuiting, scheduler program presence/liveness, heartbeat freshness,
and registry-sync freshness. Verify-mode dispatcher integration via
`--family=schedule`. Fix map handles `scheduler_missing`,
`scheduler_stopped`, `lock_stuck`, `run_history_hook_missing`, and
`run_history_hook_mismatch` (deterministic hook paths under
`/opt/orbit/schedules/hooks/`, hash-based comparison).
