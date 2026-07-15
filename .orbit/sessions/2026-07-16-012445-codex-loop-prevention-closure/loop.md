# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: `/Users/nckrtl/shared-knowledge/projects/orbit/superpowers/specs/2026-07-15-loop-prevention-closure-design.md`
- Worktree: `/Users/nckrtl/orbit/.worktrees/codex-loop-prevention-closure`
- Branch: `codex/loop-prevention-closure`

## Goal

Make the existing single-reviewer feature loop prevent missing affected surfaces through one conditional blast-radius closure check, while making compact session evidence and the canonical session index trustworthy.

## Scope

- Owned: compact loop contract/template/instructions, general reviewer contract, feature acceptance/finalization checks, compact archive evidence retention, session-index archive discovery, focused regression tests, and generated session index.
- Constraints: preserve the existing loop phases and one-reviewer default; add no standing analyzer/reviewer; keep local changes cheap; never run agent-triggered `composer test:e2e*`; preserve unrelated main changes.
- Out of scope: dashboards, routine aggregate loop review, generic policy engines, retroactive reconstruction of already-deleted proof artifacts, and product runtime behavior.

## Proof

- Verification:
  - focused: passed - original closure 281 combined tests / 1,529 assertions plus isolated CLI 2,254 tests / 9,359 assertions; final archive and cleanup suites 210 tests / 772 assertions; final reviewer parser matrix 9 tests / 32 assertions; hook, PHP syntax, and diff checks passed
  - broader: passed - `composer quality-check` passed all 43 subgates at exact reviewed commit; artifact `.orbit/quality-gates/quality-check-2026-07-15T232332Z-d3f48ff5ee64.json`
  - runtime: not applicable - current main-to-tip follow-up changes only the repository-local archive parser and its fixture; earlier closure candidate cc3dc30c918ff06f63521c1cb45f35d95d677bb8 passed retained operator proof recorded at `.orbit/evidence/retained-topology-loop-prevention-dev-4e9725.txt`
- Review: passed - independent general reviewer loop_machinery_audit final follow-up - human-judgment=not-required
- Blast radius: not-required - final follow-up centralizes exact top-label normalization for archive and cleanup identity checks, rejects multiline labels, and changes only repository-local loop tooling plus regressions
- Reviewed feature tip: 7c0d7e89b11cb335104e336f44814bab4bd8e22d
- Acceptance venue: automated
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: 7c0d7e89b11cb335104e336f44814bab4bd8e22d
- Accepted main tip: 26f55b7d6a3fde7b75c63dfaae1acbd967ba203b

## Status

- State: accepted
- Blocker: none

## Feedback

- Events: `.orbit/feedback.jsonl`

Allowed states are `frame`, `build`, `prove`, `accept`, `accepted`, `land`, and
`blocked`. Review must be `passed`, `fix`, or `escalate`. Acceptance must be
`pending`, `accepted`, `not-applicable`, or `changes-requested`. A terminal
review PASS must record `human-judgment=required|not-required` and the exact
committed HEAD in `Reviewed feature tip`.
