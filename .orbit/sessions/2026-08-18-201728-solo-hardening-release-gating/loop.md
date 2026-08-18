# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: /private/tmp/claude-501/-Users-nckrtl-orbit/9d84cd44-8ab7-472d-94d1-62c9fa19f7c9/scratchpad/brief-212.md
- Worktree: /Users/nckrtl/orbit/.worktrees/solo-hardening-release-gating
- Branch: solo-hardening-release-gating

## Goal

GitHub release visibility is fail-closed on artifact verification: accepted assets stay on a draft version-tagged release until `.github/workflows/orbit-release.yml` verifies the manifest, CLI hashes, websocket archive, and digest-pinned gateway image, then publishes the GitHub release only on success.

## Scope

- Owned: `.github/workflows/orbit-release.yml`, `.agents/skills/release/SKILL.md`, `apps/docs/content/tech-stack.md`, `PRODUCT_DECISIONS.md`, `apps/gateway/tests/Feature/Release/OrbitReleaseWorkflowTest.php`
- Constraints: do not weaken existing verification; do not trigger any workflow run; no `composer test:e2e*`; no push or merge
- Out of scope: GHCR tag rollback, TypeScript SDK `publish.yml`, live release execution, split-repo npm bootstrap

## Proof

- Verification:
  - focused: passed - `bin/orbit-gateway-pest --compact tests/Feature/Release/OrbitReleaseWorkflowTest.php` 22 passed 310 assertions on the R2 contract; red-first pins for workflow_dispatch and tag-before-draft ordering
  - broader: passed - `composer quality-check` on clean merged commit 2363a860f1d39ca738d3a9a13dd713810f3a9766 exit 0, 45/45 subgates (`.orbit/quality-gates/quality-check-2026-08-18T181653Z-30fcdd0a0310.json`)
  - runtime: not applicable
- Blast radius: complete - evidence=workflow trigger inventory plus repository-wide search for release entry points; result=only orbit-release.yml, the release skill, tech-stack docs, ledger, and the workflow contract test changed, no push or tags trigger exists so the new tag-push step starts nothing, release: published retained as verified safety net with failure rollback to draft, GHCR version-tag rollback explicitly out of scope
- Review: passed - orchestrator Claude reviewer VERDICT PASS: draft-first via workflow_dispatch with correct GitHub Actions semantics, reviewer-found draft-tag checkout bug fixed by tag-push-before-draft with rationale pinned in tests, publish step idempotent and failure path converts back to draft, concurrency group prevents dispatch and published racing; human-judgment=not-required
- Reviewed feature tip: 2363a860f1d39ca738d3a9a13dd713810f3a9766
- Acceptance venue: automated
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: 2363a860f1d39ca738d3a9a13dd713810f3a9766
- Accepted main tip: 1dc825202b98e1ae0609a1122933a5f453dbcd26

## Status

- State: accepted
- Blocker: none

## Feedback

- Events: `.orbit/feedback.jsonl`
