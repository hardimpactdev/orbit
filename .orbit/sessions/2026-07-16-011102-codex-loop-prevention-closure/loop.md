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
  - focused: passed - original closure 281 combined tests / 1,529 assertions plus isolated CLI 2,254 tests / 9,359 assertions; exact follow-up archive suite 82 tests / 454 assertions; reviewer identity subset 6 tests / 25 assertions; hook, PHP syntax, and diff checks passed
  - broader: passed - `composer quality-check` passed all 43 subgates at exact reviewed commit; artifact `.orbit/quality-gates/quality-check-2026-07-15T230932Z-af8f4a059113.json`
  - runtime: not applicable - current main-to-tip follow-up changes only the repository-local archive parser and its fixture; earlier closure candidate cc3dc30c918ff06f63521c1cb45f35d95d677bb8 passed retained operator proof recorded at `.orbit/evidence/retained-topology-loop-prevention-dev-4e9725.txt`
- Review: passed - independent general reviewer loop_machinery_audit follow-up - human-judgment=not-required
- Blast radius: not-required - follow-up changes only exact inline-code normalization in the compact archive identity parser and its production-shaped regression; the full loop-prevention closure is already landed on current main
- Reviewed feature tip: fc21e4c76b73ba5498218ebe263af91ade53be9f
- Acceptance venue: automated
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: fc21e4c76b73ba5498218ebe263af91ade53be9f
- Accepted main tip: 0c91924c0f4c20cf7c2b7d6693ec03242455531b

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
