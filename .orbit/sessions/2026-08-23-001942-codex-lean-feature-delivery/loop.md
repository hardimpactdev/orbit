# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Session: feat-codex-lean-feature-delivery
- Worktree: /Users/nckrtl/orbit/.worktrees/codex-lean-feature-delivery
- Branch: codex/lean-feature-delivery

## Goal

Orbit's ordinary feature loop is materially faster and simpler while preserving
exact identity, independent review, fail-closed cleanup, resumable LAND, and the
human-only E2E boundary: one persistent implementer owns the clean candidate and
its terminal diff-routed gate, one diff-first Opus-high reviewer owns the review
cycle without repeating proven gates, and every later phase consumes one exact
SHA-bound receipt.

## Scope

- Owned: proof-receipt validation shared by implementation handoff, acceptance,
  finalization, and LAND; SHA-keyed structured handoffs; spawned worker identity;
  reviewer gate tripwire; targeted/acknowledged worker watching; milestone-only
  heartbeat policy; one-implementer/one-reviewer-cycle policy; diff-first
  Opus-high default and risk-triggered xhigh; `DEFECT` versus `POLISH` review
  output; risk-brief invariants/regressions; opt-in worktree baseline tests;
  frame feedback lookup resilience; focused tests and harness docs/personas.
- Constraints: use the existing tmux foundation; one successful terminal gate
  per immutable candidate; any HEAD change invalidates receipt and review;
  implementer owns focused red/green checks and the final `composer
  quality-check` (or docs-only `composer docs-lint`); owner/reviewer/acceptance/
  LAND never repeat it; reviewer may run only a narrow reproduction for a named
  concern; preserve exact checkout/accepted-main identity, append-only evidence,
  deterministic acceptance, cleanup guards, resumability, and manual-only E2E.
- Out of scope: event bus/daemon, reviewer memory across features, semantic
  finding grader, new verification lanes/personas, small-diff gate exemptions,
  hook grammar expansion, product runtime behavior, release/deployment changes,
  and automated E2E execution.

## Proof

- Verification:
  - focused: passed - implementer-owned red/green coverage for proof receipts, handoff/watch/spawn identity, review gate tripwire, acceptance/finalization/LAND receipt consumption, opt-in worktree tests, feedback resilience, and bounded tmux capture
  - broader: passed - exact clean merge candidate `89c99a42b4135bba6fbdd03ea01a4a23b2d9d301` passed implementer-owned `composer quality-check`; evidence `.orbit/quality-gates/quality-check-2026-08-22T221527Z-426ae4308ea8.json`
  - runtime: not applicable
- Blast radius: complete - evidence=Opus-high bounded repository-wide sweeps plus both-parent merge inventory and full intent-ledger set comparison; result=no stale flow contract, dropped parent content, or unresolved affected surface
- Review: passed - reviewer=Claude Opus-high review-1; exact-tip delta review; all four DEFECT findings closed; main-integration conflict resolution verified; human-judgment=not-required; no broad gate rerun
- Reviewed feature tip: 89c99a42b4135bba6fbdd03ea01a4a23b2d9d301
- Acceptance venue: automated
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: 89c99a42b4135bba6fbdd03ea01a4a23b2d9d301
- Accepted main tip: 6ca8b38d5a21ca23a63e9d20cb488ad797b12306

## Status

- State: accepted
- Blocker: none

## Feedback

- Events: `.orbit/feedback.jsonl`
