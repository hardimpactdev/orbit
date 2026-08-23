# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Session: feat-fix-worktree-dependency-bootstrap
- Worktree: /Users/nckrtl/orbit/.worktrees/fix-worktree-dependency-bootstrap
- Branch: fix-worktree-dependency-bootstrap

## Goal

Prepared feature worktrees cannot silently inherit stale pre-tmux authority and always contain installed Composer dependencies for the root plus every tracked app and package Composer project.

## Scope

- Owned: `bin/orbit-prepare-worktree`, `bin/orbit-prepare-worktree-test`, active feature-flow authority and its architecture tests
- Constraints: Keep preparation fast; use local refs only; preserve the optional frontend build; fail before creating a worktree when default local `main` differs from `origin/main`; prove every tracked one-level app/package Composer manifest is installed.
- Out of scope: Orbit's separate product extension and its commands, historical archives/fixtures, automatic fetch/pull, Beast dirty-file cleanup, frontend dependency policy, E2E execution.

## Proof

- Verification:
  - focused: passed - `bin/orbit-prepare-worktree-test`, root and per-app autoload mutation checks, shell syntax, and focused gateway architecture/worker tests passed
  - broader: passed - `composer quality-check` exit 0 on clean exact candidate c7de10543b76816d94db84682207e6142c823a99; artifact `.orbit/quality-gates/quality-check-2026-08-23T093332Z-51841e298d8c.json`
  - runtime: not applicable
- Blast radius: complete - evidence=bounded repository-wide active feature-flow authority sweep plus tracked one-level Composer manifest inventory; result=no obsolete workflow name remains in active feature authority, separate product-extension and historical records are unchanged, and root plus every tracked app/package Composer install is covered
- Review: passed - resumed Claude Opus-high reviewer closed all three proof/diagnostic findings, confirmed the rebased full diff and final correction delta, and reported no actionable finding; human-judgment=not-required
- Reviewed feature tip: c7de10543b76816d94db84682207e6142c823a99
- Acceptance venue: automated
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: c7de10543b76816d94db84682207e6142c823a99
- Accepted main tip: 20b35a21a25aa40274102b3a84c8fa0f3cb91ba8

## Status

- State: accepted
- Blocker: none

## Feedback

- Events: `.orbit/feedback.jsonl`
