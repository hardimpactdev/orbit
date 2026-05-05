# Supervisor Runtime Backend Documentation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Update Orbit's product documentation so processes and schedules are defined in concept-first terms, with Supervisor as the runtime backend and a resident `orbit-scheduler` daemon as the executor for recurring work. Replace systemd as the product-level runtime, not parallelize alongside it.

**Architecture:** Documentation changes only. The clean-rebuild process and schedule code is not yet ported (`docs/PORTING.md` shows both workstreams entirely `[ ]`), so there is no in-clean-repo migration burden. Old systemd evidence in `../orbit-old-may` remains porting reference, not canon.

**Tech Stack:** Laravel CLI documentation, Orbit command contracts, Supervisor (`supervisord`), `orbit-scheduler` Artisan-command daemon supervised by Supervisor, Incus E2E, Docker E2E.

---

## Context

The current product docs treat systemd services and timers as the runtime model. That bakes a Linux host-init detail into the product contract, and `journald` into log behavior. Two product moves change this:

1. **Supervisor is the runtime backend.** App nodes and the gateway run `supervisord`. Orbit-managed long-running processes are Supervisor programs. systemd remains the host init that keeps `supervisord` itself alive on Ubuntu, but it is not the product-level process runtime.
2. **`orbit-scheduler` is the schedule executor.** A long-running Orbit Artisan command supervised by Supervisor on every gateway and app node. It evaluates schedule intent, claims due runs with local locks, executes them in the target context, and reports run history back to the gateway over the existing CLI-to-gateway HTTPS edge.

The plan amends the BLUEPRINT Explicit Non-Goal that bans "an Orbit daemon or app-node control plane" with a narrow exception: `orbit-scheduler` is a gateway-*client* daemon. It does not accept inbound RPC, does not write fleet intent, and does not orchestrate other nodes. It uses the same WireGuard node identity as the CLI client.

App-node platform support is not changing in this plan. Gateways and app nodes remain Ubuntu-only. The runtime backend abstraction is introduced for clarity and Docker-E2E reach, not to enable macOS app nodes.

## Documentation File Map

- Modify `docs/BLUEPRINT.md`: add runtime backend / Orbit Scheduler vocabulary; rewrite Processes and Schedules sections; update State Families backend examples; amend Explicit Non-Goals.
- Modify `docs/MISSION.md`: align "State families" and "Full Laravel" paragraphs with the runtime backend / scheduler model.
- Modify `docs/BUILDING-BLOCKS.md`: replace systemd rows in Technology Stack with Supervisor and `orbit-scheduler`; update App Node and Host Services lists; update overview diagram.
- Modify `docs/CONCEPTS.md`: add Runtime Backend, Runtime Unit, Supervisor Program, Orbit Scheduler, Scheduler Heartbeat, Schedule Run, Schedule Lock, and Run-History Hook entries.
- Modify `docs/commands/7_process/README.md`, `docs/commands/7_process/process-concepts.md`, `docs/commands/7_process/process-doctor.md`, and `docs/commands/7_process/*/technical/*.md`.
- Modify `docs/commands/9_schedule/README.md`, `docs/commands/9_schedule/schedule-concepts.md`, `docs/commands/9_schedule/schedule-doctor.md`, and `docs/commands/9_schedule/*/technical/*.md`.
- Modify `docs/commands/6_workspace/README.md`, `docs/commands/6_workspace/workspace-concepts.md`, and the technical contracts that reference inherited process runtime artifacts.
- Modify `TESTING.md`: provider capability matrix and E2E selection rules.
- Modify `docs/PORTING.md`: add a Supervisor / Scheduler workstream and update Process and Schedule workstream pointers.

## Runtime Vocabulary

These terms are canonical. Use them consistently across the docs.

- **Runtime backend.** The host-level supervisor that owns Orbit-managed long-running processes on a node. Supervisor (`supervisord`) is the runtime backend on every gateway and app node.
- **Runtime unit.** Abstract noun for an Orbit-managed long-running process. The product model. A runtime unit is rendered as a Supervisor program when enacted.
- **Supervisor program.** Backend-specific name for the rendered runtime unit. Program names use the same identity segments as before, without the `.service` suffix: `orbit_<app>_<workspace|main>_<process>`.
- **Orbit Scheduler.** The resident `orbit-scheduler` daemon. One Supervisor program per node, named `orbit_scheduler`, runs `php artisan orbit:scheduler:run`. Owns schedule evaluation, due-run dispatch, overlap policy, run history, and heartbeat.
- **Scheduler heartbeat.** Periodic state the Orbit Scheduler writes locally so doctor can verify liveness without requiring a long-lived inbound channel.
- **Schedule run.** One execution of a schedule. Recorded as durable gateway history with `started_at`, `finished_at`, `exit_code`, and `status` (`completed`, `failed`, `skipped`, `missed`).
- **Schedule lock.** Local node lock that prevents overlapping runs of the same schedule. Lock state lives in the node's local Orbit SQLite, not in gateway intent.
- **Run-history hook.** Orbit-managed material the scheduler uses to capture stdout/exit-status from a schedule run before reporting it to the gateway run-history intake.
- **Host init.** The host's own service manager that keeps `supervisord` alive. systemd on Ubuntu. Host init is not the product runtime.

Avoid `runtime artifact` (use runtime unit), `service`/`timer` as the abstract noun (Supervisor doesn't have those concepts), and `journald` outside Supervisor backend implementation notes.

---

## Task 1: BLUEPRINT.md

**Files:**
- Modify `docs/BLUEPRINT.md`

- [x] **Step 1: Add Runtime Backend And Orbit Scheduler subsection under Runtime Model**

Insert a new subsection between `### Apps` and `### Workspaces`:

```markdown
### Runtime Backend And Orbit Scheduler

The runtime backend is the host-level supervisor that owns Orbit-managed
long-running processes on a node. Supervisor (`supervisord`) is the runtime
backend on every gateway and app node.

Host init keeps the runtime backend alive. On Ubuntu hosts, systemd plays
that role through the distro `supervisor.service` unit. In Docker E2E
topologies, the Docker daemon's container restart policy plays that role
and `supervisord` runs as PID 1 inside the container (typically under
`tini` for signal forwarding and zombie reaping). Host init is not the
product-level process runtime.

Orbit-managed long-running processes are runtime units. A runtime unit is the
product concept; the rendered Supervisor program is the backend artifact. The
program name keeps the Orbit identity segments and drops the systemd `.service`
suffix.

Recurring work is owned by the Orbit Scheduler, a resident `orbit-scheduler`
Artisan-command daemon supervised by the runtime backend on every gateway and
app node. The Orbit Scheduler evaluates due schedules at least once per
minute, aligned to wall-clock minute boundaries. Schedule expressions remain
minute-resolution; the tick interval is an implementation detail and may be
tightened in future without changing the schedule expression contract.
Sub-minute work is not a schedule concern — it belongs in a runtime unit.
Each tick:

- fetches schedule intent for schedules targeting the local node from the
  gateway over the existing CLI-to-gateway HTTPS edge;
- evaluates Orbit interval expressions in the configured timezone against the
  current minute;
- claims due runs with local schedule locks stored in the node's local Orbit
  SQLite;
- executes command or script schedules in the target context;
- records `started`, `completed`, `failed`, `skipped`, and `missed` runs as
  durable gateway history through a typed run-history intake;
- writes a local heartbeat after the tick completes so doctor can distinguish
  scheduler-down, scheduler-stuck, and registry-sync-stale states.

The same per-tick evaluation logic is exposed through `orbit schedule:run` for
ad-hoc operator use. The daemon is essentially a `schedule:run` loop aligned
to wall-clock minute boundaries.

The Orbit Scheduler is a gateway-client daemon. It does not accept inbound RPC,
does not own fleet intent, and does not orchestrate other nodes. It authenticates
to the gateway API with the same WireGuard node identity used by the CLI client.
```

- [x] **Step 2: Rewrite the Processes subsection**

Replace lines describing systemd services with concept-first language. The runtime-unit filename block stays but loses the `.service` suffix:

```markdown
Process units are runtime units derived from `(app, optional workspace,
process)`. The product model is the runtime unit; the rendered Supervisor
program is the backend artifact. Runtime units are not gateway state rows.
Their expected content is rendered from primary state when Orbit enacts or
probes.

For each process definition, Orbit expects one runtime unit for the main app
instance and one runtime unit for each workspace of that app.
`doctor --family=process --fix` re-renders missing or divergent runtime units
from gateway-tracked app, workspace, and process configuration.

The rendered Supervisor program name follows the global runtime-unit naming
contract: `orbit_<app>_<workspace|main>_<process>`.

Runtime units have their own runtime environment contract. Runtime-unit
environment is distinct from workspace setup and teardown step environment.
```

Drop the existing `journald` reference from the Processes subsection and the
`systemd` mention from the surrounding paragraphs that describe live runtime
verification — replace `live systemctl probes` with `live runtime backend
probes` and `journald` with `the runtime backend log source`.

- [x] **Step 3: Rewrite the Schedules subsection**

Replace the existing block with:

```markdown
### Schedules

Schedules cover all recurring Orbit-managed work. A Laravel scheduler is a
normal app-scoped schedule that runs `php artisan schedule:run` every minute,
not its own entity.

Examples:

- run `php artisan schedule:run` for an app every minute;
- run a named app command on a recurring interval;
- run Orbit-owned recurring node maintenance.

Schedule intent lives on the gateway. Schedule run history lives on the
gateway. The Orbit Scheduler executes due runs on the target node and reports
run-history events back to the gateway. The `schedule` family owns the
scheduler liveness, due-run lock health, run-history hook material, and run
history; doctor verifies these.
```

- [x] **Step 4: Update the Identity Names runtime-unit block**

Replace:

```text
orbit_<app>_<workspace|main>_<process>.service
```

with:

```text
orbit_<app>_<workspace|main>_<process>
```

Update the surrounding sentence so it reads:

```markdown
Process runtime unit names use the app, workspace, and process slugs.
`orbit_` is the Orbit ownership prefix. `_` is reserved as the backend
segment delimiter and is not allowed in identity slugs. Renderers must
validate the final program name against the runtime backend's name limits
before writing it.
```

- [x] **Step 5: Update the State Families backend examples column**

Change the `process` row's "Current backend examples" from
`systemd, journald` to `Supervisor programs, Supervisor logs`.

Change the `schedule` row's "Current backend examples" from
`systemd timers/services` to `Orbit Scheduler runs, Supervisor program for orbit_scheduler`.

Change the `tool` row's mention of `systemd` to `runtime backend`.

- [x] **Step 6: Amend Explicit Non-Goals**

Replace the line `an Orbit daemon or app-node control plane` with:

```markdown
- a control-plane daemon that mutates fleet intent, exposes inbound RPC, or
  contains independent Orbit business logic outside narrow gateway-client
  workflows. The Orbit Scheduler is the named exception: it is supervised by
  the runtime backend, reads schedule intent from the gateway over the
  existing CLI-to-gateway HTTPS edge, executes due runs locally, and reports
  run history back through the gateway's typed API. It does not accept
  inbound RPC, does not orchestrate other nodes, and does not own fleet
  intent;
```

Replace the line `a generic app-node daemon or RPC channel outside the CLI
client and explicit event hooks` with:

```markdown
- a generic app-node daemon or inbound RPC channel outside the CLI client,
  the Orbit Scheduler, and explicit event hooks;
```

Leave the rest of the non-goals untouched. App-node platform stays Ubuntu.

- [x] **Step 7: Run docs lint**

```bash
composer docs-lint
```

Expected: `{"tool":"docs-lint","result":"passed","issues":0,"errors":0,"warnings":0}`.

- [x] **Step 8: Commit**

```bash
git add docs/BLUEPRINT.md
git commit -m "docs(blueprint): name runtime backend and orbit-scheduler"
```

## Task 2: MISSION.md

**Files:**
- Modify `docs/MISSION.md`

- [x] **Step 1: Update the State families paragraph**

In the "State families — DB as intent, fleet as reality" section, change the
sentence about backend names so it reads:

```markdown
Backend names such as Caddy sites, UFW rules, tool installs, Supervisor
programs, or runtime backend log paths are implementation details or
contraction candidates until folded into the product families.
```

Drop "app schedulers" and "process-event notifier probes" — both are now
named families.

- [x] **Step 2: Update the Full Laravel paragraph**

Replace:

```markdown
No PHAR builds. Updating any machine is `git pull && composer install --no-dev`. Full Laravel (currently 13.x) also gives us scheduler, queues, and a path to a web UI without a framework migration.
```

with:

```markdown
No PHAR builds. Updating any machine is `git pull && composer install --no-dev`.
Full Laravel (currently 13.x) gives us a queue runtime, a web UI path, and the
console scheduler primitives that the Orbit Scheduler builds on.
```

- [x] **Step 3: Update the Production hosting paragraph (optional polish)**

Where the section says "same Caddy runtime," extend it to "same Caddy runtime
and runtime backend" so the runtime backend appears alongside the existing
named host services.

- [x] **Step 4: Run docs lint**

```bash
composer docs-lint
```

- [x] **Step 5: Commit**

```bash
git add docs/MISSION.md
git commit -m "docs(mission): align with runtime backend model"
```

## Task 3: BUILDING-BLOCKS.md

**Files:**
- Modify `docs/BUILDING-BLOCKS.md`

- [x] **Step 1: Update the overview diagram**

Replace the app-node line in the ASCII diagram from:

```text
│ Orbit CLI client, PHP-FPM, Caddy, systemd, Docker, files     │
```

to:

```text
│ Orbit CLI client, PHP-FPM, Caddy, Supervisor, Docker, files  │
```

- [x] **Step 2: Update the App Node host services list**

Replace the bullets:

```markdown
- systemd units and timers for Orbit-managed runtime artifacts;
```

with:

```markdown
- Supervisor as the runtime backend for Orbit-managed runtime units;
- the `orbit_scheduler` Supervisor program running the Orbit Scheduler daemon;
```

- [x] **Step 3: Update the Gateway responsibilities list**

Add to the gateway ownership bullets:

```markdown
- the gateway-local Orbit Scheduler instance that runs Orbit-scoped
  maintenance schedules and gateway-targeted recurring work;
```

- [x] **Step 4: Update the Technology Stack table**

Replace the rows:

```markdown
| Process runtime | systemd services and journald |
| Schedule runtime | systemd timers and services |
```

with:

```markdown
| Host init | systemd on Ubuntu hosts; Docker daemon restart policy in Docker E2E containers (`supervisord` as PID 1, typically under `tini`) |
| Runtime backend | Supervisor (`supervisord`) on every gateway and app node |
| Schedule runtime | `orbit-scheduler` Artisan-command daemon supervised by the runtime backend |
| Runtime logs | Supervisor-managed stdout/stderr log files |
```

- [x] **Step 5: Update the State And Storage paragraph**

Replace:

```markdown
Deployment policy and history belong to apps. Process definitions are app-owned
intent; derived app/workspace systemd units are physical artifacts, not gateway
state rows. Process lifecycle events are durable history, not a separate
process-unit intent table.
```

with:

```markdown
Deployment policy and history belong to apps. Process definitions are app-owned
intent; derived app/workspace runtime units are physical artifacts on the
runtime backend, not gateway state rows. Process lifecycle events are durable
history, not a separate runtime-unit intent table.
```

Replace the line `Backend-shaped names such as Caddy sites, UFW rules, systemd
units, or package manager installs` with `Backend-shaped names such as Caddy
sites, UFW rules, Supervisor programs, or package manager installs`.

- [x] **Step 6: Update the Host Services typical artifacts list**

Replace:

```markdown
- systemd service units derived from app-owned process definitions;
- systemd timer/service pairs for schedules;
```

with:

```markdown
- Supervisor programs derived from app-owned process definitions;
- the `orbit_scheduler` Supervisor program running the Orbit Scheduler daemon;
```

- [x] **Step 6a: Add a Runtime Backend And Scheduler subsection**

Insert this subsection between `## Host Services` and `## Installation Shape`:

````markdown
## Runtime Backend And Scheduler

Supervisor (`supervisord`) is the runtime backend on every gateway and app
node. It supervises Orbit-managed long-running processes — one Supervisor
program per runtime unit — and the `orbit_scheduler` program that runs the
Orbit Scheduler daemon. Host init keeps Supervisor itself alive: the distro
`supervisor.service` unit on Ubuntu, or the Docker daemon's container
restart policy in Docker E2E topologies (`supervisord` runs as PID 1
inside the container, typically under `tini`).

The Orbit Scheduler is a long-running PHP process invoked as
`php artisan orbit:scheduler:run`. It runs an internal loop that aligns to
wall-clock minute boundaries, performs one evaluation tick, and sleeps
until the next boundary:

```text
loop:
  sleep until the next wall-clock minute boundary
  perform one tick   // shared logic with `orbit schedule:run`
  goto loop
```

The tick interval is an implementation detail. It may be tightened (for
example to evaluate at most every ten seconds) without changing the
schedule expression contract, which remains minute-resolution. Sub-minute
work belongs in a runtime unit, not in a schedule expression.

Periodic execution comes from the daemon's internal sleep loop, not from
Supervisor — Supervisor itself does not provide cron-style scheduling. Its
contributions are: keep the PHP process alive, restart it on crash, and
capture stdout/stderr for `process:logs orbit_scheduler`.

The daemon's per-tick logic is shared with the `orbit schedule:run`
command. The daemon is the steady-state path; `schedule:run` is the
on-demand path used for testing, troubleshooting, and recovery.
````

- [x] **Step 7: Run docs lint**

```bash
composer docs-lint
```

- [x] **Step 8: Commit**

```bash
git add docs/BUILDING-BLOCKS.md
git commit -m "docs(building-blocks): runtime backend is Supervisor"
```

## Task 4: CONCEPTS.md

**Files:**
- Modify `docs/CONCEPTS.md`

- [x] **Step 1: Add new entries under Global Concepts**

After the existing `**Adopt**` entry, append:

```markdown
- **Runtime backend** — host-level supervisor that owns Orbit-managed
  long-running processes on a node. Supervisor (`supervisord`) on every
  gateway and app node. See
  [Blueprint: Runtime Backend And Orbit Scheduler](BLUEPRINT.md#runtime-backend-and-orbit-scheduler).
- **Runtime unit** — abstract product noun for an Orbit-managed long-running
  process. Rendered as a Supervisor program by the runtime backend. See
  [Process Concepts](commands/7_process/process-concepts.md).
- **Supervisor program** — backend-specific name for the rendered runtime
  unit. See [Process Concepts](commands/7_process/process-concepts.md).
- **Orbit Scheduler** — resident `orbit-scheduler` Artisan-command daemon
  supervised by the runtime backend on every gateway and app node. Owns
  schedule evaluation, due-run dispatch, overlap policy, run history, and
  heartbeat. See [Schedule Concepts](commands/9_schedule/schedule-concepts.md).
- **Host init** — the host's own service manager that keeps the runtime
  backend alive. systemd on Ubuntu. Not the product-level process runtime.
```

- [x] **Step 2: Update the Process Concepts concept-index block**

Replace the existing list with:

```markdown
- **Process definition**
- **Process identity slug**
- **Process order**
- **Runtime unit**
- **Runtime unit filename**
- **Runtime unit environment**
- **Supervisor program**
- **Restart policy**
- **Crash notification policy**
- **Process event**
- **Crash event**
- **Process-family boundaries**
```

- [x] **Step 3: Update the Schedule Concepts concept-index block**

Replace the existing list with:

```markdown
- **Schedule**
- **Schedule scope**
- **App-scoped schedule**
- **Node-scoped schedule**
- **Orbit-scoped schedule**
- **Laravel scheduler**
- **Execution source**
- **Portable interval expression**
- **Orbit Scheduler**
- **Scheduler heartbeat**
- **Schedule run**
- **Schedule lock**
- **Run-history hook**
- **Schedule-family boundaries**
```

- [x] **Step 4: Run docs lint**

```bash
composer docs-lint
```

Docs-lint enforces concept-index alignment. If it complains that the new
concepts are missing from `process-concepts.md` or `schedule-concepts.md`,
that is expected — those files are updated in Tasks 5 and 6 in the same
worker session.

If using subagent-driven-development, defer Task 4's commit until after
Tasks 5 and 6 close so the concept-index lint check passes against the
updated owning docs. Otherwise stage Task 4 changes and amend after Tasks
5 and 6.

- [x] **Step 5: Commit (after Tasks 5 and 6)**

```bash
git add docs/CONCEPTS.md
git commit -m "docs(concepts): runtime backend, scheduler, runtime unit"
```

## Task 5: Process Command Contracts

**Files:**
- Modify `docs/commands/7_process/README.md`
- Modify `docs/commands/7_process/process-concepts.md`
- Modify `docs/commands/7_process/process-doctor.md`
- Modify `docs/commands/7_process/5_process-start/technical/1_process-start.md`
- Modify `docs/commands/7_process/6_process-stop/technical/1_process-stop.md`
- Modify `docs/commands/7_process/7_process-restart/technical/1_process-restart.md`
- Modify `docs/commands/7_process/8_process-logs/technical/1_process-logs.md`
- Modify JSON renderer docs under `docs/commands/7_process/*/technical/6.2_*.md`

- [x] **Step 1: Replace systemd-specific domain rules in README**

Apply these changes to `docs/commands/7_process/README.md`:

- Change `Runtime units are physical artifacts derived from app, optional workspace, and process intent. They are not the product model.` → keep as is (already concept-first).
- Change `Runtime unit filenames use orbit_<app>_<workspace|main>_<process>.service. The orbit_ prefix marks Orbit ownership; underscores are reserved as backend segment delimiters and are not allowed in identity slugs.` → replace `.service` with no suffix, and add a sentence: `The rendered Supervisor program uses the same name.`
- Change `Logs come from the node runtime backend.` → `Logs come from the runtime backend's stdout/stderr capture for the rendered Supervisor program.`
- Change `Live runtime verification belongs to doctor --family=process` (already concept-first, leave).
- Change `They do not synchronously SSH to app nodes or run live systemctl probes.` → `They do not synchronously SSH to app nodes or run live runtime backend probes.`
- Change `The app-node CLI never writes process intent, reads journald directly, or operates systemd directly.` → `The app-node CLI never writes process intent, reads runtime backend logs directly, or operates the runtime backend directly.`

- [x] **Step 2: Update process-concepts.md Runtime Artifacts section**

Replace the runtime unit filename entry with:

```markdown
- **Runtime unit filename:** `orbit_<app>_<workspace|main>_<process>`. The
  `orbit_` prefix marks Orbit ownership; underscores are reserved as backend
  segment delimiters. The rendered Supervisor program uses the same name.
```

Add an entry after the runtime unit filename entry:

```markdown
- **Supervisor program:** Backend-specific rendering of a runtime unit. The
  program is supervised by the node's runtime backend and starts the process
  command in the resolved app or workspace context.
```

Update the Runtime Artifacts header lead paragraph so the abstract noun is
"runtime unit" and the implementation noun is "Supervisor program."

- [x] **Step 3: Rewrite process-doctor.md probe layers**

Replace the Probe Layers section with:

```markdown
## Probe Layers

The processes probe reads gateway process definitions and checks these layers:

1. **Registry intent:** every selected process definition has a valid app
   reference, process name, command, restart policy, and crash-notification
   policy.
2. **Owning app and workspace expansion:** the owning app resolves to an
   active app record and the expected runtime contexts are the main app
   instance plus every active workspace for that app.
3. **Runtime backend availability:** the node has Supervisor installed, the
   `supervisord` daemon is reachable, and its control socket is responsive.
   When this layer fails, the probe stops and reports
   `process.runtime_backend_unavailable` instead of cascading downstream
   layer failures.
4. **Runtime-unit identity:** each expected runtime context maps to exactly
   one Orbit-owned runtime unit name using
   `orbit_<app>_<workspace|main>_<process>`.
5. **Supervisor program presence:** each expected runtime unit exists as a
   Supervisor program when the runtime backend is reachable.
6. **Supervisor program shape:** rendered command, working directory,
   restart policy, user, and runtime environment match gateway intent.
7. **Lifecycle notifier material:** Orbit-managed crash event hooks, gateway
   endpoint material, and gateway CA material required to write durable
   `crashed` lifecycle events exist and match the selected process
   definitions.
8. **Stale runtime units:** Orbit-owned Supervisor programs whose encoded
   app, workspace, or process identity no longer maps to active gateway
   intent are reported as process-family drift.
```

- [x] **Step 4: Update process issue codes table**

Replace the existing table with:

```markdown
| Code | Detected when |
| --- | --- |
| `process.record_incomplete` | A selected process definition lacks app, name, command, restart policy, or crash-notification policy. |
| `process.owner_app_invalid` | The process definition points at a missing app, unauthorized app, or app whose owning node is not an active app node. |
| `process.runtime_context_unresolved` | The expected main app or workspace runtime context cannot be derived from gateway intent. |
| `process.runtime_backend_unavailable` | Supervisor is not installed, `supervisord` is not running, or its control socket is not reachable. Downstream runtime-unit checks are skipped while this code is active. |
| `process.runtime_unit_missing` | An expected Orbit-owned runtime unit has no corresponding Supervisor program. |
| `process.runtime_unit_extra` | An Orbit-owned Supervisor program exists without matching active app, workspace, and process intent. |
| `process.runtime_unit_mismatch` | The Supervisor program command, working directory, user, or program name differs from gateway process intent. |
| `process.restart_policy_mismatch` | The rendered Supervisor restart policy differs from the process definition. |
| `process.runtime_environment_mismatch` | The rendered Supervisor program environment differs from the runtime unit environment contract. |
| `process.event_notifier_missing` | Runtime lifecycle event notifier material is absent for a runtime unit that should emit crash events. |
| `process.event_notifier_mismatch` | Runtime lifecycle event notifier material exists but points at the wrong gateway endpoint, app, workspace, process, or event intake identity. |
```

- [x] **Step 5: Update process fix map**

Replace with:

```markdown
| Code | `--fix` behavior |
| --- | --- |
| `process.runtime_backend_unavailable` | No `--fix` action. Runtime backend installation and recovery belong to `tool` family doctor and node operations. Process doctor reports the dependency and does not attempt to install Supervisor. |
| `process.runtime_unit_missing` | Re-render and reload the missing Supervisor program from gateway app, workspace, and process intent. |
| `process.runtime_unit_extra` | Stop and remove the stale Orbit-owned Supervisor program whose identity no longer maps to active gateway app, workspace, and process intent. |
| `process.runtime_unit_mismatch` | Rewrite the Supervisor program from gateway app, workspace, and process intent. |
| `process.restart_policy_mismatch` | Rewrite the Supervisor restart policy from the process definition. |
| `process.runtime_environment_mismatch` | Rewrite the Supervisor program environment from the runtime unit environment contract. |
| `process.event_notifier_missing` | Reinstall Orbit-managed lifecycle event notifier material for the selected runtime unit. |
| `process.event_notifier_mismatch` | Rewrite lifecycle event notifier material to match gateway intent and the current gateway event intake identity. |
```

`--fix` does not handle `process.record_incomplete`,
`process.owner_app_invalid`, `process.runtime_context_unresolved`, or
`process.runtime_backend_unavailable`.

- [x] **Step 6: Update process adopt map**

Add `process.runtime_backend_unavailable | No adoption action.` Leave the
rest of the adopt-map rows unchanged in shape; replace any "runtime-unit
artifact" wording with "Supervisor program."

- [x] **Step 7: Update test mapping rows**

Replace the existing E2E test mapping rows with:

```markdown
| `tests/E2E/Read/ProcessesDoctorTest.php` | Real read-only `doctor --family=process --json` on a topology with Supervisor-rendered process runtime units. Docker-eligible. |
| `tests/E2E/Ephemeral/ProcessesDoctorFixTest.php` | Real `doctor --family=process --fix` repair of missing or divergent Supervisor programs and lifecycle event notifier material. Docker-eligible. |
```

Add a row:

```markdown
| `tests/Feature/Doctor/ProcessesFamilyDoctorContractTest.php` | Asserts `process.runtime_backend_unavailable` short-circuits downstream layer checks and that all issue codes above are emitted by their seeded conditions. |
```

- [x] **Step 8: Update per-command technical contracts**

For `5_process-start`, `6_process-stop`, `7_process-restart`, and
`8_process-logs` technical files, apply the same vocabulary swap:

- `systemd reported a start failure` → `the runtime backend reported a start failure`
- `systemctl ...` examples → drop or rewrite as `supervisorctl ...` only when the
  example is explicitly backend-specific evidence; otherwise remove the example.
- `journald` references → `Supervisor stdout/stderr log files` for `process:logs`
  source language; remove from non-logs commands entirely.
- `.service` filename references → drop the suffix.

- [x] **Step 9: Update process JSON renderer docs**

Add or update the JSON entity examples under
`docs/commands/7_process/*/technical/6.2_*.md` so any field that exposes the
backend-rendered name uses the no-suffix shape:

```json
{
  "runtime_unit": "orbit_docs_main_vite",
  "supervisor_program": "orbit_docs_main_vite"
}
```

Drop any field named `service_name` or `unit_name`. Where the renderer
documented `unit_name`, replace it with `runtime_unit`.

- [x] **Step 10: Run docs lint**

```bash
composer docs-lint
```

- [x] **Step 11: Commit**

```bash
git add docs/commands/7_process
git commit -m "docs(process): runtime unit and supervisor program contract"
```

## Task 6: Schedule Command Contracts

**Files:**
- Modify `docs/commands/9_schedule/README.md`
- Modify `docs/commands/9_schedule/schedule-concepts.md`
- Modify `docs/commands/9_schedule/schedule-doctor.md`
- Modify technical contracts under `docs/commands/9_schedule/*/technical/*.md`

- [x] **Step 1: Replace the systemd-timers preamble in README**

Replace:

```markdown
Systemd timers and services are the current backend on supported Ubuntu nodes.
They are not the product model.
```

with:

```markdown
The Orbit Scheduler is the schedule executor. It is a resident
`orbit-scheduler` Artisan-command daemon supervised by the runtime backend on
every gateway and app node. Schedule intent and durable run history live on
the gateway; the scheduler reads intent, dispatches due runs locally, and
reports run history back over the existing CLI-to-gateway HTTPS edge.

The scheduler evaluates due schedules at least once per minute, aligned to
wall-clock minute boundaries. Each tick fetches the node's schedule list from
the gateway, evaluates which schedules are due in the current minute, and
fires them. Schedule expressions remain minute-resolution; the tick interval
is an implementation detail. `orbit schedule:run` performs one such tick on
demand and shares its evaluation logic with the daemon.
```

- [x] **Step 2: Update schedule domain rules**

Replace `Schedule write commands mutate gateway intent first, then enact
timer and service artifacts on the target node through the gateway.` with:

```markdown
Schedule write commands mutate gateway intent first. The Orbit Scheduler on
the target node observes the change on its next sync, claims due runs with a
local schedule lock, and executes them. There is no per-schedule node-side
artifact to enact; the only enacted artifact is the `orbit_scheduler`
Supervisor program, which is enacted once per node by node provisioning.
```

Replace `Schedule reads use gateway intent and durable run history by
default. Live timer/service reality belongs to doctor --family=schedule.`
with:

```markdown
Schedule reads use gateway intent and durable run history by default. Live
scheduler reality belongs to `doctor --family=schedule`.
```

Replace `Backend discovery/import is not part of the schedule command
surface. Adoption of observed schedule artifacts must use explicit
doctor --family=schedule --adopt semantics.` with:

```markdown
The schedule family does not adopt arbitrary observed processes as
schedules. Adoption is reserved for explicitly selected runs reported by the
Orbit Scheduler that match an existing or operator-supplied schedule shape.
```

- [x] **Step 3: Update schedule-concepts.md**

Add concepts:

```markdown
- **Orbit Scheduler:** Resident `orbit-scheduler` Artisan-command daemon
  supervised by the runtime backend on every gateway and app node. Owns
  schedule evaluation, due-run dispatch, overlap policy, run history, and
  heartbeat for schedules whose target resolves to the local node.
- **Scheduler heartbeat:** Periodic local state the Orbit Scheduler writes
  so doctor can verify liveness without a long-lived inbound channel.
- **Schedule run:** One execution of a schedule. Recorded as durable
  gateway history with `started_at`, `finished_at`, `exit_code`, and
  `status` (`completed`, `failed`, `skipped`, `missed`).
- **Schedule lock:** Local node lock that prevents overlapping runs of the
  same schedule. Lock state lives in the node's local Orbit SQLite, not in
  gateway intent.
- **Run-history hook:** Orbit-managed material the scheduler uses to
  capture stdout/exit-status from a schedule run before reporting it to the
  gateway run-history intake.
```

- [x] **Step 4: Update Schedule JSON Entity**

Append fields to the canonical schedule entity (and document them in the
fields table):

```json
{
  "name": "laravel-scheduler",
  "scope": "app",
  "target": {
    "type": "app",
    "name": "docs",
    "node": "app-1"
  },
  "interval": "every minute",
  "timezone": "Europe/Amsterdam",
  "execution": {
    "type": "command",
    "value": "php artisan schedule:run"
  },
  "enabled": true,
  "status": "expected",
  "scheduler": {
    "node": "app-1",
    "heartbeat_at": "2026-05-02T08:00:01Z",
    "registry_synced_at": "2026-05-02T07:59:50Z"
  },
  "last_run": {
    "id": 12,
    "status": "completed",
    "exit_code": 0,
    "started_at": "2026-05-02T08:00:00Z",
    "finished_at": "2026-05-02T08:00:03Z"
  }
}
```

Add the corresponding rows to the fields table:

```markdown
| `scheduler.node` | string | Node where the Orbit Scheduler responsible for this schedule runs. |
| `scheduler.heartbeat_at` | string \| null | ISO-8601 of the most recent scheduler heartbeat reported to the gateway. `null` until the first heartbeat is recorded. |
| `scheduler.registry_synced_at` | string \| null | ISO-8601 of the most recent schedule-intent sync the scheduler completed. `null` until the first sync is recorded. |
```

`status` keeps its current meaning: gateway-intent status, not live
verification.

- [x] **Step 5: Rewrite schedule-doctor.md probe layers**

Replace with:

```markdown
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
10. **Run-history hook material:** scheduler-side hook material required to
    capture stdout/exit-status for the selected schedules exists and matches
    gateway intent.
```

- [x] **Step 6: Update schedule issue codes**

Replace the existing table with:

```markdown
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
```

- [x] **Step 7: Update schedule fix map**

Replace with:

```markdown
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
```

`--fix` does not handle `schedule.record_incomplete`,
`schedule.target_invalid`, `schedule.runtime_backend_unavailable`,
`schedule.heartbeat_stale`, or `schedule.registry_sync_stale`.

- [x] **Step 8: Update schedule adopt map**

Replace with:

```markdown
| Code | `--adopt` behavior |
| --- | --- |
| (no codes adopt by default) | Schedules are gateway intent. There is no observed-artifact-as-intent path. Adoption candidates that an operator wants to materialize as schedules must use `schedule:add` directly. |
```

`--adopt` does not scan arbitrary hosts or import scheduler-local state into
gateway schedule intent.

- [x] **Step 9: Update test mapping rows**

Replace the existing E2E test mapping rows with:

```markdown
| `tests/E2E/Read/ScheduleDoctorTest.php` | Real read-only `doctor --family=schedule --json` against a topology with the Orbit Scheduler running. Docker-eligible. |
| `tests/E2E/Ephemeral/ScheduleDoctorFixTest.php` | Real `doctor --family=schedule --fix` repair for `scheduler_missing`, `scheduler_stopped`, `lock_stuck`, and `run_history_hook_*` codes. Docker-eligible. |
```

Drop the existing `ScheduleDoctorAdoptTest.php` row; the schedule family no
longer documents adoption.

Add a row:

```markdown
| `tests/Feature/Doctor/ScheduleFamilyDoctorContractTest.php` | Asserts every issue code above, fix-map behavior, denied adopt cases, and that `runtime_backend_unavailable` short-circuits downstream layers. |
```

- [x] **Step 10: Update technical contracts under `*/technical/*.md`**

Vocabulary swap across all schedule technical contracts:

- `timer/service` → `schedule run`
- `systemd timer` / `systemd service` → drop entirely or replace with `Orbit Scheduler` where the sentence is about execution
- `journald` → drop; replace with `scheduler-captured stdout/stderr` for `schedule:logs` source language
- `--service` / `--timer` flags or column names → remove or rename to `--run` / `--schedule`

- [x] **Step 10a: Update `schedule:run` to document the shared tick logic**

In `docs/commands/9_schedule/5_schedule-run/technical/1_schedule-run.md`, add
a paragraph in the behavior section:

```markdown
`schedule:run` performs one Orbit Scheduler tick on the resolved target node:
fetch the node's schedule list from the gateway, evaluate which schedules are
due in the current minute, and fire them. The same logic runs inside the
resident `orbit-scheduler` daemon at least once per minute. Operators use
`schedule:run` to fire a tick on demand for testing, troubleshooting, or
recovery; the daemon's loop is the steady-state path.

When called with a schedule name, `schedule:run [name]` force-runs that one
schedule regardless of its interval and records the resulting run.
```

Confirm the existing `schedule:run` JSON renderer documents the run
identifier, status, exit code, and timestamps. If it currently documents
"trigger and forget" semantics, replace with the run-completed shape
described above.

- [x] **Step 11: Run docs lint**

```bash
composer docs-lint
```

- [x] **Step 12: Commit**

```bash
git add docs/commands/9_schedule
git commit -m "docs(schedule): orbit scheduler and runtime backend contract"
```

## Task 7: Workspace Contract Updates

**Files:**
- Modify `docs/commands/6_workspace/README.md`
- Modify `docs/commands/6_workspace/workspace-concepts.md`
- Modify technical contracts for `workspace:new`, `workspace:setup`,
  `workspace:remove`, `workspace:show`, `workspace:history`, and
  `workspace:log`

- [x] **Step 1: Update inherited runtime unit language in README**

Replace the bullet `Workspaces inherit app process definitions as runtime
artifacts. Inherited process-unit convergence belongs to the process
family.` with:

```markdown
- Workspaces inherit app process definitions as runtime units. Each
  inherited runtime unit is rendered by the runtime backend as a Supervisor
  program owned by the workspace. Runtime unit convergence belongs to the
  process family.
```

- [x] **Step 2: Classify which workspace flows need a live runtime backend**

Add this paragraph beneath the Domain Rules list:

```markdown
Workspace registry-only reads — `workspace:show`, `workspace:history`,
`workspace:list`, and `workspace:log` for stored history — do not require a
live runtime backend. `workspace:new`, `workspace:setup`, and
`workspace:remove` require a live runtime backend on the owning app node
when they create, update, remove, or verify inherited runtime units.
```

- [x] **Step 3: Update technical contract test mapping rows**

For each affected workspace technical contract, replace any test mapping row
that names systemd with:

```markdown
| `tests/E2E/...` | Real backend coverage for inherited workspace runtime units rendered as Supervisor programs. Docker-eligible. |
```

- [x] **Step 4: Update workspace-concepts.md if it references runtime units**

Where the file uses `runtime artifact`, replace with `runtime unit`. Where
it references systemd, drop the implementation name and use `runtime
backend` or `Supervisor program` per the abstract/concrete distinction.

- [x] **Step 5: Run docs lint**

```bash
composer docs-lint
```

- [x] **Step 6: Commit**

```bash
git add docs/commands/6_workspace
git commit -m "docs(workspace): inherited runtime units use runtime backend"
```

## Task 8: Testing Boundary Docs

**Files:**
- Modify `TESTING.md`

- [x] **Step 1: Update the Docker Feature Topologies paragraph**

Replace the sentence:

```markdown
In practice, Docker is a good candidate for read-style feature tests such as
registry-backed `node:*` reads and gateway API backed commands after the
topology has been seeded. Incus is required for `process:*` commands, schedule
runtime assertions, and `workspace:*` flows that create, inspect, start, stop,
restart, or validate systemd-backed runtime units. Registry-only workspace
views can run on Docker only when they do not inspect live process state,
execute setup/teardown steps, or assert runtime unit convergence.
```

with:

```markdown
Docker is a valid lane for `process:*`, `schedule:*`, and `workspace:*`
runtime assertions because the runtime backend (Supervisor) and the Orbit
Scheduler run identically inside Docker containers. Incus remains required
for tests that depend on real VM behavior: cloud-init, package
installation, real SSH daemon behavior, sudo prompts, OS trust-store
mutation, real WireGuard interfaces and peer routing, and host init
itself.
```

- [x] **Step 2: Add a provider capability matrix**

Add this table immediately above the "E2E Lanes" subsection:

```markdown
## Provider Capability Matrix

| Capability | Docker | Incus |
| --- | --- | --- |
| Gateway API and CA trust | yes | yes |
| Registry-backed command behavior | yes | yes |
| Supervisor runtime backend | yes | yes |
| Orbit Scheduler daemon | yes | yes |
| Real WireGuard interfaces and peer routing | no | yes |
| VM boot, cloud-init, package install mutation | no | yes |
| Real SSH daemon and sudo behavior | no | yes |
| OS trust-store mutation | no | yes |
| Host init (systemd) on the node itself | no | yes |
```

- [x] **Step 3: Define E2E selection rules**

Append a short subsection beneath the matrix:

```markdown
### Provider Selection Rules

Use Docker for feature tests whose correctness depends on gateway API, CA
trust, registry state, the runtime backend, the Orbit Scheduler, or
Orbit-managed process and schedule lifecycle.

Use Incus for tests whose correctness depends on real VM behavior: WireGuard
kernel networking, cloud-init, package installation, OS trust-store
mutation, real SSH daemon behavior, sudo prompts, or host init.

Provisioning, installer, and host-mutation tests stay in the
`e2e-provision` lane on Incus regardless of family.
```

- [x] **Step 4: Commit**

`TESTING.md` is not covered by `composer docs-lint`. Skip the lint step and
commit:

```bash
git add TESTING.md
git commit -m "docs(testing): runtime backend provider capability matrix"
```

## Task 9: PORTING.md

**Files:**
- Modify `docs/PORTING.md`

- [x] **Step 1: Update the Process Workstream**

Replace the existing Process Workstream block with:

```markdown
## Process Workstream

- [x] Convert process command docs into current format.
- [x] Reshape process docs around runtime backend (Supervisor) and runtime
  unit vocabulary. See
  [`2026-05-05-supervisor-runtime-backend-plan.md`](2026-05-05-supervisor-runtime-backend-plan.md).
- [x] Port process schema and models.
- [x] Port process add/edit/remove/list commands.
- [x] Port process start/stop/restart commands against Supervisor.
- [x] Port process log command against Supervisor stdout/stderr capture.
- [x] Port process exit hook support.
```

- [x] **Step 2: Update the Workspace Workstream**

Add a `[x]` line after the existing convert-docs entry:

```markdown
- [x] Reshape workspace docs to reference inherited runtime units instead of
  systemd units.
```

- [x] **Step 3: Add a Schedule Workstream section**

Place between Process Workstream and Gateway API Client And Transport
Workstream:

```markdown
## Schedule Workstream

- [x] Convert schedule command docs into current format.
- [x] Reshape schedule docs around the Orbit Scheduler resident daemon. See
  [`2026-05-05-supervisor-runtime-backend-plan.md`](2026-05-05-supervisor-runtime-backend-plan.md).
- [x] Port schedule schema, models, and run-history table.
- [x] Port schedule add/list/show/remove commands.
- [x] Port schedule run command (manual fire).
- [x] Port schedule logs command against scheduler-captured stdout/stderr.
- [x] Port `orbit-scheduler` Artisan-command daemon and Supervisor program
  rendering.
- [x] Port scheduler heartbeat reporting and run-history intake endpoint.
- [x] Port schedule doctor probe and fix map.
```

- [x] **Step 4: Add a Runtime Backend And Scheduler Workstream section**

Place between Schedule Workstream and Gateway API Client And Transport
Workstream:

```markdown
## Runtime Backend And Scheduler Workstream

The runtime backend (Supervisor) and the Orbit Scheduler are introduced as
product behavior in the doc reshape and require implementation work
shared across the process and schedule families.

- [x] Document the runtime backend (Supervisor) and Orbit Scheduler in
  blueprint, mission, building blocks, concepts, process docs, schedule
  docs, and workspace docs. See
  [`2026-05-05-supervisor-runtime-backend-plan.md`](2026-05-05-supervisor-runtime-backend-plan.md).
- [x] Add Supervisor installation to gateway and app node provisioning.
- [x] Add the runtime backend reachability probe shared by process and
  schedule doctor.
- [x] Add the Supervisor program renderer shared by process and schedule
  enactment.
- [x] Add the `orbit-scheduler` Artisan-command daemon.
- [x] Add scheduler local-state schema (locks, heartbeat, last-sync).
- [x] Add scheduler-to-gateway authentication using the existing WireGuard
  node identity.
- [x] Add the gateway run-history intake endpoint and typed request.
- [x] Docker E2E base image runs `supervisord -n` as PID 1 (under `tini`)
  and ships pre-installed Supervisor and `orbit_scheduler` program files.
- [x] Add Docker E2E coverage for runtime backend behavior and scheduler
  liveness.
- [x] Add Incus E2E coverage where host init or VM-only behavior is part of
  the assertion.
```

- [x] **Step 5: Drop stale Docker scoping notes**

In the Todo Pipeline Hints section, update the line that says
`Docker remains the likely long-term default for fast feature regression
because container topology reset is cheaper. Incus remains the VM-realism
lane for systemd, SSH, users, package installation, WireGuard, trust-store,
and VPS-adjacent behavior.` to:

```markdown
Docker remains the likely long-term default for fast feature regression
because container topology reset is cheaper and the runtime backend +
Orbit Scheduler run identically inside containers. Incus remains the
VM-realism lane for host init, real SSH, sudo, package installation,
real WireGuard, trust-store mutation, and VPS-adjacent behavior.
```

- [x] **Step 6: Commit**

`PORTING.md` is not covered by `composer docs-lint`. Skip the lint step and
commit:

```bash
git add docs/PORTING.md
git commit -m "docs(porting): add runtime backend and scheduler workstream"
```

## Task 10: Follow-Up Implementation Plan

**Files:**
- Create `docs/2026-05-05-supervisor-runtime-implementation-plan.md`

- [ ] **Step 1: Create the implementation plan only after Tasks 1-9 are merged**

The implementation plan must be based on the updated command contracts. Do
not start it from current systemd-specific code assumptions.

- [ ] **Step 2: Include these implementation areas**

```markdown
- Supervisor installation, version probing, and config layout.
- Runtime backend reachability probe service, shared by process and
  schedule doctor.
- Supervisor program renderer (program file, env block, log paths,
  restart policy mapping).
- `orbit-scheduler` Artisan-command daemon: main loop, schedule sync,
  due-evaluation, lock management, executor, heartbeat writer, run-history
  reporter.
- Scheduler local-state schema: schedule_locks, scheduler_heartbeat,
  scheduler_sync_state.
- Scheduler-to-gateway authentication via the WireGuard node identity used
  by the existing CLI client.
- Gateway run-history intake endpoint and typed request.
- Backend-aware process doctor.
- Backend-aware schedule doctor.
- Docker E2E topology with Supervisor and the Orbit Scheduler enabled.
- Incus E2E coverage for host init and VM-only behavior.
```

- [ ] **Step 3: Defer test harness changes until docs are stable**

Do not rename or expand E2E lanes before this plan's docs land.

## Final Gate

The plan is not considered successful until this gate passes. Run after Tasks
1-9 are committed.

- [x] **Step 1: Run docs lint as the final pass/fail check**

```bash
composer docs-lint
```

Required result: `{"tool":"docs-lint","result":"passed","issues":0,"errors":0,"warnings":0}`.

A non-zero exit, any `errors`, or any `warnings` block plan completion. Fix
the reported issue in the owning doc, recommit on the relevant task, and
re-run the gate. Do not bypass with skip flags, do not silence rules, and do
not mark the gate green on partial passes.

- [x] **Step 2: Verify Self-Review Checklist is fully checked**

Every item in the Self-Review Checklist below must be ticked. Any unchecked
item blocks plan completion.

- [x] **Step 3: Verify no task checkboxes remain `[ ]`**

Search the plan for any remaining `- [ ]` task or step lines. The plan may
only complete with every executable task and step checked. Task 10 (follow-up
implementation plan) is the documented exception: its checkboxes stay open
because that work is intentionally deferred to a separate plan file.

## Self-Review Checklist

- [x] Product docs no longer treat systemd as the process or schedule
      product runtime.
- [x] Supervisor is named as the runtime backend; the `orbit_scheduler`
      Supervisor program is named as the executor.
- [x] `runtime unit` is the canonical abstract noun. `runtime artifact` is
      not used.
- [x] App-nodes-are-Ubuntu remains intact in BLUEPRINT, MISSION, and
      BUILDING-BLOCKS.
- [x] BLUEPRINT Explicit Non-Goals are amended explicitly to permit the
      Orbit Scheduler as a gateway-client daemon, not silently dropped.
- [x] Every new doctor issue code has `--fix` and `--adopt` behavior in the
      same task that introduces the code.
- [x] Doctor precedence: `*.runtime_backend_unavailable` short-circuits
      downstream runtime-unit and scheduler-layer checks.
- [x] JSON renderer field changes are field-specified (process
      `runtime_unit`/`supervisor_program`; schedule
      `scheduler.{node,heartbeat_at,registry_synced_at}`).
- [x] Orbit Scheduler authentication uses the existing WireGuard node
      identity; no new transport, no inbound RPC.
- [x] Concept-index blocks in `CONCEPTS.md` align with the updated owning
      family docs and `composer docs-lint` passes.
- [x] `composer docs-lint` passes after Tasks 1-7 and again as the Final
      Gate after Task 9. Tasks 8 and 9 modify files outside the lint scope
      and must not regress lint.
- [x] `PORTING.md` reflects the doc reshape as `[x]` and lists the
      Runtime Backend And Scheduler Workstream as `[ ]` implementation
      work.
