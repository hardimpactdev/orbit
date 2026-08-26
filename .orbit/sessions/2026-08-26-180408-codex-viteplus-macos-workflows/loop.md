# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Session: feat-codex-viteplus-macos-workflows
- Worktree: /Users/nckrtl/orbit/.worktrees/codex-viteplus-macos-workflows
- Branch: codex/viteplus-macos-workflows

## Goal

The native macOS desktop bundle workflow uses Vite+ for package installation,
Tauri build execution, and updater signing while retaining npm as the
project-selected package manager.

## Scope

- Owned: `apps/macos/package.json`, `bin/orbit-build-desktop-bundle`, and the
  pinned native release contract tests.
- Constraints: use `vp` for generic package and script interactions; retain
  `packageManager: npm@11.17.0`; do not change Tauri behavior or package
  dependencies; do not run human-only E2E lanes.
- Out of scope: browser workflows, Bun project selection, upstream Vite+ or
  Bun behavior, and native app UI changes.

## Slices

| Slice | State | Checkpoint |
| --- | --- | --- |
| `.orbit/slices/01-vp-macos-workflows.md` | complete | 06d8e0be6943df90c8983cc1bd8198fb82b59adc |

## Proof

- Verification:
  - focused: passed - NativeReleaseAssetsBuilderTest.php (13 tests, 82 assertions), Mago format/lint, shell syntax
  - broader: passed - exact clean candidate 06d8e0be6943df90c8983cc1bd8198fb82b59adc passed all 51 `composer quality-check` subgates; artifact `.orbit/quality-gates/quality-check-2026-08-26T160043Z-e04db833ab39.json`; three earlier 50/51 attempts retained the same unrelated CLI filesystem-permission red before the exact run passed
  - runtime: passed - candidate=06d8e0be6943df90c8983cc1bd8198fb82b59adc; venue=host-macos; environment=dev-fixture; command=`vp install --frozen-lockfile --ignore-scripts` and `vp run tauri --version` from `apps/macos`; expected=Vite+ dispatches the npm-selected macOS Tauri project; observed=install completed with npm 11.17.0 and Tauri CLI reported 2.11.4; result=passed; evidence=`.orbit/evidence/viteplus-macos-runtime-proof.md`
- Blast radius: complete - evidence=`.orbit/evidence/viteplus-macos-blast-radius.md`; result=owned macOS workflow has no generic native npm/Bun package or script calls, and only the intended Vite+ commands plus npm project declaration remain
- Review: passed - same Claude general reviewer verified the requested build-command pin on exact candidate 06d8e0be6943df90c8983cc1bd8198fb82b59adc; blast-radius=complete; VERDICT=PASS; human-judgment=not-required; report=`.orbit/workers/reports/feature-review-06d8e0be.md`
- Reviewed feature tip: 06d8e0be6943df90c8983cc1bd8198fb82b59adc
- Acceptance venue: host-macos
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: 06d8e0be6943df90c8983cc1bd8198fb82b59adc
- Accepted main tip: 085822a149f0928716c8d595fcc082d9577fb2fe

## Status

- State: accepted
- Blocker: none

## Feedback

- Events: `.orbit/feedback.jsonl`
