# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: /private/tmp/claude-501/-Users-nckrtl-orbit/9d84cd44-8ab7-472d-94d1-62c9fa19f7c9/scratchpad/brief-217.md
- Worktree: /Users/nckrtl/orbit/.worktrees/solo-hardening-cosmetics-batch
- Branch: solo-hardening-cosmetics-batch

## Goal

Six independent cosmetics fixes land on one branch: SDK surface reason, Codex
docs placeholder, retired UpdateAll filesystem tombstone, Codex config lock
release order, ToolUpdateBulk single-pass target resolution, and redundant
Dockerfile.inputs children.

## Scope

- Owned: `apps/gateway/openapi-sdk-surface.json`; `apps/docs/content/domains/22_codex/1_codex-app/technical/1_codex-app.md`; `packages/sdk/tests/Unit/RetiredUpdateAllDirectStackTest.php`; `apps/cli/app/Services/CodexApp/LocalCodexAppConfigStore.php`; `apps/gateway/app/Http/Controllers/Api/ToolUpdateBulkController.php` plus `ResolvesVisibleToolNodes`; `docker/orbit-gateway/Dockerfile.inputs` and COPY-parity helper
- Constraints: byte-identical ToolUpdateBulk auth/422 outcomes; no PHP SDK UpdateAll* reintroduction; no `composer test:e2e*`; no merge or push
- Out of scope: LAND/merge; live nodes; TypeScript SDK regeneration unless the consumed classification field changes

## Proof

- Verification:
  - focused: passed - per-fix suites green on the pre-merge tip: docs-lint, sdk tombstone 1 passed, cli codex store 12 passed, gateway tool bulk 17 passed, e2e fingerprint 16 passed; full touched-app suites sdk 156, cli 2618, gateway 7070, docs 181 passed
  - broader: passed - `composer quality-check` on clean merged commit 7e6981de1bff09360131d40a7297f20ed4ed91d3 exit 0, 45/45 subgates (`.orbit/quality-gates/quality-check-2026-08-18T191338Z-1f9ef471dc66.json`)
  - runtime: passed - candidate=7e6981de1bff09360131d40a7297f20ed4ed91d3; venue=retained-incus; environment=dev-fixture; target=orbit-e2e-dev-22d631-gateway; expected=exact candidate keeps single-pass tool bulk target resolution with identical authorization and 422 outcomes and atomic codex config lock ordering in the routed retained environment; observed=matching ToolUpdateBulkController sha256 f978af4f85cf with 17 tests passed in the retained gateway instance and 12 codex store tests passed in the retained operator instance; result=passed; evidence=`.orbit/evidence/solo-hardening-cosmetics-batch-retained-incus-runtime.txt`
- Blast radius: complete - evidence=per-fix inventory in the handoff plus full suites of every touched app; result=six independent fixes with no behavior change beyond the documented ones, shared authorizedToolTarget return shape extended with resolved node and sibling controller PHPDocs aligned, TypeScript SDK artifacts confirmed independent of the manifest reason field, COPY-parity test collapses directory-covered children, known pre-existing apps/e2e in-memory suite hang recorded separately
- Review: passed - orchestrator Claude reviewer VERDICT PASS: bulk resolution reuses the already-resolved node with membership check and byte-identical 422 shapes locked by tests, lock release now throws before fclose, tombstone is a filesystem glob catching renamed reintroductions, docs placeholder and manifest prose corrected, Dockerfile.inputs dedup covered by fingerprint test; human-judgment=not-required
- Reviewed feature tip: 7e6981de1bff09360131d40a7297f20ed4ed91d3
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: 7e6981de1bff09360131d40a7297f20ed4ed91d3
- Accepted main tip: a579a4b9004d3e1ede74792285f4eb9e3e809765

## Status

- State: accepted
- Blocker: none

## Feedback

- Events: `.orbit/feedback.jsonl`
