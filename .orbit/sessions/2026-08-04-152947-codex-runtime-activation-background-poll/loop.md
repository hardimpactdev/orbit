# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: none (approved bug-fix brief)
- Worktree: `/Users/nckrtl/orbit/.worktrees/codex-runtime-activation-background-poll`
- Branch: `codex/runtime-activation-background-poll`

## Goal

A hibernated app/workspace boot page stays mounted while activation is pending:
wait five seconds, then non-overlapping same-origin background fetch probes of
the original path/query, and perform exactly one browser navigation when the
response is no longer an Orbit pending response (no full-document refresh while
waking). Soft and cold remain identical externally; failed activation remains
terminal with the existing retry experience.

## Scope

- Owned:
  - `apps/gateway` runtime-activation boot page (Blade, `RuntimeActivationPage`,
    headers, CSP, inline poll script)
  - focused gateway Pest coverage and shared boot-screen helper
  - `PRODUCT_DECISIONS.md` (2026-08-04 superseding document meta-refresh)
  - architecture / tech-stack / proxy domain docs for wake presentation
- Constraints:
  - no new public stream/operation endpoint; no grants/auth change
  - keep Caddy serving-node WireGuard pre-check
  - restrictive CSP authorizing only the minimal same-origin fetch script
  - Spatie JS: const/let only (no `var`); fetch uses `redirect: 'manual'`
  - preserve exact approved Orbit mark presentation (no new copy/diagnostics/
    progress UI/redesign/soft-cold distinction)
  - ordinary browser GET-only scope
  - never run `composer test:e2e*`
  - do not merge, archive, clean up worktree, push, or deploy until LAND
- Out of scope:
  - automated visual tests; Caddy module changes; activation runner/plan semantics
  - soft vs cold backend split changes

## Proof

- Verification:
  - focused: passed - soft/cold suites 48/729 on merge tip `.orbit/evidence/runtime-activation-post-main-merge-suite.txt`
  - broader: passed - `.orbit/quality-gates/quality-check-2026-08-04T132436Z-1724ad1c053c.json` exit 0 dirty=false tip eb30aa9ce5dfc2bd00c9cb783b9f241d26a3b7f1
  - runtime: passed - browser venue evidence `.orbit/evidence/runtime-activation-background-poll-browser.md` carried after main merge with activation blobs unchanged (`.orbit/evidence/runtime-activation-post-main-merge-blobs.txt` ACTIVATION_BLOBS_UNCHANGED_BY_MAIN_MERGE=yes)
- Feature tip after main merge: eb30aa9ce5dfc2bd00c9cb783b9f241d26a3b7f1 (parents ac766f4be2a3 + main eec2d757646f)
- Blast radius: complete - evidence=repository-wide activation surface (page, headers, CSP, soft/cold Pest, PRODUCT_DECISIONS/architecture/tech-stack/proxy docs) and post-main-merge blob identity check; result=local activation presentation change with docs/tests/code aligned; no residual findings
- Review: passed - human-judgment=not-required
- Reviewed feature tip: eb30aa9ce5dfc2bd00c9cb783b9f241d26a3b7f1
- Acceptance venue: browser
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: eb30aa9ce5dfc2bd00c9cb783b9f241d26a3b7f1
- Accepted main tip: eec2d757646f73c112fada2cf4ca363deb50aac3

## Status

- State: land
- Blocker: none

## Feedback

- Events: `.orbit/feedback.jsonl`
