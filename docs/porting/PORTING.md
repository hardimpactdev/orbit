# Orbit Porting Tracker

This file is the canonical entry point for command porting progress. It tracks
the clean rebuild work still needed to recreate useful Orbit behavior from
`../orbit-old-may`.

Implementation-pattern guidance for command porting lives in
`docs/abstractions/`. Before porting a command, workers read
`docs/abstractions/cross-cutting.md` plus the matching family file.

## Layout

- `docs/porting/PORTING.md` (this file) — high-level status tracker.
- `docs/porting/<n>_<family>.md` — per-family workstream detail (one file per
  command family, mirroring `docs/commands/`).
- `docs/porting/<topic>.md` — cross-cutting workstreams (activity logging,
  runtime backend, gateway API transport, doctor pattern, testing
  infrastructure, documentation pipeline).

## Rules

- Current `docs/` are product authority.
- `docs/abstractions/` is implementation guidance, not product authority. When
  abstraction guidance and command docs conflict, update the abstraction docs or
  ask for direction; do not override the command contract.
- `../orbit-old-may` is implementation evidence, not product authority.
- Old features should be treated as reference material, not a mandate to copy
  their structure. Before porting behavior, verify whether the clean rebuild can
  implement it more simply, safely, or directly against the current contracts.
- Before the first implementation todo for a family is promoted to
  `worker-ready`, `docs/abstractions/<n>_<family>.md` must exist.
- Command-port implementer todos must read
  `docs/abstractions/cross-cutting.md` and the relevant family abstraction file
  before code edits. If the family abstraction file is missing, the worker marks
  the todo `needs-direction` instead of inventing patterns.
- When all read commands in a family are ported, or when a deliberate subset
  proves the implementation shape, add a concrete family-review candidate under
  [`documentation-porting.md`](documentation-porting.md). The pipeline filler
  turns that entry into a normal worker todo tagged `family-review`.
- The next family's abstraction seed may be authored while the previous family
  review is open. The next family's implementation todos must not be promoted
  to `worker-ready` until the previous `family-review` todo is merged or
  explicitly deferred here with a reason.
- If we decide to keep a feature or command that exists only in
  `../orbit-old-may/docs`, port its documentation into this repo before
  implementing it.
- Legacy command docs must be converted into the current command-doc format
  before the command is built here.
- Every migrated implementation slice must cite the current docs it implements
  and the old code it used as evidence.
- Standing live infrastructure is not a test lane. Do not use persistent
  gateway, control, or app nodes as verification targets.
- In-memory Pest tests own deterministic command, service, database, renderer,
  and contract coverage.
- Provisioning, destructive, host-mutation, live transport, and repair/adoption
  flows require the `e2e-provision` lane before they can be treated as fully
  verified.
- Every newly-ported command requires focused in-memory Pest coverage and a
  committed E2E test or an explicit `lane=none` reason before its workstream
  entry can flip to `[x]`. A Solo `E2E-*` gate todo is coordination
  bookkeeping; it is not verification evidence by itself.
- The matching workstream entry must name the E2E test file and the exact
  `composer test:e2e`, `composer test:e2e:provision`, or `php artisan e2e:*`
  command/filter that passed. `composer quality-check` is not enough because it
  runs in-memory Pest and excludes E2E.
- `lane=none` is allowed only when the command is docs-only, a pure refactor,
  or has no observable runtime behavior outside Pest. Record the reason in the
  workstream entry.

## Status Legend

- `[ ]` Not started
- `[~]` In progress
- `[x]` Ported and tested
- `[!]` Blocked or decision needed
- `[-]` Intentionally not ported

Use `[x]` only when the current implementation satisfies the current product
docs for that item and has focused tests for the documented contract. Useful
bootstrap slices that do not yet satisfy the full current contract stay `[~]`.

## Porting Workflow

1. Find the old documentation in `../orbit-old-may/docs`.
2. Port or convert that documentation into this repo first.
3. For command docs, use the converted family directory/split-file shape and
   run the command-designer semantic check for each ported command before
   marking the command or family done.
4. Run `composer docs-lint` when command docs changed.
5. Before implementation, read `docs/abstractions/cross-cutting.md` and the
   relevant `docs/abstractions/<n>_<family>.md` file.
6. Inspect the old implementation in `../orbit-old-may/app`,
   `../orbit-old-may/config`, `../orbit-old-may/database`, and
   `../orbit-old-may/tests`.
7. Decide whether the old implementation should be ported directly or replaced
   with a simpler clean-rebuild approach that better fits the current docs.
8. Respect the implementation order below unless a verification-helper command
   unlocks better testing for the next slice.
9. Implement the smallest useful vertical slice in the clean repo.
10. Add focused Pest tests that assert the current docs contract, not legacy
    internals.
11. Run the narrow in-memory Pest test, then `composer quality-check`.
12. Add or update the paired E2E test under `tests/E2E/` unless the slice has a
    documented `lane=none` reason.
13. Run the paired E2E command/filter and record the exact passing command in
    the matching workstream entry. For ordinary feature ports this is usually
    `composer test:e2e -- --filter='<CommandScenario>'`; provisioning,
    destructive, host-mutation, live transport, and repair/adoption flows use
    `composer test:e2e:provision -- --filter='<Scenario>'`.
14. Update the matching workstream file under `docs/porting/` and the
    Command Port Status below in the same commit as the ported slice.

## Implementation Order

Default migration order is command-contract and capability driven:

1. **Foundation and verification harness.**
   - Keep `composer quality-check` green for in-memory Pest coverage.
   - Expand the Incus-backed ephemeral E2E harness before provisioning or
     destructive flows depend on it.
    - Use blank Incus VM snapshots for the `e2e-provision` lane and ready
      Incus VM snapshots for the `e2e-feature` lane.
   - Convert docs for the next implementation slice before writing code.
   - Create or refresh the matching `docs/abstractions/<n>_<family>.md` before
     the first implementation todo for a family is promoted to `worker-ready`.
2. **Nodes first.**
   - Finish node registry read and metadata commands before app/workspace
     commands depend on them.
   - Complete access-policy and identity foundations: caller role, local node
     identity, grants, gateway API reachability, and `/api/me`.
   - Port node provisioning only after ephemeral E2E is ready enough to verify
     host mutation safely.
3. **Verification-helper commands may move earlier when they improve testing.**
   - `profile` is an early candidate once node identity and minimal app
     resolution exist, because it helps validate app routing, TLS, and runtime
     behavior while later app/workspace work is being ported.
   - `doctor` family docs and read-only checks may also move earlier when they
     expose drift needed to verify node or app slices.
   - These commands still follow docs-first conversion and must not jump ahead
     of their prerequisites.
4. **Apps after nodes.**
   - Port app schema, API transport, read commands, and app creation/removal
     once node selection and access semantics are reliable.
5. **Workspaces after apps.**
   - Port workspace commands after app ownership, paths, URLs, and runtime
     routing are available.
6. **Processes after workspaces.**
   - Port process commands after the app/workspace execution context is stable.
7. **State families and doctor integration.**
   - Port each family when its owning command domain needs intent/reality
     convergence.
8. **Tools, schedules, proxy/firewall, deployments, Cloudflare, VPN, PHP, and
   agent IDE commands.**
   - Port these after their required node/app/workspace/process foundations
     exist, unless one command is needed earlier as a verification helper.

## Command Port Status

Family-level status mirrors `docs/commands/`. Per-command checklists, family
doctor coverage, and decision history live in the linked detail file.

The family marker reflects commands and doctor coverage together — a family
flips to `[x]` only when every documented command and the family doctor are
ported, with the activity backfill in place.

- [~] [`1_node`](1_node.md)
- [~] [`2_gateway`](2_gateway.md)
- [~] [`3_tool`](3_tool.md)
- [~] [`4_firewall`](4_firewall.md)
- [~] [`5_app`](5_app.md)
- [~] [`6_workspace`](6_workspace.md)
- [x] [`7_process`](7_process.md)
- [~] [`8_proxy`](8_proxy.md)
- [x] [`9_schedule`](9_schedule.md)
- [ ] [`10_deploy`](10_deploy.md)
- [~] [`11_operation`](11_operation.md)
- [ ] [`12_cf`](12_cf.md)
- [ ] [`13_vpn`](13_vpn.md)
- [ ] [`14_php`](14_php.md)
- [~] [`15_agent-ide`](15_agent-ide.md)
- [x] [`16_dns`](16_dns.md)
- [x] [`17_activity`](17_activity.md)

## Cross-cutting workstreams

These workstreams cut across families. Each lives in its own file:

- [`activity-logging.md`](activity-logging.md) — Loggable doctrine, gateway
  correlation, per-family contract backfill.
- [`runtime-backend-scheduler.md`](runtime-backend-scheduler.md) — Supervisor
  runtime backend and the Orbit Scheduler daemon.
- [`gateway-api-transport.md`](gateway-api-transport.md) — Saloon migration,
  per-family typed requests/DTOs, doctor API transport.
- [`state-families-doctor.md`](state-families-doctor.md) — family inventory,
  cross-family doctor status, enactor/probe/doctor pattern.
- [`testing-infrastructure.md`](testing-infrastructure.md) — Incus and Docker
  E2E lanes, base-image/topology preparation, host installer foundation.
- [`documentation-porting.md`](documentation-porting.md) — doc conversion
  workflow, todo pipeline hints, hard blocks.

## Foundation

- [x] Incus-backed ephemeral E2E harness — see
  [`testing-infrastructure.md`](testing-infrastructure.md).
- [x] Orbit host installer (`bin/install-orbit`) — see
  [`testing-infrastructure.md`](testing-infrastructure.md).
- [x] Command docs linter (`tool/docs-linter`, `composer docs-lint`) — exits 0
  with warnings for known command-doc complexity debt.
