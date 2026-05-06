# Runtime Backend And Scheduler Workstream

The runtime backend (Supervisor) and the Orbit Scheduler are introduced as
product behavior in the doc reshape and require implementation work shared
across the process and schedule families.

## Workstream

- [x] Document the runtime backend (Supervisor) and Orbit Scheduler in
  blueprint, mission, building blocks, concepts, process docs, schedule
  docs, and workspace docs. See
  [`../2026-05-05-supervisor-runtime-backend-plan.md`](../2026-05-05-supervisor-runtime-backend-plan.md).
- [x] Cross-family coherence cleanup: add `supervisor` as a Required
  Baseline tool catalog entry (closing the doctor handoff loop from
  process / schedule), document the host-services-as-Supervisor-peers
  rule, add deploy `Cross-family invocation` so deploy steps can call
  `process:restart`, document `app:new` does not auto-create the
  Laravel scheduler, and distinguish the Orbit Scheduler daemon from
  the `orbit_scheduler` Supervisor program in `CONCEPTS.md`.
- [x] Mitigate BLUEPRINT / BUILDING-BLOCKS drift on the runtime backend +
  scheduler with explicit co-edit cross-references and a one-line
  contract / implementation split note in both files.
- [x] Make per-workspace Supervisor config explicit: each workspace's
  inherited runtime units render as separate Supervisor programs with
  workspace-specific working directory, environment, and log paths
  derived from the parent app's process definition.
- [x] Add Supervisor installation to gateway and app node provisioning.
  - `bin/install-orbit` installs the Ubuntu `supervisor` package with platform
    prerequisites, and `bin/_e2e-deps.sh` includes `supervisor` in prepared E2E
    base-image packages so `--skip-prerequisites` role provisioning still has the
    runtime backend package available.
- [x] Add the runtime backend reachability probe shared by process and
  schedule doctor.
  - `RuntimeBackendProbe` checks `supervisorctl` presence plus control-socket
    responsiveness through the existing gateway-owned `RemoteShell` edge.
    Current process runtime-unit enactment uses the probe; process/schedule
    doctor command wiring remains in the family command implementation items.
- [x] Add the Supervisor program renderer shared by process and schedule
  enactment.
  - `SupervisorProgramRenderer` now lives under the runtime-backend service
    boundary and renders reusable Supervisor program definitions plus install
    scripts. The process-family renderer builds process-specific definitions
    on top of that shared renderer; schedule-family code now builds the
    `orbit_scheduler` program definition through the same backend renderer.
- [x] Add the `orbit-scheduler` Artisan-command daemon.
  - Hidden `orbit-scheduler` command runs the resident daemon loop and supports
    one-tick execution for tests/smoke checks. The current tick is intentionally
    schema-free and reports zero due/executed schedules; schedule sync,
    locks, heartbeat persistence, and run-history reporting remain in the
    dedicated scheduler state/API items below.
- [x] Add scheduler local-state schema (locks, heartbeat, last-sync).
  - Added `scheduler_states` for per-node local heartbeat and registry-sync
    timestamps, plus `schedule_locks` for per-node stable schedule-key locks.
    Both tables are local-node scheduler state foundations; gateway schedule
    intent and durable run-history schemas remain separate tracker items.
- [x] Add scheduler-to-gateway authentication using the existing WireGuard
  node identity.
  - Scheduler-originated gateway calls reuse the existing gateway transport and
    `WireGuardIdentity` middleware: the gateway authenticates the scheduler by
    source WireGuard address mapped to an active node, while
    `GatewayConnector::forScheduler()` only labels the client as `scheduler`
    for activity/diagnostic context and is not trusted as identity.
- [x] Add the gateway run-history intake endpoint and typed request.
  - Added gateway-owned `schedule_runs` history storage, authenticated
    `POST /api/schedules/runs` intake keyed to the caller's WireGuard node
    identity, and typed `StoreScheduleRunRequest` / `ScheduleRunResponse`
    client DTOs for scheduler-to-gateway reporting.
- [x] Docker E2E base image runs `supervisord -n` as PID 1 (under `tini`)
  and ships pre-installed Supervisor and `orbit_scheduler` program files.
  - Docker topology runtime images install `supervisor` and `tini`, boot through
    `tini` into `supervisord -n`, and preinstall Supervisor programs for `sshd`
    plus `orbit_scheduler` so Docker feature E2E can exercise the runtime
    backend and scheduler without relying on host init.
- [x] Add Docker E2E coverage for runtime backend behavior and scheduler
  liveness.
  - `tests/E2E/RuntimeBackendSchedulerTest.php` verifies the prepared Docker
    control-gateway topology exposes a live Supervisor backend with `sshd` and
    `orbit_scheduler` registered and running.
- [x] Add Incus E2E coverage where host init or VM-only behavior is part of
  the assertion.
  - `tests/E2E/RuntimeBackendHostInitTest.php` requires VM capabilities and
    verifies `supervisor.service` is active under host init while
    `supervisorctl status` responds on the gateway.
