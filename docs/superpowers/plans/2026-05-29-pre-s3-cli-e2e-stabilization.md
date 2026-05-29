# Pre-S3 CLI/E2E Stabilization Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Once Solo items exist, Solo is the live execution ledger; this file is the contract and handoff source.

**Goal:** Finish WebSocket adjustments, clean up the CLI/gateway command split, and move E2E into `apps/e2e` before RustFS/S3 work resumes.

**Architecture:** Public operator commands are owned by `apps/cli/orbit`; gateway Artisan is for gateway maintenance and internal automation only. E2E is root-owned monorepo verification exposed through root Composer scripts, implemented in a dedicated `apps/e2e` app that tests CLI, gateway, and nodes externally instead of importing gateway internals.

**Tech Stack:** PHP 8.5, Laravel Zero CLI app, Laravel 13 gateway app, Pest 4, root Composer orchestration, prepared Docker/Incus E2E lanes.

---

## Overview

This plan converts `solo://proj/2/scratchpad/gateway-cli-contract--353` and `solo://proj/2/scratchpad/future-apps-e2e-arch--354` into bite-sized Solo work before S3/RustFS can continue.

Required lane order:

1. WebSocket adjustments and final WebSocket verification.
2. Gateway/CLI cleanup so public commands live only in `apps/cli`.
3. Full E2E extraction into `apps/e2e`.
4. Final pre-S3 verification gate.

Current findings:

- `apps/gateway/app/Console/Commands` still contains many public command classes that now overlap `apps/cli/app/Commands`.
- Gateway `Kernel.php` hides most of that public surface from normal listing, but direct invocation still works. Example finding: `bin/orbit-gateway-artisan app:list --help` still returned a public command help screen with stale `--environment`.
- `apps/gateway/tests/Feature/Commands` still carries a large amount of public command UX coverage that should either move to CLI tests or be deleted after CLI parity exists.
- Root `composer test:e2e` is already the right public entry point, but the implementation host is still `apps/gateway/tests/E2E`. That implementation host must move to `apps/e2e` before S3 starts.

Pre-S3 decision:

- Do not start S3/RustFS implementation until Solo item `#547` closes and aggregate gate `#536` remains blocked on `#539`.
- Do not move gateway business logic into `packages/core` just to support future `apps/e2e`.
- Do not keep compatibility fallbacks for old gateway public commands, old node role columns, role aliases, or environment fields.
- Do not target new S3 E2E coverage at the gateway harness. S3 E2E starts in `apps/e2e`.

## Complexity

Files: 50+ likely touched during implementation
Modules: `apps/docs`, `apps/gateway`, `apps/cli`, root Composer scripts, E2E harness notes
Risk: High, because deleting gateway command classes can break hidden E2E/provisioning call sites if the inventory is wrong

## Solo Sequence

| Order | Solo item | Purpose |
| --- | --- | --- |
| 1 | `#540` ORBIT-PRE-S3-01 | Document CLI/gateway and E2E ownership contracts |
| 2 | `#543` ORBIT-PRE-S3-02 | Add gateway command inventory and guard tests |
| 3 | `#545` ORBIT-PRE-S3-03 | Port app/node/local command coverage to CLI |
| 4 | `#542` ORBIT-PRE-S3-04 | Remove gateway app/node/local public commands/tests |
| 5 | `#541` ORBIT-PRE-S3-05 | Port database/workspace/process/schedule coverage to CLI |
| 6 | `#546` ORBIT-PRE-S3-06 | Remove gateway resource public commands/tests |
| 7 | `#544` ORBIT-PRE-S3-07 | Port infra/tool command coverage to CLI |
| 8 | `#548` ORBIT-PRE-S3-08 | Remove gateway infra/tool public commands/tests |
| 9 | `#549` ORBIT-PRE-S3-09 | Extract the E2E harness into `apps/e2e` |
| 10 | `#547` ORBIT-PRE-S3-10 | Final command/E2E stabilization gate |

Expected dependency graph:

```text
#347 websocket final verification -> #540 -> #543
  -> #545 -> #542
  -> #541 -> #546
  -> #544 -> #548

#542 + #546 + #548 -> #549 -> #547 -> #539 -> #536 -> S3/RustFS
```

Each implementation item should land as its own commit before the next item starts. If an item discovers broader drift, update the inventory and create a follow-up Solo item instead of expanding the item indefinitely.

## Task 1: Product Contract Docs

Solo item: `#540`

**Files:**

- Modify: `apps/docs/content/architecture.md`
- Modify: `apps/docs/content/concepts.md`
- Modify: `apps/docs/content/tech-stack.md`
- Modify: `apps/docs/content/testing/README.md`
- Inspect: `docs/superpowers/plans/2026-05-27-cli-first-command-surface.md`

- [ ] Read the current authority docs and the CLI-first plan decisions D18 through D22.
- [ ] Update product docs so public Orbit commands are explicitly owned by `apps/cli/orbit`.
- [ ] Update product docs so gateway Artisan is explicitly limited to gateway maintenance, internal automation, E2E/provisioning harness commands, and developer operations through controlled gateway entry points.
- [ ] Update testing docs so E2E is described as root-owned monorepo verification with root Composer entry points, even while the current harness lives in `apps/gateway/tests/E2E`.
- [ ] Add required `apps/e2e` wording: external black-box/gray-box runner before S3 starts, no gateway service/model imports, no gateway business logic moved to `packages/core`.
- [ ] Run:

```bash
composer docs-lint
```

Expected: PASS.

- [ ] Commit:

```bash
git add apps/docs/content docs/superpowers/plans/2026-05-29-pre-s3-cli-e2e-stabilization.md
git commit -m "docs: clarify pre-s3 cli and e2e ownership"
```

## Task 2: Gateway Command Inventory And Guard Tests

Solo item: `#543`

**Files:**

- Modify or create: `apps/gateway/tests/Feature/CommandListVisibilityTest.php`
- Create or extend: `docs/superpowers/notes/gateway-public-command-inventory-2026-05-29.md`
- Inspect: `apps/gateway/app/Console/Kernel.php`
- Inspect: `apps/gateway/app/Console/Commands/**`
- Inspect: `apps/cli/app/Commands/**`
- Inspect: `apps/gateway/tests/Feature/Commands/**`
- Inspect: `apps/cli/tests/Feature/Commands/**`

- [ ] Generate the gateway command list:

```bash
bin/orbit-gateway-artisan list --raw > /tmp/orbit-gateway-commands.txt
```

Expected: file includes gateway maintenance/internal commands and may still include hidden public commands until cleanup lands.

- [ ] Generate the CLI command list:

```bash
apps/cli/orbit list --raw > /tmp/orbit-cli-commands.txt
```

Expected: file includes public Orbit commands and hidden CLI executor commands as configured by the CLI app.

- [ ] Build the checked-in inventory with one row per gateway command class using these classifications: `delete`, `port-cli-coverage-first`, `internalize`, `keep`.
- [ ] Classify these categories as keep by default: Laravel/gateway maintenance, `orbit:internal:*`, `e2e:*`, `orbit-scheduler`, intentional docs/librarian/dev commands.
- [ ] Classify stale public command families as delete or port first: app, node, database, workspace, process, schedule, tool, proxy, firewall, Cloudflare, VPN, deploy, activity, DNS, PHP, gateway trust/add, doctor, profile, update, agent-ide.
- [ ] Add or update gateway guard tests that pass in the current repo by asserting every invokable non-allowed gateway command is explicitly classified in the inventory. Include at least `app:list`, `node:new`, `database:list`, `workspace:new`, `tool:list`, and `doctor` in the classified stale set.
- [ ] Add the final-state assertion path to the same test or companion test so the removal tasks can flip the classified stale set to empty as families are deleted.
- [ ] Run:

```bash
bin/orbit-gateway-pest --compact tests/Feature/CommandListVisibilityTest.php
bin/orbit-gateway-vendor-bin pint --dirty --format agent
```

Expected: the visibility test passes with a current allow-list/inventory assertion. Pint exits cleanly.

- [ ] Commit:

```bash
git add apps/gateway/tests/Feature/CommandListVisibilityTest.php docs/superpowers/notes/gateway-public-command-inventory-2026-05-29.md
git commit -m "test: inventory gateway command boundary"
```

## Task 3: CLI Coverage For App, Node, Local, And Operation Commands

Solo item: `#545`

**Files:**

- Modify or create: `apps/cli/tests/Feature/Commands/**`
- Modify only if a test exposes a real CLI gap: `apps/cli/app/Commands/**`
- Update: `docs/superpowers/notes/gateway-public-command-inventory-2026-05-29.md`

- [ ] Compare gateway command tests against CLI tests for `app:*`, `node:*`, `node role:*`, `node:default`, `gateway:add`, `gateway:trust`, `dns:*`, `php:*`, `update`, `update:all`, `doctor`, and `profile`.
- [ ] Port missing CLI-owned coverage to `apps/cli/tests/Feature/Commands/**`.
- [ ] Cover command signatures, validation, JSON envelopes, human output, gateway API payloads or stream contracts, and local config behavior where those concerns exist.
- [ ] Keep gateway API/service behavior in gateway tests. Do not move those tests to CLI.
- [ ] Mark the corresponding gateway command tests in the inventory as safe to delete once CLI parity exists.
- [ ] Run:

```bash
bin/orbit-cli-pest --compact --filter='App|Node|Gateway|Dns|Php|Update|Doctor|Profile'
cd apps/cli && vendor/bin/pint --dirty --format agent
```

Expected: focused CLI tests pass and modified PHP files are formatted.

- [ ] Commit:

```bash
git add apps/cli docs/superpowers/notes/gateway-public-command-inventory-2026-05-29.md
git commit -m "test: port app node local command coverage to cli"
```

## Task 4: Remove Gateway App, Node, Local, And Operation Public Commands

Solo item: `#542`

**Files:**

- Delete or modify: `apps/gateway/app/Console/Commands/**`
- Delete or modify: `apps/gateway/tests/Feature/Commands/**`
- Keep as needed: `apps/gateway/app/Http/Controllers/Api/**`
- Keep as needed: `apps/gateway/app/Services/**`
- Update: `docs/superpowers/notes/gateway-public-command-inventory-2026-05-29.md`

- [ ] Delete or unregister gateway public command classes covered by Task 3.
- [ ] If a command class owns business logic still needed by gateway HTTP/API behavior, move that logic into a gateway service/action before deleting the command class.
- [ ] Do not leave a hidden public command as a compatibility fallback.
- [ ] Keep `orbit:internal:*`, E2E support commands, scheduler/queue/migration maintenance, and intentional gateway maintenance commands.
- [ ] Verify removed names are no longer direct-invokable through `bin/orbit-gateway-artisan`.
- [ ] Run:

```bash
bin/orbit-gateway-pest --compact tests/Feature/CommandListVisibilityTest.php
bin/orbit-cli-pest --compact --filter='App|Node|Gateway|Dns|Php|Update|Doctor|Profile'
bin/orbit-gateway-vendor-bin pint --dirty --format agent
```

Expected: gateway command guard passes, CLI ownership tests still pass, formatting exits cleanly.

- [ ] Commit:

```bash
git add apps/gateway apps/cli docs/superpowers/notes/gateway-public-command-inventory-2026-05-29.md
git commit -m "refactor: remove gateway app node local commands"
```

## Task 5: CLI Coverage For Resource Command Families

Solo item: `#541`

**Files:**

- Modify or create: `apps/cli/tests/Feature/Commands/**`
- Modify only if a test exposes a real CLI gap: `apps/cli/app/Commands/**`
- Update: `docs/superpowers/notes/gateway-public-command-inventory-2026-05-29.md`

- [ ] Compare gateway command tests against CLI tests for `database:*`, `workspace:*`, workspace setup and teardown step commands, `process:*`, and `schedule:*`.
- [ ] Port missing CLI-owned UX/input/output coverage.
- [ ] Keep gateway API/service/model tests in gateway.
- [ ] Mark corresponding gateway command tests in the inventory as safe to delete once CLI parity exists.
- [ ] Run:

```bash
bin/orbit-cli-pest --compact --filter='Database|Workspace|Process|Schedule'
cd apps/cli && vendor/bin/pint --dirty --format agent
```

Expected: focused CLI tests pass and formatting exits cleanly.

- [ ] Commit:

```bash
git add apps/cli docs/superpowers/notes/gateway-public-command-inventory-2026-05-29.md
git commit -m "test: port resource command coverage to cli"
```

## Task 6: Remove Gateway Resource Public Commands

Solo item: `#546`

**Files:**

- Delete or modify: `apps/gateway/app/Console/Commands/**`
- Delete or modify: `apps/gateway/tests/Feature/Commands/**`
- Keep as needed: `apps/gateway/app/Http/Controllers/Api/**`
- Keep as needed: `apps/gateway/app/Services/**`
- Update: `docs/superpowers/notes/gateway-public-command-inventory-2026-05-29.md`

- [ ] Delete or unregister gateway public command classes for database, workspace, process, and schedule families where the inventory says CLI coverage exists.
- [ ] Preserve `orbit-scheduler` and Laravel framework schedule maintenance commands.
- [ ] Extract needed business logic into gateway services/actions before deletion.
- [ ] Verify removed names are no longer direct-invokable through `bin/orbit-gateway-artisan`.
- [ ] Run:

```bash
bin/orbit-gateway-pest --compact tests/Feature/CommandListVisibilityTest.php
bin/orbit-cli-pest --compact --filter='Database|Workspace|Process|Schedule'
bin/orbit-gateway-vendor-bin pint --dirty --format agent
```

Expected: gateway command guard passes, CLI resource tests pass, formatting exits cleanly.

- [ ] Commit:

```bash
git add apps/gateway apps/cli docs/superpowers/notes/gateway-public-command-inventory-2026-05-29.md
git commit -m "refactor: remove gateway resource commands"
```

## Task 7: CLI Coverage For Infra And Tool Command Families

Solo item: `#544`

**Files:**

- Modify or create: `apps/cli/tests/Feature/Commands/**`
- Modify only if a test exposes a real CLI gap: `apps/cli/app/Commands/**`
- Update: `docs/superpowers/notes/gateway-public-command-inventory-2026-05-29.md`

- [ ] Compare gateway command tests against CLI tests for `tool:*`, `proxy:*`, `firewall:*`, `cf-*`, `vpn-client:*`, `vpn-web-ui:*`, `deploy:*`, `activity:*`, `agent-ide:message`, `app:agent-ide`, and `node:agent-ide`.
- [ ] Port missing CLI-owned UX/input/output coverage.
- [ ] Keep gateway API/service/model tests in gateway.
- [ ] Mark corresponding gateway command tests in the inventory as safe to delete once CLI parity exists.
- [ ] Run:

```bash
bin/orbit-cli-pest --compact --filter='Tool|Proxy|Firewall|Cloudflare|Vpn|Deploy|Activity|AgentIde'
cd apps/cli && vendor/bin/pint --dirty --format agent
```

Expected: focused CLI tests pass and formatting exits cleanly.

- [ ] Commit:

```bash
git add apps/cli docs/superpowers/notes/gateway-public-command-inventory-2026-05-29.md
git commit -m "test: port infra tool command coverage to cli"
```

## Task 8: Remove Gateway Infra And Tool Public Commands

Solo item: `#548`

**Files:**

- Delete or modify: `apps/gateway/app/Console/Commands/**`
- Delete or modify: `apps/gateway/tests/Feature/Commands/**`
- Keep as needed: `apps/gateway/app/Http/Controllers/Api/**`
- Keep as needed: `apps/gateway/app/Services/**`
- Update: `docs/superpowers/notes/gateway-public-command-inventory-2026-05-29.md`

- [ ] Delete or unregister gateway public command classes for infra and tool families where the inventory says CLI coverage exists.
- [ ] Extract needed business logic into gateway services/actions before deletion.
- [ ] Verify removed names are no longer direct-invokable through `bin/orbit-gateway-artisan`.
- [ ] Run:

```bash
bin/orbit-gateway-pest --compact tests/Feature/CommandListVisibilityTest.php
bin/orbit-cli-pest --compact --filter='Tool|Proxy|Firewall|Cloudflare|Vpn|Deploy|Activity|AgentIde'
bin/orbit-gateway-vendor-bin pint --dirty --format agent
```

Expected: gateway command guard passes, CLI infra/tool tests pass, formatting exits cleanly.

- [ ] Commit:

```bash
git add apps/gateway apps/cli docs/superpowers/notes/gateway-public-command-inventory-2026-05-29.md
git commit -m "refactor: remove gateway infra tool commands"
```

## Task 9: Extract E2E Into Apps/E2E

Solo item: `#549`

**Files:**

- Create: `apps/e2e/**`
- Move or rewrite: `apps/gateway/tests/E2E/**`
- Move or extract: `apps/gateway/app/E2E/Support/**`
- Modify: root `composer.json`
- Modify: `apps/docs/content/testing/README.md`
- Modify as needed: `apps/docs/content/testing/e2e/**`
- Create: `docs/superpowers/notes/future-apps-e2e-migration-2026-05-29.md`
- Keep: gateway unit/feature/API tests that instantiate gateway services, models, controllers, jobs, or internal commands
- Keep or move with a clear boundary: E2E helper code that is topology/process support, not gateway business logic
- Inspect: `apps/gateway/tests/E2E/**`
- Inspect: `apps/gateway/app/E2E/Support/**`
- Inspect: S3/RustFS Solo items blocked by `#536`

- [ ] Read `apps/docs/content/testing/README.md` before touching E2E docs or scripts.
- [ ] Create a dedicated `apps/e2e` app/harness that can run Pest tests from the monorepo without importing gateway application internals as the test subject.
- [ ] Classify current `apps/gateway/tests/E2E` into three groups:
  - move to `apps/e2e` now because the test drives CLI/API/SSH/Docker/Incus externally;
  - rewrite while moving because the current test reaches into gateway internals but should assert external behavior;
  - keep in gateway because it is actually a gateway feature/unit/API/internals test, not E2E.
- [ ] Move the E2E tests and topology helpers that belong to external verification into `apps/e2e`.
- [ ] Keep gateway business logic in `apps/gateway`. Shared test harness utilities may move to `apps/e2e` or a narrow support package only when they are not gateway product behavior.
- [ ] Update root Composer E2E scripts so `composer test:e2e`, `composer test:e2e:docker`, `composer test:e2e:incus`, `composer test:e2e:topology-contract`, and `composer test:e2e:provision` run through the new `apps/e2e` entry points where applicable.
- [ ] Retire or redirect gateway `e2e:*` Artisan command entry points when they only exist to host the old E2E runner. Keep gateway internal/provisioning commands only when they are real gateway maintenance/internal automation.
- [ ] Write the migration note with the final state:
  - root `composer test:e2e` remains the public entry point;
  - `apps/e2e` owns external E2E execution before S3 starts;
  - gateway tests keep only gateway internals, API, services, models, jobs, and internal command coverage;
  - `packages/core` is not a dumping ground for gateway/E2E convenience;
  - S3 E2E must be added under `apps/e2e`.
- [ ] Update S3/RustFS Solo item bodies if needed so they target `apps/e2e` for E2E coverage and remain blocked by `#536`.
- [ ] Run:

```bash
composer docs-lint
composer test:e2e
```

Expected: PASS.

- [ ] Run provider-specific E2E lanes when available/relevant:

```bash
composer test:e2e:docker
composer test:e2e:incus
composer test:e2e:provision
```

Expected: PASS where the local provider is available. If Incus is unavailable, record the concrete environment blocker.

- [ ] Commit:

```bash
git add apps/e2e apps/gateway apps/docs/content/testing composer.json docs/superpowers/notes/future-apps-e2e-migration-2026-05-29.md
git commit -m "refactor: move e2e harness into dedicated app"
```

Only add paths that exist and changed.

## Task 10: Final Pre-S3 Stabilization Gate

Solo item: `#547`

**Files:**

- Inspect: final diff across `apps/docs`, `apps/gateway`, `apps/cli`, `packages/core`, root Composer scripts
- Inspect: Solo items `#540` through `#549`
- Update: Solo items `#539` and `#536`

- [ ] Confirm `#540`, `#543`, `#545`, `#542`, `#541`, `#546`, `#544`, `#548`, and `#549` are closed with verification evidence.
- [ ] Confirm gateway no longer registers retired public command names that now live in `apps/cli`.
- [ ] Confirm CLI command ownership tests cover the public command families removed from gateway.
- [ ] Confirm `composer test:e2e` now runs through `apps/e2e`, not the gateway test harness.
- [ ] Confirm S3/RustFS remains blocked by `#536`, and `#536` remains blocked by `#539`.
- [ ] Run:

```bash
composer docs-lint
bin/orbit-gateway-pest --compact tests/Feature/CommandListVisibilityTest.php
bin/orbit-cli-pest --compact tests/Feature/CommandListVisibilityTest.php
composer quality-check
composer test:e2e
```

Expected: PASS.

- [ ] Run Incus E2E when available or relevant:

```bash
composer test:e2e:incus
```

Expected: PASS, or a close-out comment records the exact environment blocker.

- [ ] If all evidence is current, close `#547`, then close or update `#539` with final evidence for `#536` review.
- [ ] Commit final fixes only if the verification gate required code/doc changes:

```bash
git add .
git commit -m "test: close pre-s3 command e2e gate"
```

## Open Questions

- None blocking this plan. The user decision is now explicit: move the E2E harness into `apps/e2e` before S3 work starts.
