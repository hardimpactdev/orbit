# 7_process — Process Workstream

Detail file for the process command family. Top-level status lives in
[`PORTING.md`](PORTING.md). Doc authority: `docs/commands/7_process/`.

All commands ported with gateway-local + Saloon forwarding + activity
contracts. Crash-event intake from app-node runtime hooks ported. Process
docs are reshaped around the runtime backend (Supervisor) and runtime-unit
vocabulary; see
[`../2026-05-05-supervisor-runtime-backend-plan.md`](../2026-05-05-supervisor-runtime-backend-plan.md).

## Commands

- [x] `process:list` — registry read + latest lifecycle event projection.
  `lane=none` (fast gateway-intent read).
- [x] `process:add` — intent write + Supervisor runtime-unit rendering for
  main app and existing workspaces + optional `--start` + repairable
  warnings on post-intent drift.
- [x] `process:edit` — intent update + runtime-unit re-render + optional
  `--restart` + repairable warnings.
- [x] `process:remove` — destructive intent removal + runtime-unit cleanup
  + destructive consent + repairable warnings.
- [x] `process:start` / `process:stop` / `process:restart` — Supervisor
  lifecycle actions + durable `started`/`stopped` events + partial bulk
  failure reporting.
- [x] `process:logs` — Supervisor stdout/stderr read + bounded JSON +
  human follow-mode + `--lines` / `--json --follow` validation.
- [x] Authenticated app-node `crashed` event intake with event-id
  idempotency, runtime-unit intent resolution for main/workspace units, and
  unmatched-unit history preservation. Runtime hook material convergence is
  owned by `doctor --family=process`.

Pest under `tests/Feature/Commands/Processes/`. The process family is
tested entirely at the in-memory layer; Supervisor runtime realism is
exercised through the shared
[`runtime-backend-scheduler.md`](runtime-backend-scheduler.md) E2E gates.

## Family doctor

`ProcessesProbe` covers record completeness, owner-app eligibility,
runtime-context expansion, runtime backend availability, Supervisor program
presence/content, restart policy, runtime environment, lifecycle event
notifier material, and stale runtime units.
