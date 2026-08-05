# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: solo://proj/4/scratchpad/orbit-feature-loop-e--341
- Worktree: /Users/nckrtl/orbit/.worktrees/codex-loop-runtime-proof-integrity
- Branch: codex/loop-runtime-proof-integrity

## Goal

ACCEPT and finalization fail closed when a non-automated `Verification.runtime`
row claims `passed` while the decisive final hop failed, was excluded, remains
required, or is deferred; require a candidate-bound structured runtime receipt
inside the existing row.

## Scope

- Owned: `bin/orbit-loop-contract.php`, acceptance/finalization callers and
  fixtures, `FeatureAcceptanceTest.php`, `FeatureFinalizationGateTest.php`,
  `apps/docs/content/testing/quality-gates.md` bin/ topology drift, and aligned
  HARNESS/skill/LOOP/reviewer wording for the shared runtime contract
- Constraints: Slice 1 only; no parallel receipt/lane; no `composer test:e2e*`;
  historical archives remain readable; automated venue keeps runtime not-
  applicable behavior; minimize diff
- Out of scope: Slices 2–8 (PROVE-only repair lane, venue routing expansion,
  release-evidence archive, LAND atomicity, index identity, efficiency
  experiment)

## Proof

- Verification:
  - focused: passed - Fable R3 path on acec08120; EOF whitespace fix f5245f222
    focused filter 62 passed (358 assertions); `git diff main...HEAD --check`
    clean
  - broader: passed - `composer quality-check` on clean HEAD
    `f5245f222caedb4a51bd866ef9f644dbeaec8c09` exit 0; artifact
    `.orbit/quality-gates/quality-check-2026-08-05T080314Z-79f48dc14dd1.json`
  - runtime: not applicable - repository tooling / harness contract only
- Blast radius: complete - evidence=Fable bounded runtime caller, proof-reference consumer, and runtime producer sweep; result=no affected surface unresolved
- Review: passed - fable-loop-proof-review--1391 - human-judgment=not-required
- Reviewed feature tip: f5245f222caedb4a51bd866ef9f644dbeaec8c09
- Acceptance venue: automated
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: f5245f222caedb4a51bd866ef9f644dbeaec8c09
- Accepted main tip: be3ea58c20a1e4debe79a68e1488354d9f8d3f95

## Status

- State: land
- Blocker: none

## Feedback

- Events: `.orbit/feedback.jsonl`

Allowed states are `frame`, `build`, `prove`, `accept`, `accepted`, `land`, and
`blocked`. Review must be `passed`, `fix`, or `escalate`. Acceptance must be
`pending`, `accepted`, `not-applicable`, or `changes-requested`. A terminal
review PASS must record `human-judgment=required|not-required` and the exact
committed HEAD in `Reviewed feature tip`. Blast radius must be `not-required`
with a reason, or `complete` with evidence and result fields, before
acceptance; `gaps` returns to BUILD. Proof files retained by the compact
archive must be cited as one exact inline-code path, for example a real file
under the worktree evidence tree; prose, directories, padded code spans, and
partial paths are not proof citations.
