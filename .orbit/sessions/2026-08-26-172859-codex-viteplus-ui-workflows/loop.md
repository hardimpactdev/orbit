# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Session: feat-codex-viteplus-ui-workflows
- Worktree: /Users/nckrtl/orbit/.worktrees/codex-viteplus-ui-workflows
- Branch: codex/viteplus-ui-workflows

## Goal

All generic JavaScript dependency, script, and executable interactions in
`apps/ui` use `vp`, while the existing explicit `bun@1.3.14` project declaration
continues to select Bun for package installation.

## Scope

- Owned: `apps/ui/**` command references, browser build runner, aligned tests,
  and UI project guidance. primitive=UI package workflow; transitions=success:vp
  dispatches installs scripts and dlx|failure:browser build fails visibly|retry:vp
  install/build are repeatable|stop-restart:dev process restarts through existing
  Orbit process ownership|stale:docs/tests expose native generic interactions
- Constraints: keep `packageManager: bun@1.3.14`; do not replace Bun-specific
  runtime APIs; use `vp run` for scripts and `vp dlx` for one-off executables;
  preserve Orbit-managed Git hooks.
- Out of scope: `apps/macos/**`, root/server workflows already landed, visual UI
  changes, dependency upgrades, and human-only E2E lanes.

## Slices

| Slice | State | Checkpoint |
| --- | --- | --- |
| `.orbit/slices/01-vp-ui-workflows.md` | complete | f35edb321674bb19cabf5ea1acc3d98d48304d2c |

## Proof

- Verification:
  - focused: passed - AgentDocs 23 tests / 85 assertions, Pint, and `git diff --check` at f35edb321674bb19cabf5ea1acc3d98d48304d2c
  - broader: passed - `composer quality-check` on exact clean candidate f35edb321674bb19cabf5ea1acc3d98d48304d2c; all 51 subgates passed; artifact `.orbit/quality-gates/quality-check-2026-08-26T152431Z-73ba26432dc6.json`
  - runtime: passed - candidate=f35edb321674bb19cabf5ea1acc3d98d48304d2c; venue=browser; environment=dev-fixture; target=http://127.0.0.1:18731/; expected=exact candidate serves the complete Launch UI after assets are installed and built through vp; observed=HTTP 200 with title Ship your next idea in minutes - Orbit and primary heading Launch your next idea faster than ever_; result=passed; evidence=`.orbit/evidence/viteplus-ui-browser-proof.md`
- Blast radius: complete - evidence=reviewer sweep of all `apps/ui` package, script, executable, documentation, and hidden-skill surfaces plus regression guards; result=no generic native Bun/npm interaction remains outside regression guards
- Review: passed - same Claude general reviewer closed both FIX rounds on exact candidate f35edb321674bb19cabf5ea1acc3d98d48304d2c; VERDICT=PASS; human-judgment=not-required; report=`.orbit/workers/reports/feature-review-f35edb32.md`
- Reviewed feature tip: f35edb321674bb19cabf5ea1acc3d98d48304d2c
- Acceptance venue: browser
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: f35edb321674bb19cabf5ea1acc3d98d48304d2c
- Accepted main tip: bcbebb6392a92811934858a3b0dee5bf9950ebb5

## Status

- State: accepted
- Blocker: none

## Feedback

- Events: `.orbit/feedback.jsonl`
