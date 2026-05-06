# Orbit Porting Tracker

Canonical entry point for command porting progress. Tracks the clean rebuild
work still needed to recreate useful Orbit behavior from `../orbit-old-may`.
Current `docs/` are product authority; `docs/abstractions/` is implementation
guidance; the old repo is implementation evidence only.

## Layout

- `PORTING.md` — this file. High-level tracker + rules.
- `<n>_<family>.md` — per-family workstream detail, mirroring `docs/commands/`.
- `<topic>.md` — cross-cutting workstreams (activity logging, runtime backend,
  gateway API transport, doctor pattern, testing infrastructure, documentation
  pipeline).

## Rules

- **Active family.** Drive one family from `[~]` to `[x]` at a time.
  Cross-family work is permitted only when it unblocks the active family —
  record the dependency in the active family file.
- **Abstraction first.** `docs/abstractions/<n>_<family>.md` must exist
  before the first implementation todo for that family is dispatched. If it
  is missing, the worker reports `needs-direction` instead of inventing
  patterns. Implementer todos always read `cross-cutting.md` plus the family
  file before touching code.
- **Family review.** When a family's read commands (or a deliberate subset)
  are ported, add a `family-review` candidate in
  [`documentation-porting.md`](documentation-porting.md). The next family's
  implementation may not start until that review is merged or explicitly
  deferred here.
- **Testing pairing.** Every command and feature ships an in-memory Pest
  test plus an E2E test. The E2E lane is one of:
  - **Docker feature** — `pest()->group('e2e-feature')` + `e2eTopology()`,
    run via `composer test:e2e:docker -- --filter=<Scenario>`. Default lane
    for command-port E2E.
  - **Incus VM-feature** — `e2eVmTopology()` requiring
    `E2ETopologyCapabilities::vm()`, grouped with `e2e-provider-incus`, and
    run via `composer test:e2e:incus -- --filter=<Scenario>`. Use only when
    the test needs real systemd, kernel networking, or host init.
  - **Incus provision** — `pest()->group('e2e-provision')`, run via
    `composer test:e2e:provision -- --filter=<Scenario>`. Use for installer,
    WireGuard enrollment, SSH trust, destructive host mutation.
  - **`lane=none`** — only for docs-only, pure refactor, or no observable
    runtime behavior outside Pest. Record the reason in the workstream entry.
- **Done evidence.** A command flips to `[x]` only after the workstream
  entry cites the test file and the exact `composer test:e2e[…]` command
  that passed. `composer quality-check` excludes E2E and is not enough.
- **No standing infrastructure.** Persistent gateway, control, or app nodes
  are never test targets.

## Status Legend

- `[ ]` Not started · `[~]` In progress · `[x]` Ported and tested
- `[!]` Blocked or decision needed · `[-]` Intentionally not ported

`[x]` requires the implementation to satisfy the current product docs and
have focused tests for the documented contract. Bootstrap slices that don't
yet satisfy the full contract stay `[~]`. A family flips to `[x]` only when
every documented command and the documented family doctor (if any) are
ported and the activity backfill is in place.

## Porting Workflow

1. Convert the family's command and doctor docs into the current format and
   run `composer docs-lint`.
2. Author or refresh `docs/abstractions/<n>_<family>.md` (and
   `cross-cutting.md` if a new pattern emerges).
3. Implement the smallest useful vertical slice; add focused in-memory Pest
   tests that assert the current docs contract, not legacy internals.
4. Add or update the paired E2E test under `tests/E2E/`. Pick the lane per
   the testing-pairing rule above.
5. Run `composer quality-check` and the exact
   `composer test:e2e[:docker|:incus|:provision] -- --filter=<Scenario>`.
6. In the same commit, update the family workstream file plus the Command
   Port Status below; cite the passing E2E command.

## Command Port Status

Family-level status mirrors `docs/commands/`. Per-command checklists,
family doctor coverage, and decision history live in the linked file.

- [x] [`1_node`](1_node.md)
- [x] [`2_gateway`](2_gateway.md)
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

- [`activity-logging.md`](activity-logging.md) — Loggable doctrine, gateway
  correlation, per-family contract backfill.
- [`runtime-backend-scheduler.md`](runtime-backend-scheduler.md) — Supervisor
  runtime backend and the Orbit Scheduler daemon.
- [`gateway-api-transport.md`](gateway-api-transport.md) — Saloon migration,
  per-family typed requests/DTOs, doctor API transport.
- [`state-families-doctor.md`](state-families-doctor.md) — family inventory,
  cross-family doctor status, enactor/probe/doctor pattern.
- [`testing-infrastructure.md`](testing-infrastructure.md) — Docker and Incus
  E2E lanes, base-image/topology preparation, host installer foundation.
- [`documentation-porting.md`](documentation-porting.md) — doc conversion
  workflow, family-review pipeline, sequencing rules.

## Foundation

- [x] Incus + Docker ephemeral E2E harness — see
  [`testing-infrastructure.md`](testing-infrastructure.md).
- [x] Orbit host installer (`bin/install-orbit`).
- [x] Command docs linter (`tool/docs-linter`, `composer docs-lint`).
