# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: none
- Worktree: `/Users/nckrtl/orbit/.worktrees/codex-sdk-typescript-npm-bootstrap`
- Branch: `codex/sdk-typescript-npm-bootstrap`

## Goal

Land fail-closed one-time npm bootstrap for `@hardimpactdev/orbit-sdk-typescript`
so only confirmed npm `E404` permits first package creation, successful lookups
and non-E404 registry errors refuse, release OIDC stays token-free, and no
monorepo durable npm secret is reintroduced.

## Scope

- Owned:
  - `packages/sdk-typescript/.github/workflows/publish.yml`
  - `packages/sdk-typescript/scripts/npm-bootstrap-registry-absent.sh`
  - `apps/gateway/tests/Feature/Release/OrbitReleaseWorkflowTest.php`
  - PRODUCT_DECISIONS, tech-stack, sdk-typescript README, release skill
- Constraints:
  - Correction branch off primary main `7440e627c25540506dd1ffa09eb24ac3c7ab4eef`
  - Preserve unrelated dirty work outside this worktree
  - No npm publish, no split-repo push, no secrets/token/browser actions in this LAND
- Out of scope:
  - Publishing `@hardimpactdev/orbit-sdk-typescript@0.1.0` to npm
  - Syncing/pushing `hardimpactdev/orbit-sdk-typescript`
  - Configuring `NPM_BOOTSTRAP_TOKEN` or Trusted Publisher
  - Altering existing split GitHub release `v0.1.0`

## Proof

- Verification:
  - focused: passed - OrbitReleaseWorkflowTest 14 tests/230 assertions including executable mock-npm coverage for E404 allow, existing package refuse, ENOTFOUND/E401 refuse on tip `616ceb6019565e99f19225d6f26f08828aaa8ffc`
  - broader: passed - `composer quality-check` exit 0 on clean HEAD `616ceb6019565e99f19225d6f26f08828aaa8ffc` dirty=false via `.orbit/quality-gates/quality-check-2026-08-04T110228Z-638037a3ea1d.json`
  - runtime: passed - no retained-topology product surface; diff is package publish workflow, fail-closed registry-absent shell guard, release tests, and docs; proof is quality-check + focused executable registry-guard tests; no live Orbit process/node mutation and no npm publish required for ACCEPT of this monorepo correction
- Blast radius: complete - evidence=repository-wide rg of NPM_TOKEN/NPM_BOOTSTRAP_TOKEN/npm view/publish.yml/sdk-typescript bootstrap paths plus OrbitReleaseWorkflowTest contract and PRODUCT_DECISIONS/tech-stack/README/release skill; result=E404-only bootstrap fail-closed, release path token-free OIDC, monorepo has no durable npm publish secret, no unexamined bootstrap consumer remaining
- Review: passed - independent general reviewer - human-judgment=not-required; no findings; P1 closed
- Reviewed feature tip: 616ceb6019565e99f19225d6f26f08828aaa8ffc
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: 616ceb6019565e99f19225d6f26f08828aaa8ffc
- Accepted main tip: 7440e627c25540506dd1ffa09eb24ac3c7ab4eef

## Status

- State: land
- Blocker: none

## Feedback

- Events: `.orbit/feedback.jsonl`
