# Layered E2E Live Topology Workflow Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Keep Orbit development fast by preserving source-checkout E2E as the normal lane, adding retained prepared topologies for manual SSH/debug iteration, and reserving binary E2E for release-candidate acceptance after source E2E passes.

**Architecture:** The dedicated `apps/e2e` runner owns external E2E execution. Prepared topology support is extracted out of the gateway harness into `apps/e2e`, while gateway product behavior stays in `apps/gateway`. Retained topology acquisition/release uses the same extracted prepared-topology support as normal source-checkout E2E, writes an `apps/e2e` manifest, keeps provider capacity leased until explicit release/reap, and never adds new gateway-owned runner commands.

**Tech Stack:** `apps/e2e`, Pest 4, extracted prepared-topology support, Docker/Incus prepared topology providers, root Composer scripts, `composer docs-lint`, `composer test:e2e:next` until the root E2E scripts flip.

---

## Status And Ownership

This is a supporting plan for the pre-S3 E2E extraction chain:

- Primary plan: `docs/superpowers/plans/2026-05-29-pre-s3-cli-e2e-stabilization.md`
- Main implementation todo: `#558` ORBIT-PRE-S3-09C
- Final docs/repointing todo: `#557` ORBIT-PRE-S3-09I
- Aggregate E2E gate: `#549`

This plan replaces the earlier gateway-centric sketch. Do not implement retained topology commands in `apps/gateway/app/Console/Commands`. Gateway Artisan is not the public E2E runner and should not gain new public or hidden dev-topology runner surface as part of this work.

## Lane Contract

- Source-checkout lane: `composer test:e2e`, `composer test:e2e:docker`, and `composer test:e2e:incus` keep using current-checkout overlay. They do not build a binary.
- Retained topology lane: `apps/e2e` acquires a prepared topology, overlays the current checkout on selected roles, writes a manifest, prints provider-aware shell commands, and keeps the topology until an explicit release or reap.
- Codification path: manual findings from a retained topology become ordinary prepared-topology Pest tests under `apps/e2e`.
- Binary candidate lane: after source-checkout E2E passes, a downstream binary plan builds the CLI once and runs targeted binary acceptance against the built artifact.
- Installer/provision release lane: after binary candidate acceptance, `install-orbit`/provisioning E2E proves the downloaded or linked binary path and node provisioning behavior.

## Boundaries

- Do not move gateway product services, Eloquent models, controllers, jobs, or internal command behavior to `packages/core` for E2E convenience.
- Do not import gateway internals as the test subject from `apps/e2e`. The E2E runner drives CLI, API, SSH, Docker, Incus, and process boundaries externally.
- Do not add compatibility fallback language or commands for old gateway public command surfaces.
- Do not make binary E2E the normal feature feedback loop.
- Do not treat retained topologies as standing live infrastructure; they are disposable prepared clones and must be released or reaped.

## Files

Expected implementation files after `#550` and `#551` establish the `apps/e2e` structure:

- Modify/create: `apps/e2e/app/E2E/Support/**`
- Modify/create: `apps/e2e/tests/Feature/E2ESupport/**`
- Modify/create: `apps/e2e/tests/Feature/E2ESupport/Commands/**`
- Modify: root `composer.json`
- Modify/create: `apps/e2e/composer.json`
- Modify as needed: `apps/docs/content/testing/README.md`
- Modify as needed: `apps/docs/content/testing/e2e/**`
- Create/update: `docs/superpowers/notes/future-apps-e2e-migration-2026-05-29.md`

If `#550` creates a different support namespace or test directory, use that concrete `apps/e2e` structure and update this plan reference in `#558` before implementation continues.

## Task 1: Document The Layered E2E Contract

Solo coverage: `#540` for high-level docs, `#557` for final E2E split docs.

**Files:**

- Modify: `apps/docs/content/testing/README.md`
- Modify: `apps/docs/content/testing/e2e/README.md`
- Modify: `apps/docs/content/testing/e2e/prepared-topologies.md`
- Modify: `apps/docs/content/testing/e2e/provisioning.md`
- Create/update: `docs/superpowers/notes/future-apps-e2e-migration-2026-05-29.md`

- [ ] Update the testing overview to describe these workflow layers:
  - in-memory Pest tests;
  - prepared-topology source E2E with current-checkout overlay;
  - retained prepared topologies for manual diagnosis;
  - release-candidate binary acceptance;
  - provisioning and installer acceptance.
- [ ] State that retained topology commands are provided by `apps/e2e`, not by new gateway Artisan runner commands.
- [ ] State that manual retained-topology findings must be codified back into prepared-topology Pest E2E tests.
- [ ] State that binary acceptance runs after source-checkout E2E passes and does not replace the source-checkout feature lane.
- [ ] Run:

```bash
composer docs-lint
```

Expected: PASS.

## Task 2: Add Retained Lease Support In Apps/E2E

Solo coverage: `#558`.

**Files:**

- Modify/create: `apps/e2e/app/E2E/Support/E2EResourceLease.php`
- Modify/create: `apps/e2e/app/E2E/Support/E2EResourceLeasePool.php`
- Modify/create: `apps/e2e/tests/Feature/E2ESupport/E2EResourceLeasePoolTest.php`

- [ ] Add retained lease coverage that proves a retained lease is not reclaimed by dead-process or stale-time cleanup.
- [ ] Expose lease metadata needed by manifests: backend, host, slot, path, and owner.
- [ ] Add `retain(string $owner): self` or equivalent API in the extracted `apps/e2e` lease object.
- [ ] Persist retained lease payload fields:
  - `owner`
  - `pid: null`
  - `retained: true`
  - `retained_at`
- [ ] Ensure `release()` still removes retained lease files explicitly.
- [ ] Run focused `apps/e2e` tests for retained lease behavior.

Expected: focused retained lease tests pass.

## Task 3: Add The Retained Topology Manifest Store

Solo coverage: `#558`.

**Files:**

- Create: `apps/e2e/app/E2E/Support/E2EDevTopologyManifest.php`
- Create: `apps/e2e/tests/Feature/E2ESupport/E2EDevTopologyManifestTest.php`

- [ ] Store retained topology manifests under the `apps/e2e` storage path, not gateway storage.
- [ ] Support `write(array $payload)`, `read(string $id): ?array`, `list(): array`, and `remove(string $id): void`.
- [ ] Include these payload fields:
  - `id`
  - `provider`
  - `host`
  - `kind`
  - `created_at`
  - `checkout_roles`
  - `checkouts`
  - `instances`
  - `cleanup_commands`
  - `leases`
  - `release_command`
- [ ] Sanitize manifest ids before turning them into filenames.
- [ ] Run focused `apps/e2e` manifest tests.

Expected: manifest tests pass.

## Task 4: Expose Resource Lease Metadata From Topology Leases

Solo coverage: `#558`.

**Files:**

- Modify/create: `apps/e2e/app/E2E/Support/E2ETopologyLease.php`
- Modify/create: `apps/e2e/tests/Feature/E2ESupport/E2EPestHelpersTest.php`

- [ ] Add a `resourceLeases(): array` API, or equivalent, to the extracted topology lease.
- [ ] Return a flat list for both single resource leases and lease sets.
- [ ] Keep the API read-only; release lifecycle remains owned by the topology/retained workflow.
- [ ] Run focused `apps/e2e` topology lease metadata tests.

Expected: topology lease metadata tests pass.

## Task 5: Add Retained Topology Acquire Through Apps/E2E

Solo coverage: `#558`.

**Files:**

- Create/modify: `apps/e2e/app/Console/Commands/E2EDevTopologyCommand.php`
- Create/modify: `apps/e2e/tests/Feature/E2ESupport/Commands/E2EDevTopologyCommandTest.php`
- Modify: root `composer.json`
- Modify/create: `apps/e2e/composer.json`

- [ ] Add an `apps/e2e` command for retained topology acquisition. Use `e2e:dev-topology` unless `#550` establishes a different command namespace.
- [ ] Add an `apps/e2e` Composer script that runs the local E2E command:

```json
"e2e:dev-topology": [
    "php artisan e2e:dev-topology @additional_args"
]
```

- [ ] Add root Composer entry:

```json
"e2e:dev-topology": [
    "set -a; [ ! -f .env.e2e ] || . ./.env.e2e; set +a; composer --working-dir=apps/e2e e2e:dev-topology @additional_args"
]
```

- [ ] Add dry-run coverage that does not acquire real providers and returns:
  - `dry_run: true`
  - `kind`
  - `checkout_roles`
  - `release_command`
- [ ] Add validation coverage for unsupported topology kinds.
- [ ] For non-dry-run mode, acquire the prepared topology through extracted `apps/e2e` support, overlay current checkout on selected roles, retain resource leases, write a manifest, and render shell commands.
- [ ] Do not call `bin/orbit-gateway-artisan` or `php apps/gateway/artisan` from the root Composer dev-topology alias.
- [ ] Run focused `apps/e2e` command tests.

Expected: acquire command tests pass.

## Task 6: Add Retained Topology Release Through Apps/E2E

Solo coverage: `#558`.

**Files:**

- Create/modify: `apps/e2e/app/Console/Commands/E2EDevTopologyReleaseCommand.php`
- Create/modify: `apps/e2e/tests/Feature/E2ESupport/Commands/E2EDevTopologyReleaseCommandTest.php`
- Modify: root `composer.json`
- Modify/create: `apps/e2e/composer.json`

- [ ] Add an `apps/e2e` command for retained topology release. Use `e2e:dev-topology:release` unless `#550` establishes a different command namespace.
- [ ] Add an `apps/e2e` Composer script that runs the local E2E command:

```json
"e2e:dev-topology:release": [
    "php artisan e2e:dev-topology:release @additional_args"
]
```

- [ ] Add root Composer entry:

```json
"e2e:dev-topology:release": [
    "set -a; [ ! -f .env.e2e ] || . ./.env.e2e; set +a; composer --working-dir=apps/e2e e2e:dev-topology:release @additional_args"
]
```

- [ ] Fail clearly when a manifest id does not exist.
- [ ] Execute manifest cleanup commands with a bounded timeout.
- [ ] Release every retained lease from the manifest.
- [ ] Remove the manifest after cleanup succeeds.
- [ ] Return JSON and human output with stable success/error envelopes.
- [ ] Run focused `apps/e2e` release command tests.

Expected: release command tests pass.

## Task 7: Wire The Workflow Into Final E2E Docs And Gates

Solo coverage: `#557`, `#549`, `#547`, `#539`, `#536`.

**Files:**

- Modify: `apps/docs/content/testing/README.md`
- Modify: `apps/docs/content/testing/e2e/**`
- Update: `docs/superpowers/notes/future-apps-e2e-migration-2026-05-29.md`
- Update as needed: S3/RustFS Solo todos that mention old gateway E2E paths

- [ ] Document retained topology acquisition/release as an `apps/e2e` workflow.
- [ ] Document that retained topologies consume provider capacity until released or reaped.
- [ ] Document that retained topology debugging is not a replacement for Pest E2E assertions.
- [ ] Confirm S3/RustFS E2E todos target `apps/e2e`.
- [ ] Confirm binary docs do not imply all E2E runs consume a built binary.
- [ ] Run:

```bash
composer docs-lint
composer test:e2e
```

Expected: PASS, or provider-specific blockers are recorded with exact environment details.

## Verification Summary

Before closing `#558`, the implementer must report:

- Focused retained lease tests: PASS
- Focused manifest tests: PASS
- Focused dev-topology acquire tests: PASS
- Focused dev-topology release tests: PASS
- `composer test:e2e:next`: PASS, if still present
- Formatter/static analysis for changed `apps/e2e` PHP files: PASS
- `composer docs-lint`: PASS, if docs changed

Before closing `#549`, the orchestrator must confirm:

- retained topology workflow is owned by `apps/e2e`;
- no new `apps/gateway/app/Console/Commands/E2EDevTopology*` runner commands were added;
- root `composer test:e2e` runs through `apps/e2e`;
- S3/RustFS todos target `apps/e2e`;
- source-checkout E2E remains the normal feature loop and binary acceptance remains a later release-candidate lane.

## Open Questions

- Initial binary acceptance scope belongs to the downstream CLI binary plan and should not be decided in `#558`.
