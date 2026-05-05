# Supervisor Runtime Backend Documentation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Update Orbit's product documentation so processes and schedules are defined in terms of a portable runtime backend, with `supervisord` plus an `orbit-scheduler` daemon as the cross-platform candidate.

**Architecture:** Documentation changes come first. The docs should stop treating `systemd` units and timers as the product model, define runtime backends explicitly, and preserve Linux systemd as a backend while introducing Supervisor as the backend that can work on Linux, Docker, and macOS app nodes.

**Tech Stack:** Laravel CLI documentation, Orbit command contracts, Supervisor, systemd, Orbit-authored scheduler daemon, Incus E2E, Docker topology E2E.

---

## Context

Orbit currently documents app nodes as Ubuntu-only and runtime artifacts as systemd services/timers. That matches the current implementation, but it blocks a future where macOS can act as an app node and Docker can run feature tests that exercise real process and schedule behavior.

The proposed direction is:

- `supervisord` manages both app/process daemons and the `orbit-scheduler` daemon.
- `orbit-scheduler` owns schedule evaluation, due-job execution, overlap policy, retries, missed-run behavior, and run history.
- `systemd` remains a Linux backend for production/VM nodes until the product decision says otherwise.
- `launchd` or systemd may still keep `supervisord` alive on a host, but Orbit's process/schedule command contracts should target Orbit runtime backends, not host init details.
- `doctor` becomes the source of truth for runtime backend liveness and drift checks.

## Documentation File Map

- Modify `docs/BLUEPRINT.md`: replace systemd-as-product language with runtime backend terminology and introduce `orbit-scheduler`.
- Modify `docs/BUILDING-BLOCKS.md`: update app-node stack and technology table to distinguish host init, process runtime, and schedule runtime.
- Modify `docs/CONCEPTS.md`: add or update runtime backend concepts if this file has overlapping process/schedule terminology.
- Modify `docs/commands/7_process/README.md`: define process runtime backend semantics, Supervisor/systemd backend expectations, logs, crash events, and doctor ownership.
- Modify `docs/commands/7_process/process-doctor.md`: expand probes from systemd-unit checks to backend-aware checks.
- Modify `docs/commands/7_process/*/technical/*.md`: replace hardcoded systemd wording where command behavior should say runtime backend, while preserving systemd-specific examples only where explicitly backend-specific.
- Modify `docs/commands/9_schedule/README.md`: replace systemd timers as the product backend with `orbit-scheduler` plus runtime backend supervision.
- Modify `docs/commands/9_schedule/schedule-doctor.md`: define scheduler daemon liveness, heartbeat, queue/lock/run-history checks, and backend drift.
- Modify `docs/commands/9_schedule/*/technical/*.md`: update contracts for schedule execution through `orbit-scheduler`.
- Modify `docs/commands/6_workspace/README.md` and `docs/commands/6_workspace/**/technical/*.md`: update inherited process/runtime-unit wording and classify which workspace flows require a live runtime backend.
- Modify `TESTING.md`: document the final provider split after command contracts are updated.

## Runtime Vocabulary

Use these terms consistently across the docs:

- **Runtime backend:** The node-local process manager Orbit targets for app/process daemons and the scheduler daemon.
- **Systemd runtime backend:** Linux backend using systemd services and journald.
- **Supervisor runtime backend:** Portable backend using `supervisord` programs and Supervisor logs/control APIs.
- **Orbit scheduler daemon:** Long-running Orbit process supervised by the runtime backend. It evaluates schedule intent and executes due work.
- **Host init:** The host-native mechanism that keeps the runtime backend alive, such as systemd on Linux or launchd/Homebrew services on macOS. Host init is not the Orbit process model.

Avoid using `systemd unit` as a synonym for Orbit process intent. Say `runtime unit` or `runtime program` unless the section is explicitly about the systemd backend.

## Task 1: Product Model Docs

**Files:**
- Modify `docs/BLUEPRINT.md`
- Modify `docs/BUILDING-BLOCKS.md`
- Modify `docs/CONCEPTS.md`

- [ ] **Step 1: Update app-node platform language**

Change the app-node section from Ubuntu-only to a capability-based model:

```markdown
### App Node

An app node runs Orbit-managed workloads. Linux app nodes are the production
baseline. macOS app nodes are a supported direction for local and development
workloads when the configured runtime backend can satisfy the required process,
schedule, filesystem, proxy, and networking capabilities.
```

- [ ] **Step 2: Separate host init from Orbit runtime**

In the technology stack tables, replace rows shaped like:

```markdown
| Process runtime | systemd services and journald |
| Schedule runtime | systemd timers and services |
```

with:

```markdown
| Host init | systemd on Linux, launchd/Homebrew services on macOS |
| Process runtime | Runtime backend: systemd services or Supervisor programs |
| Schedule runtime | `orbit-scheduler` daemon supervised by the runtime backend |
```

- [ ] **Step 3: Introduce backend-neutral process and schedule concepts**

Update the process/schedule concept sections so they say:

```markdown
Process runtime artifacts are physical backend artifacts derived from app,
workspace, and process intent. On the systemd backend they are services. On the
Supervisor backend they are programs. The artifact is not a gateway entity.
```

```markdown
Schedules are evaluated by `orbit-scheduler`. The scheduler daemon reads gateway
schedule intent, claims due work with Orbit-owned locks, executes jobs on the
target node, records run history, and emits health data for doctor.
```

- [ ] **Step 4: Run docs lint**

Run:

```bash
composer docs-lint
```

Expected: `{"tool":"docs-lint","result":"passed","issues":0,"errors":0,"warnings":0}`.

- [ ] **Step 5: Commit**

```bash
git add docs/BLUEPRINT.md docs/BUILDING-BLOCKS.md docs/CONCEPTS.md
git commit -m "docs: define portable runtime backend model"
```

## Task 2: Process Command Contracts

**Files:**
- Modify `docs/commands/7_process/README.md`
- Modify `docs/commands/7_process/process-concepts.md`
- Modify `docs/commands/7_process/process-doctor.md`
- Modify `docs/commands/7_process/5_process-start/technical/1_process-start.md`
- Modify `docs/commands/7_process/6_process-stop/technical/1_process-stop.md`
- Modify `docs/commands/7_process/7_process-restart/technical/1_process-restart.md`
- Modify `docs/commands/7_process/8_process-logs/technical/1_process-logs.md`
- Modify JSON renderer docs under `docs/commands/7_process/*/technical/6.2_*.md`

- [ ] **Step 1: Define runtime backend ownership in the process README**

Replace systemd-specific domain rules with backend-neutral rules:

```markdown
- Runtime artifacts are physical backend artifacts derived from app, optional
  workspace, and process intent. On systemd they are services; on Supervisor
  they are programs. They are not the product model.
- Runtime artifact names are backend-specific renderings of the same Orbit
  identity: app, workspace or main instance, and process.
- Logs come from the configured runtime backend. The systemd backend reads
  journald. The Supervisor backend reads Supervisor-managed stdout/stderr log
  files or the Supervisor control API.
```

- [ ] **Step 2: Preserve systemd naming as backend-specific**

Move the existing name format:

```markdown
`orbit_<app>_<workspace|main>_<process>.service`
```

under a `Systemd Backend` subsection. Add the Supervisor equivalent:

```markdown
Supervisor program names use the same identity segments without the `.service`
suffix: `orbit_<app>_<workspace|main>_<process>`.
```

- [ ] **Step 3: Update lifecycle command contracts**

For `process:start`, `process:stop`, and `process:restart`, replace phrases such
as `systemd reported a start failure` with:

```markdown
the runtime backend reported a start failure
```

Keep examples that show `systemd` only in backend-specific issue metadata.

- [ ] **Step 4: Update process logs contract**

Document backend-specific log sources:

```markdown
The systemd backend reads journald for the runtime artifact. The Supervisor
backend reads the configured stdout/stderr log files for the program, falling
back to Supervisor control API metadata when logs are unavailable.
```

- [ ] **Step 5: Update process doctor**

Add probe layers:

```markdown
1. Runtime backend configured for the target node.
2. Runtime backend binary exists.
3. Runtime backend daemon is reachable.
4. Expected process artifacts exist in the backend.
5. Backend artifact shape matches gateway process intent.
6. Backend state matches the latest requested lifecycle state when that state is asserted.
7. Crash/event hook material exists for processes that require it.
8. Extra Orbit-owned backend artifacts without matching gateway intent are reported as drift.
```

Add issue codes:

```markdown
| `process.runtime_backend_unavailable` | Runtime backend daemon is not reachable. |
| `process.runtime_backend_mismatch` | Node is configured for one backend but observed artifacts belong to another backend. |
| `process.runtime_artifact_missing` | Expected backend artifact is absent. |
| `process.runtime_artifact_mismatch` | Backend artifact exists but differs from rendered intent. |
| `process.runtime_artifact_extra` | Orbit-owned backend artifact has no matching gateway intent. |
```

- [ ] **Step 6: Run docs lint**

Run:

```bash
composer docs-lint
```

Expected: docs lint passes with zero issues.

- [ ] **Step 7: Commit**

```bash
git add docs/commands/7_process
git commit -m "docs: make process runtime backend-aware"
```

## Task 3: Schedule Command Contracts

**Files:**
- Modify `docs/commands/9_schedule/README.md`
- Modify `docs/commands/9_schedule/schedule-doctor.md`
- Modify technical contracts under `docs/commands/9_schedule/*/technical/*.md`

- [ ] **Step 1: Replace systemd timers with `orbit-scheduler` as product contract**

Update the schedule README from:

```markdown
Systemd timers and services are the current backend on supported Ubuntu nodes.
They are not the product model.
```

to:

```markdown
`orbit-scheduler` is the product runtime for recurring work. It is supervised by
the node runtime backend and is responsible for due-job evaluation, overlap
policy, execution, run history, and liveness reporting.
```

- [ ] **Step 2: Define scheduler daemon responsibilities**

Add:

```markdown
The scheduler daemon must:

- sync gateway schedule intent for schedules targeting the node;
- evaluate Orbit interval expressions in the configured timezone;
- claim due runs with Orbit-owned locks;
- execute command or script schedules in the target context;
- record started, completed, failed, skipped, and missed run history;
- enforce overlap policy;
- emit a heartbeat for doctor;
- expose enough local state for `doctor --family=schedule` to distinguish
  scheduler-down, scheduler-stuck, registry-unreachable, and job-failed states.
```

- [ ] **Step 3: Update schedule doctor probe layers**

Replace timer/service artifact layers with scheduler layers:

```markdown
1. Registry intent.
2. Target eligibility.
3. Node eligibility.
4. Runtime backend availability.
5. `orbit-scheduler` program/artifact presence.
6. Scheduler process liveness.
7. Scheduler heartbeat freshness.
8. Schedule cache/sync freshness.
9. Due-run lock health.
10. Run-history hook and writer health.
11. Extra Orbit-owned legacy backend artifacts reported as migration drift.
```

- [ ] **Step 4: Update schedule issue codes**

Add or replace issue codes:

```markdown
| `schedule.scheduler_missing` | Runtime backend has no `orbit-scheduler` artifact. |
| `schedule.scheduler_stopped` | `orbit-scheduler` is registered but not running. |
| `schedule.scheduler_stale` | Scheduler heartbeat is older than the allowed threshold. |
| `schedule.registry_sync_stale` | Scheduler has not synced schedule intent recently enough. |
| `schedule.lock_stuck` | A due-run lock exceeds the configured stale-lock threshold. |
| `schedule.legacy_artifact_extra` | Orbit-owned systemd timer/service artifact remains after migration to scheduler. |
```

- [ ] **Step 5: Run docs lint**

Run:

```bash
composer docs-lint
```

Expected: docs lint passes with zero issues.

- [ ] **Step 6: Commit**

```bash
git add docs/commands/9_schedule
git commit -m "docs: define orbit scheduler runtime contract"
```

## Task 4: Workspace Contract Updates

**Files:**
- Modify `docs/commands/6_workspace/README.md`
- Modify `docs/commands/6_workspace/workspace-concepts.md`
- Modify technical contracts for `workspace:new`, `workspace:setup`, `workspace:remove`, `workspace:show`, `workspace:history`, and `workspace:log`

- [ ] **Step 1: Replace inherited systemd-unit language**

Use:

```markdown
Inherited process runtime artifacts are rendered through the node's configured
runtime backend. On the systemd backend they are services. On the Supervisor
backend they are programs.
```

- [ ] **Step 2: Classify workspace commands by runtime dependency**

Document this split:

```markdown
- Registry-only reads such as `workspace:show` and `workspace:history` do not
  require live runtime backend access.
- `workspace:new`, `workspace:setup`, and `workspace:remove` require a live
  runtime backend when they create, update, remove, or verify inherited process
  runtime artifacts.
- `workspace:log` reads durable setup/removal history by default. Live runtime
  logs belong to process commands.
```

- [ ] **Step 3: Update command test mapping docs**

For every workspace technical contract that currently names systemd, update the
test mapping to require backend-aware coverage:

```markdown
| `tests/E2E/...` | Real backend coverage for inherited process runtime artifacts. Systemd behavior runs on Incus; Supervisor behavior can run on Docker or macOS-capable nodes when that backend is implemented. |
```

- [ ] **Step 4: Run docs lint**

Run:

```bash
composer docs-lint
```

Expected: docs lint passes with zero issues.

- [ ] **Step 5: Commit**

```bash
git add docs/commands/6_workspace
git commit -m "docs: make workspace runtime artifacts backend-aware"
```

## Task 5: Testing Boundary Docs

**Files:**
- Modify `TESTING.md`
- Modify `docs/PORTING.md` only if it still documents Docker/systemd assumptions that conflict with the command contracts.

- [ ] **Step 1: Update provider capability matrix**

Add a table:

```markdown
| Capability | Docker + Supervisor | Incus + systemd |
| --- | --- | --- |
| Gateway API and CA trust | yes | yes |
| Registry-backed command behavior | yes | yes |
| Supervisor process lifecycle | yes | no |
| `orbit-scheduler` daemon behavior | yes | yes, when supervised by configured backend |
| systemd service behavior | no | yes |
| systemd timer behavior | legacy only | yes for legacy/backend migration tests |
| Real WireGuard interfaces and peer routing | no | yes |
| VM boot, cloud-init, package install mutation | no | yes |
```

- [ ] **Step 2: Define E2E selection rules**

Document:

```markdown
Use Docker for feature tests that need gateway API, CA trust, registry state,
Supervisor runtime behavior, or `orbit-scheduler` behavior without VM networking.

Use Incus for tests that need systemd behavior, VM boot, package installation,
real SSH daemon behavior, real WireGuard interfaces, peer routing, or host-level
mutation.
```

- [ ] **Step 3: Add capability requirement examples**

Add examples:

```php
E2ETopologyFactory::fromEnvironment()
    ->requireCapabilities(E2ETopologyCapabilities::vm())
    ->require(E2ETopologyKind::ControlGatewayDevProd);
```

```php
E2ETopologyFactory::fromEnvironment()
    ->requireCapabilities(E2ETopologyCapabilities::containerFeature())
    ->require(E2ETopologyKind::ControlGatewayDevProd);
```

If `containerFeature()` is not the right future requirement shape, create a
follow-up implementation task to add narrower runtime capability constructors
such as `supervisorRuntime()` and `kernelNetworking()`.

- [ ] **Step 4: Run docs lint**

Run:

```bash
composer docs-lint
```

Expected: docs lint passes with zero issues.

- [ ] **Step 5: Commit**

```bash
git add TESTING.md docs/PORTING.md
git commit -m "docs: document runtime backend test boundaries"
```

## Task 6: Follow-Up Implementation Plan

**Files:**
- Create `docs/2026-05-05-supervisor-runtime-implementation-plan.md`

- [ ] **Step 1: Create the implementation plan only after Tasks 1-5 are merged**

The implementation plan must be based on the updated command contracts. It
should not start from current systemd-specific code assumptions.

- [ ] **Step 2: Include these implementation areas**

The next plan should include:

```markdown
- runtime backend configuration model;
- Supervisor installer/checker;
- Supervisor process renderer;
- Supervisor control client;
- `orbit-scheduler` daemon command;
- scheduler heartbeat state;
- scheduler locks and overlap policy;
- scheduler run-history writer;
- backend-aware process doctor;
- backend-aware schedule doctor;
- Docker E2E topology with Supervisor and scheduler enabled;
- Incus E2E coverage for systemd backend compatibility;
- migration/adoption behavior for existing systemd timer/service artifacts.
```

- [ ] **Step 3: Defer test harness changes until docs are stable**

Do not rename or expand E2E lanes before the documentation explicitly says which
runtime backends are product-supported and which test provider proves each
capability.

## Self-Review Checklist

- [ ] Product docs no longer imply that systemd is the process or schedule product model.
- [ ] Command docs still acknowledge systemd as a supported Linux backend.
- [ ] Supervisor is introduced as a candidate backend, not silently assumed to exist.
- [ ] `orbit-scheduler` is described as product behavior that must be implemented and tested.
- [ ] `doctor` owns runtime backend liveness and drift checks.
- [ ] Docker is scoped to feature/runtime behavior that it can actually prove.
- [ ] Incus remains required for VM, systemd, package installation, SSH daemon, and WireGuard mechanics.
- [ ] Testing docs are updated after command contracts, not before.
