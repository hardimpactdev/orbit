# Operator Node Terminology Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace Orbit's `control` / `control node` terminology with `operator` / `operator node`, while preserving the product meaning: an operator node is a joined Orbit node that can operate part or all of the topology through gateway identity and grants.

**Architecture:** Treat `operator` as a node capability/identity term, not a hosted role. Operator nodes may have no hosted roles, but a node with hosted roles can also act as an operator when it has gateway identity and grants. Keep compatibility aliases during the migration where existing flags, persisted rows, E2E provider inputs, or external scripts still say `control`.

**Tech Stack:** Laravel 13, Pest, existing E2E topology harness, Orbit command docs, Laravel Pint.

---

## Status

This plan is intentionally added on `main` so it does not contaminate the dirty `composable-node-roles` worktree. The implementation should run in a fresh worktree. If `composable-node-roles` has merged by then, implement against current `main`; otherwise implement against a branch based on `composable-node-roles` because that branch already demotes `control` from product role to joined identity.

## Product Vocabulary

- **Operator node:** a joined Orbit node that can operate the topology through gateway API identity and grants.
- **Gateway node:** the singleton authority node that owns durable Orbit state and policy.
- **Hosted node:** a node with hosted role assignments such as app development, app production, or database.
- **Operator capability:** any joined node can act as an operator when it has gateway identity and grants. A hosted app-development node can therefore also be an operator.
- **Legacy control:** compatibility spelling for old rows, old CLI flags, old tests, old E2E image names, and transition docs only.

## File Map

### Product Docs

- Modify: `docs/ARCHITECTURE.md`
- Modify: `docs/BUILDING-BLOCKS.md`
- Modify: `docs/CONCEPTS.md`
- Modify: `docs/domains/**`
- Modify: `docs/abstractions/**`
- Modify: `docs/superpowers/specs/**` only where active specs are still used by current E2E work.

### Command And API Surface

- Modify: `app/Console/Commands/GatewayAddCommand.php`
- Modify: `app/Console/Commands/NodeNewCommand.php`
- Modify: `app/Http/Controllers/Api/*Controller.php` files that return `control node` or `control or gateway node` messages.
- Modify: request/response DTOs only if they expose `control` in public JSON keys.
- Preserve compatibility for existing public inputs such as `--control-name` until a separate breaking-change plan removes or aliases them.

### Domain Model And Factories

- Modify: `app/Models/Node.php`
- Modify: `database/factories/NodeFactory.php`
- Modify: node-related tests under `tests/Feature/**` and `tests/Unit/**`.
- Do not add an `operator` hosted role. A row value or helper named `operator` is allowed only as a compatibility shadow for "joined node with no hosted roles".

### E2E Topologies

- Modify: `app/E2E/Support/E2ETopologyKind.php`
- Modify: `app/E2E/Support/E2ETopologyLease.php`
- Modify: `app/E2E/Support/E2ETopologyHarness.php`
- Modify: `app/E2E/Support/E2EConfig.php`
- Modify: `app/E2E/Support/E2ECurrentCheckout.php`
- Modify: `app/E2E/Support/E2EGatewayApi.php`
- Modify: `app/E2E/Support/E2EControlIdentity.php`
- Modify: `app/E2E/Support/IncusTopologyBuilder.php`
- Modify: `app/E2E/Support/IncusTopologyProvider.php`
- Modify: `app/E2E/Support/DockerTopologyProvider.php`
- Modify: `docker/e2e/topology/Dockerfile`
- Modify: `tests/E2E/**`

Expected topology rename:

| Old | New |
| --- | --- |
| `control` | `operator` |
| `control-gateway` | `operator-gateway` |
| `control-gateway-dev` | `operator-gateway-appdev` |
| `control-gateway-dev-prod` | `operator-gateway-appdev-appprod` |
| `e2e-feature-control-gateway-dev` | `e2e-feature-operator-gateway-appdev` |
| `e2e-feature-control-gateway-dev-prod` | `e2e-feature-operator-gateway-appdev-appprod` |

Keep old group names and enum values as deprecated aliases for one migration window if CI or local scripts still select them.

## Phases

### Phase 1: Define Compatibility Boundary

- [ ] Decide the persistent compatibility rule for `nodes.role = control`: either keep it as a legacy database value meaning "operator with no hosted roles", or migrate it to `operator` with a backward-compatible reader.
- [ ] If `composable-node-roles` is the base, confirm that "no active hosted role assignments" is the canonical role-assignment meaning for an operator-only node.
- [ ] Add or update focused tests proving old `control` inputs still resolve to operator semantics during the transition.
- [ ] Commit: `test: cover operator terminology compatibility`.

### Phase 2: Rename Product Language In Docs

- [ ] Replace product-facing `control node` language with `operator node` in architecture, concepts, building blocks, command docs, and abstraction docs.
- [ ] Clarify that operator is not mutually exclusive with hosted roles: a hosted node can also be an operator when it has identity and grants.
- [ ] Keep a short "Legacy control terminology" note in node concepts and migration-sensitive command docs.
- [ ] Run: `php tool/docs-linter/docs-linter.php`
- [ ] Commit: `docs: rename control nodes to operator nodes`.

### Phase 3: Rename Command And API Messages

- [ ] Replace user-facing command/API messages such as "This command may only be run from a control node" with operator wording.
- [ ] Rename internal authorization helpers only when the rename improves clarity, for example `authorizeControlCaller` to `authorizeOperatorCaller`.
- [ ] Preserve JSON error codes unless they explicitly contain `control`; error-code churn should be avoided unless the code is wrong.
- [ ] Run narrow tests for the touched command/API families.
- [ ] Commit: `refactor: use operator node command terminology`.

### Phase 4: Rename E2E Topology Concepts

- [ ] Rename topology enum cases from `Control*` to `Operator*`, while accepting old string values as aliases if external selection uses them.
- [ ] Rename harness concepts from `control()` / `controlUser` / `control` checkout to `operator()` / `operatorUser` / `operator` where practical.
- [ ] Rename topology names and feature groups to the operator names in the table above.
- [ ] Rename the Docker topology user and prepared-image references only if the related image build scripts are updated in the same change. Otherwise keep OS user `control` as a documented legacy fixture detail.
- [ ] Run: `ORBIT_E2E=1 php artisan test --testsuite=E2E --group=e2e-topology-contract`
- [ ] Commit: `test(e2e): rename control topology to operator topology`.

### Phase 5: Update Feature And Unit Tests

- [ ] Replace fixtures such as `control-1` with `operator-1` where the test asserts product terminology.
- [ ] Keep `control-1` only in tests explicitly proving backward compatibility.
- [ ] Update assertions for human text, JSON role labels, activity messages, and gateway grant errors.
- [ ] Run narrow tests by modified family, then `php artisan test --compact`.
- [ ] Commit: `test: align operator node expectations`.

### Phase 6: Final Sweep

- [ ] Run `rg "\b[Cc]ontrol( node| caller| role|-)|\bcontrol(_|-)?gateway|ControlGateway|controlUser|control-1" docs app tests docker` and classify remaining hits as either compatibility, historical plan/spec text, or missed rename.
- [ ] Add a compatibility comment beside every intentional remaining `control` use in active app/test code.
- [ ] Run `vendor/bin/pint --dirty --format agent`.
- [ ] Run `composer quality-check`.
- [ ] Commit: `chore: finish operator terminology migration`.

## Open Questions

- Should persisted `nodes.role = control` be migrated to `operator`, or remain as a legacy shadow value until the composable role model removes reliance on the column?
- Should public CLI option `--control-name` gain a new `--operator-name` alias immediately, or wait for a dedicated command-contract change?
- Should E2E Linux users and image names change from `control` to `operator` now, or stay as legacy fixture internals until prepared images are rebuilt?
