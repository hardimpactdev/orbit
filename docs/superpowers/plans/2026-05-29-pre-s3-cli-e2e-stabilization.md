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
| 4 | `#542` ORBIT-PRE-S3-04 | Extract then remove gateway app/node/local public commands/tests |
| 5 | `#541` ORBIT-PRE-S3-05 | Port database/workspace/process/schedule coverage to CLI |
| 6 | `#546` ORBIT-PRE-S3-06 | Extract then remove gateway resource public commands/tests |
| 7 | `#544` ORBIT-PRE-S3-07 | Port infra/tool command coverage to CLI |
| 8 | `#548` ORBIT-PRE-S3-08 | Extract then remove gateway infra/tool public commands/tests |
| 9 | `#550`-`#557` ORBIT-PRE-S3-09A-H | Move the E2E harness into `apps/e2e` in batches |
| 10 | `#549` ORBIT-PRE-S3-09 | Aggregate `apps/e2e` extraction gate |
| 11 | `#547` ORBIT-PRE-S3-10 | Final command/E2E stabilization gate |

Expected dependency graph:

```text
#347 websocket final verification -> #540 -> #543
  -> #545 -> #542
  -> #541 -> #546
  -> #544 -> #548

#542 + #546 + #548 -> #550 -> #551 -> #552 -> #555 -> #556 -> #553 -> #554 -> #557 -> #549 -> #547 -> #539 -> #536 -> S3/RustFS
```

Each implementation item should land as its own commit before the next item starts. If an item discovers broader drift, update the inventory and create a follow-up Solo item instead of expanding the item indefinitely.

The three command-family lanes after `#543` may be implemented in parallel only if each worker owns a disjoint family and rebases before merge. They all touch the shared inventory note and `CommandListVisibilityTest.php`, so merge-backs must be serialized or the inventory must be partitioned by family inside the same note.

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

- [ ] Read prior inventory/classification work if present:
  - `docs/superpowers/notes/cli-command-classification-2026-05-28.md`
  - `docs/superpowers/notes/phase4-pre-sweep-inventory-2026-05-28.md`
- [ ] Build the checked-in inventory with one row per gateway command class using these classifications: `delete`, `port-cli-coverage-first`, `internalize-extract-first`, `keep`.
- [ ] Include these columns in the inventory: command name, gateway command class, CLI owner, gateway internal call sites, classification, removal todo, and required tests.
- [ ] Inventory internal gateway command call sites:

```bash
rg -n 'Artisan::call\(|\$this->call\(' apps/gateway/app/Http apps/gateway/app/Services apps/gateway/app/Actions apps/gateway/app/Jobs
```

- [ ] Pre-classify these known live API dependencies as `internalize-extract-first`:
  - `app:exec` from `AppExecController`
  - `app:register` from `AppRegisterController`
  - `app:root` from `AppRootController`
  - `app:worker` from `AppWorkerController`
  - `node:new` from `NodeStoreController`
  - `workspace:exec` from `WorkspaceExecController`
- [ ] Classify these categories as keep by default: Laravel/gateway maintenance, `orbit:internal:*`, `e2e:*`, `orbit-scheduler`, intentional docs/librarian/dev commands.
- [ ] Classify stale public command families as delete or port first: app, node, database, workspace, process, schedule, tool, proxy, firewall, Cloudflare, VPN, deploy, activity, DNS, PHP, gateway trust/add, doctor, profile, update, agent-ide.
- [ ] Add or update gateway guard tests that pass in the current repo by asserting every invokable non-allowed gateway command is explicitly classified in the inventory. Include at least `app:list`, `node:new`, `database:list`, `workspace:new`, `tool:list`, and `doctor` in the classified stale set.
- [ ] Update `apps/gateway/tests/Feature/CommandListVisibilityTest.php`. It already asserts the transitional hidden-but-invokable contract, including `app:root`; mark that explicitly as transitional and add the final-state assertion path the removal tasks can flip to "not registered/invokable".
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
- [ ] Use the inventory from Task 2 to decide whether `dns:*`, `gateway:add`, `gateway:trust`, `node:default`, and `php:*` are CLI-only/local/bootstrap concerns or gateway-owned surfaces before any gateway removal task acts on them.
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

## Task 4: Extract Then Remove Gateway App, Node, Local, And Operation Public Commands

Solo item: `#542`

**Files:**

- Delete or modify: `apps/gateway/app/Console/Commands/**`
- Delete or modify: `apps/gateway/tests/Feature/Commands/**`
- Keep as needed: `apps/gateway/app/Http/Controllers/Api/**`
- Keep as needed: `apps/gateway/app/Services/**`
- Update: `docs/superpowers/notes/gateway-public-command-inventory-2026-05-29.md`

- [ ] For the known live API dependencies, extract command-owned behavior into gateway services/actions before deleting or unregistering the command classes:
  - `AppExecController` must no longer call `app:exec`.
  - `AppRegisterController` must no longer call `app:register`.
  - `AppRootController` must no longer call `app:root`.
  - `AppWorkerController` must no longer call `app:worker`.
  - `NodeStoreController` must no longer call `node:new`.
- [ ] Add or update focused gateway API/controller tests for the rewired endpoints before deleting command classes.
- [ ] Delete or invert the obsolete `app:root` hidden adapter assertion in `CommandListVisibilityTest.php` when the replacement path is covered.
- [ ] Delete or unregister gateway public command classes covered by Task 3 after their gateway API dependencies are rewired.
- [ ] Do not leave a hidden public command as a compatibility fallback.
- [ ] Keep `orbit:internal:*`, E2E support commands, scheduler/queue/migration maintenance, and intentional gateway maintenance commands.
- [ ] Verify removed names are no longer direct-invokable through `bin/orbit-gateway-artisan`.
- [ ] Run:

```bash
bin/orbit-gateway-pest --compact tests/Feature/CommandListVisibilityTest.php
bin/orbit-cli-pest --compact --filter='App|Node|Gateway|Dns|Php|Update|Doctor|Profile'
bin/orbit-gateway-vendor-bin pint --dirty --format agent
```

Expected: gateway command guard passes, relevant gateway API/controller tests pass, CLI ownership tests still pass, formatting exits cleanly.

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

## Task 6: Extract Then Remove Gateway Resource Public Commands

Solo item: `#546`

**Files:**

- Delete or modify: `apps/gateway/app/Console/Commands/**`
- Delete or modify: `apps/gateway/tests/Feature/Commands/**`
- Keep as needed: `apps/gateway/app/Http/Controllers/Api/**`
- Keep as needed: `apps/gateway/app/Services/**`
- Update: `docs/superpowers/notes/gateway-public-command-inventory-2026-05-29.md`

- [ ] For the known live API dependency, extract command-owned behavior into a gateway service/action before deleting or unregistering the command class:
  - `WorkspaceExecController` must no longer call `workspace:exec`.
- [ ] Add or update a focused gateway API/controller test for the rewired workspace execution endpoint before deleting `workspace:exec`.
- [ ] Delete or unregister gateway public command classes for database, workspace, process, and schedule families where the inventory says CLI coverage exists and internal call sites are rewired.
- [ ] Preserve `orbit-scheduler` and Laravel framework schedule maintenance commands.
- [ ] Extract any additional needed business logic discovered by the inventory into gateway services/actions before deletion.
- [ ] Verify removed names are no longer direct-invokable through `bin/orbit-gateway-artisan`.
- [ ] Run:

```bash
bin/orbit-gateway-pest --compact tests/Feature/CommandListVisibilityTest.php
bin/orbit-cli-pest --compact --filter='Database|Workspace|Process|Schedule'
bin/orbit-gateway-vendor-bin pint --dirty --format agent
```

Expected: gateway command guard passes, relevant gateway API/controller tests pass, CLI resource tests pass, formatting exits cleanly.

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

## Task 8: Extract Then Remove Gateway Infra And Tool Public Commands

Solo item: `#548`

**Files:**

- Delete or modify: `apps/gateway/app/Console/Commands/**`
- Delete or modify: `apps/gateway/tests/Feature/Commands/**`
- Keep as needed: `apps/gateway/app/Http/Controllers/Api/**`
- Keep as needed: `apps/gateway/app/Services/**`
- Update: `docs/superpowers/notes/gateway-public-command-inventory-2026-05-29.md`

- [ ] Extract any internal gateway call sites discovered by Task 2 into gateway services/actions before deleting or unregistering command classes.
- [ ] Add or update focused gateway tests for any rewired internal endpoints or jobs before deleting command classes.
- [ ] Delete or unregister gateway public command classes for infra and tool families where the inventory says CLI coverage exists and internal call sites are rewired.
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

## Task 9A: Scaffold Dedicated Apps/E2E Harness

Solo item: `#550`

**Files:**

- Create: `apps/e2e/**`
- Modify: root `composer.json`
- Inspect: `apps/docs/content/testing/README.md`
- Inspect: `apps/gateway/tests/E2E/**`
- Inspect: `apps/gateway/app/E2E/Support/**`

- [ ] Read `apps/docs/content/testing/README.md` before touching E2E scripts or docs.
- [ ] Create a dedicated `apps/e2e` app/harness that can run Pest tests from the monorepo without importing gateway application internals as the test subject.
- [ ] Add one minimal external smoke/topology-contract test under `apps/e2e` that proves the harness boots.
- [ ] Add a temporary root Composer entry point, such as `test:e2e:next`, that runs only the new harness. Do not flip `composer test:e2e` yet.
- [ ] Document the harness boundary in code or a local README: E2E may call CLI/API/SSH/Docker/Incus externally, but must not import gateway services, models, controllers, jobs, or internal commands as product behavior.
- [ ] Put immediate E2E support code under `apps/e2e` internal support namespaces. Do not create `packages/e2e-support` or move helpers to `packages/core` unless a separate architecture todo explicitly approves that later.
- [ ] Run:

```bash
composer test:e2e:next
```

Expected: PASS.

- [ ] Commit:

```bash
git add apps/e2e composer.json
git commit -m "test: scaffold dedicated e2e app"
```

## Task 9B: Extract E2E Support Utilities

Solo item: `#551`

**Files:**

- Move or rewrite: `apps/gateway/app/E2E/Support/**`
- Modify: `apps/e2e/**`
- Keep: gateway product services, models, controllers, jobs, and gateway-owned internals

- [ ] Classify the 49 current gateway E2E support classes into:
  - move to `apps/e2e` because they are topology/process/test harness utilities;
  - rewrite while moving because they currently reach into gateway internals but should call external CLI/API/process boundaries;
  - keep in gateway because they are gateway product behavior, not E2E harness support.
- [ ] Move the topology/process support subset into `apps/e2e` support namespaces.
- [ ] Do not move gateway product behavior to `packages/core` or use `packages/core` as a convenience dump for the E2E app.
- [ ] Keep the old gateway E2E runner working until root scripts are flipped in Task 9G.
- [ ] Run:

```bash
composer test:e2e:next
composer test:e2e:topology-contract
```

Expected: PASS.

- [ ] Commit:

```bash
git add apps/e2e apps/gateway
git commit -m "refactor: extract e2e support utilities"
```

## Task 9C: Move Topology And Provider E2E Tests

Solo item: `#552`

**Files:**

- Move or rewrite: `apps/gateway/tests/E2E/**Topology**`
- Move or rewrite: `apps/gateway/tests/E2E/**Provider**`
- Modify: `apps/e2e/**`

- [ ] Move Docker/Incus/topology/provider contract tests that drive external behavior into `apps/e2e`.
- [ ] Rewrite any moved test that imports gateway internals so it asserts behavior through CLI/API/process boundaries.
- [ ] Keep gateway feature/unit/API tests in gateway when they instantiate gateway internals.
- [ ] Run:

```bash
composer test:e2e:next
composer test:e2e:topology-contract
```

Expected: PASS. If Incus is unavailable locally, record the exact environment blocker instead of widening the task.

- [ ] Commit:

```bash
git add apps/e2e apps/gateway
git commit -m "test: move topology e2e tests"
```

## Task 9D: Move App, Node, Gateway, And Local E2E Tests

Solo item: `#555`

**Files:**

- Move or rewrite: `apps/gateway/tests/E2E/**App**`
- Move or rewrite: `apps/gateway/tests/E2E/**Node**`
- Move or rewrite: `apps/gateway/tests/E2E/**Gateway**`
- Move or rewrite: local/bootstrap E2E tests under `apps/gateway/tests/E2E/**`
- Modify: `apps/e2e/**`

- [ ] Move app, node, gateway, and local/bootstrap E2E tests that drive external behavior into `apps/e2e`.
- [ ] Keep gateway API/service/model tests in gateway.
- [ ] Verify node role assertions keep the canonical `node_role` pivot contract and do not reintroduce `nodes.role`, `nodes.environment`, role aliases, or environment fields.
- [ ] Run:

```bash
composer test:e2e:next
composer test:e2e:docker
```

Expected: PASS where Docker is available.

- [ ] Commit:

```bash
git add apps/e2e apps/gateway
git commit -m "test: move app node gateway e2e tests"
```

## Task 9E: Move Resource Command E2E Tests

Solo item: `#556`

**Files:**

- Move or rewrite: `apps/gateway/tests/E2E/**Database**`
- Move or rewrite: `apps/gateway/tests/E2E/**Workspace**`
- Move or rewrite: `apps/gateway/tests/E2E/**Process**`
- Move or rewrite: `apps/gateway/tests/E2E/**Schedule**`
- Modify: `apps/e2e/**`

- [ ] Move database, workspace, process, and schedule E2E tests that drive external behavior into `apps/e2e`.
- [ ] Keep gateway internals coverage in gateway tests when the test directly instantiates gateway services, models, jobs, or internal commands.
- [ ] Run:

```bash
composer test:e2e:next
```

Expected: PASS.

- [ ] Commit:

```bash
git add apps/e2e apps/gateway
git commit -m "test: move resource e2e tests"
```

## Task 9F: Move Infra, Tool, And WebSocket E2E Tests

Solo item: `#553`

**Files:**

- Move or rewrite: `apps/gateway/tests/E2E/**Tool**`
- Move or rewrite: `apps/gateway/tests/E2E/**Proxy**`
- Move or rewrite: `apps/gateway/tests/E2E/**Firewall**`
- Move or rewrite: `apps/gateway/tests/E2E/**Vpn**`
- Move or rewrite: `apps/gateway/tests/E2E/**Deploy**`
- Move or rewrite: `apps/gateway/tests/E2E/**Activity**`
- Move or rewrite: `apps/gateway/tests/E2E/**WebSocket**`
- Modify: `apps/e2e/**`

- [ ] Move infra, tool, and WebSocket E2E tests that drive external behavior into `apps/e2e`.
- [ ] Ensure WebSocket E2E coverage stays aligned with the current contract: public WebSocket traffic enters ingress, routes to the gateway router, and the gateway router balances directly to WebSocket backend WireGuard IPs; inside the Orbit network, `websocket.orbit` is the logical service host.
- [ ] Current implementation may support max one active WebSocket backend, but the route shape must stay backend-pool friendly so later 2+ backend support does not require a public routing redesign.
- [ ] Run:

```bash
composer test:e2e:next
composer test:e2e:docker
```

Expected: PASS where Docker is available.

- [ ] Commit:

```bash
git add apps/e2e apps/gateway
git commit -m "test: move infra websocket e2e tests"
```

## Task 9G: Flip Root E2E Scripts To Apps/E2E

Solo item: `#554`

**Files:**

- Modify: root `composer.json`
- Delete or modify: gateway `e2e:*` Artisan command entry points that only hosted the old runner
- Keep: gateway internal/provisioning commands only when they are real gateway maintenance/internal automation
- Modify: `apps/e2e/**`

- [ ] Update root Composer E2E scripts so `composer test:e2e`, `composer test:e2e:docker`, `composer test:e2e:incus`, `composer test:e2e:topology-contract`, and `composer test:e2e:provision` run through `apps/e2e` where applicable.
- [ ] Remove or redirect gateway `e2e:*` Artisan command entry points that only exist to host the old E2E runner.
- [ ] Keep gateway internal/provisioning commands only when they are real gateway maintenance/internal automation.
- [ ] Run:

```bash
composer test:e2e
composer test:e2e:topology-contract
```

Expected: PASS.

- [ ] Run provider-specific lanes when available/relevant:

```bash
composer test:e2e:docker
composer test:e2e:incus
composer test:e2e:provision
```

Expected: PASS where the local provider is available. If Incus is unavailable, record the concrete environment blocker.

- [ ] Commit:

```bash
git add apps/e2e apps/gateway composer.json
git commit -m "refactor: route e2e scripts through e2e app"
```

## Task 9H: Document Apps/E2E Split And Repoint S3 Todos

Solo item: `#557`

**Files:**

- Modify: `apps/docs/content/testing/README.md`
- Modify as needed: `apps/docs/content/testing/e2e/**`
- Create: `docs/superpowers/notes/future-apps-e2e-migration-2026-05-29.md`
- Update: S3/RustFS Solo item bodies that mention old gateway E2E paths

- [ ] Write the migration note with the final state:
  - root `composer test:e2e` remains the public entry point;
  - `apps/e2e` owns external E2E execution before S3 starts;
  - gateway tests keep only gateway internals, API, services, models, jobs, and internal command coverage;
  - `packages/core` is not a dumping ground for gateway/E2E convenience;
  - S3 E2E must be added under `apps/e2e`.
- [ ] Repoint S3/RustFS todos that reference old gateway E2E paths. At minimum inspect and update `#351`, `#352`, `#423`, and `#424`.
- [ ] Keep all S3/RustFS implementation todos blocked by `#536`.
- [ ] Run:

```bash
composer docs-lint
composer test:e2e
```

Expected: PASS.

- [ ] Commit:

```bash
git add apps/docs/content/testing docs/superpowers/notes/future-apps-e2e-migration-2026-05-29.md
git commit -m "docs: document dedicated e2e app"
```

## Task 9I: Aggregate Apps/E2E Extraction Gate

Solo item: `#549`

**Files:**

- Inspect: Solo items `#550` through `#557`
- Inspect: root `composer.json`
- Inspect: `apps/e2e/**`
- Inspect: `apps/gateway/tests/**`
- Inspect: `apps/gateway/app/Console/Commands/**`

- [ ] Confirm `#550` scaffolded `apps/e2e`.
- [ ] Confirm `#551` extracted E2E support utilities without moving gateway product behavior to `packages/core`.
- [ ] Confirm `#552`, `#555`, `#556`, and `#553` moved or rewrote all external E2E tests into `apps/e2e`.
- [ ] Confirm `#554` flipped root E2E Composer scripts to `apps/e2e`.
- [ ] Confirm `#557` documented the final split and repointed S3/RustFS E2E todos.
- [ ] Spot-check or re-run:

```bash
composer test:e2e
```

Expected: PASS, unless the close-out evidence from the decomposed todos is current and complete.

- [ ] Close `#549` only after the decomposed E2E chain is complete. Commit only if this gate finds final fixes.

## Task 10: Final Pre-S3 Stabilization Gate

Solo item: `#547`

**Files:**

- Inspect: final diff across `apps/docs`, `apps/gateway`, `apps/cli`, `packages/core`, root Composer scripts
- Inspect: Solo items `#540` through `#557`
- Update: Solo items `#539` and `#536`

- [ ] Confirm `#540`, `#543`, `#545`, `#542`, `#541`, `#546`, `#544`, `#548`, `#550`, `#551`, `#552`, `#555`, `#556`, `#553`, `#554`, `#557`, and `#549` are closed with verification evidence.
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
