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
  owned by `doctor --family=process`. Docker E2E:
  `tests/E2E/ProcessCrashEventIngestTest.php`, run with
  `composer test:e2e:docker -- --filter='ingests authenticated crashed events from an app node through the gateway api'`.

Pest under `tests/Feature/Commands/Processes/`. The process family is
implemented and currently tested at the in-memory layer; Supervisor runtime
realism is exercised through command-level Docker feature E2E and the shared
[`runtime-backend-scheduler.md`](runtime-backend-scheduler.md) E2E gates.

## E2E debt

- [x] Command-port Docker feature E2E:
  `tests/E2E/ProcessCommandTest.php` for `process:add`, `process:edit`,
  `process:start`, `process:logs`, `process:restart`, `process:stop`, and
  `process:remove` against a seeded prepared-topology app on `app-dev-1`.
  `composer test:e2e:docker -- --filter='manages process intent runtime lifecycle and bounded logs'`.
- [x] Crash-event intake Docker feature E2E:
  `tests/E2E/ProcessCrashEventIngestTest.php` posts a `crashed` event through
  the gateway HTTP kernel with the app node WireGuard identity, proving
  active app-node authentication, runtime-unit intent matching, persistence,
  and event-id idempotency.
  `composer test:e2e:docker -- --filter='ingests authenticated crashed events from an app node through the gateway api'`.

## Family doctor

`ProcessesProbe` covers record completeness, owner-app eligibility,
runtime-context expansion, runtime backend availability, Supervisor program
presence/content, restart policy, runtime environment, lifecycle event
notifier material, and stale runtime units.
