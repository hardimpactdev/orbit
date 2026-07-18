# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: none
- Worktree: /Users/nckrtl/orbit/.worktrees/codex-runtime-proof-boundary
- Branch: codex/runtime-proof-boundary

## Goal

When a feature Goal claims runtime reachability or convergence, Orbit's feature
review contract requires direct proof of the claimed final outcome and rejects
`Verification.runtime: passed` when the final hop failed or was excluded.

## Scope

- Owned: `HARNESS.md`, `.agents/skills/implementing-features/SKILL.md`, `.agents/review-personas/general.md`, and the focused architecture contract in `apps/gateway/tests/Feature/Architecture/McpConfigurationTest.php`.
- Constraints: keep one general reviewer and the existing proof venues; add no semantic parser, analyzer, lane, or product-behavior change; never run `composer test:e2e*`.
- Out of scope: automatically correlating free-form Goal text with evidence, changing acceptance venue derivation, runtime implementation changes, and unrelated loop cleanup.

## Proof

- Verification:
  - focused: passed - red/green runtime proof-boundary contract; 26 architecture tests / 287 assertions; docs lint; Mago format; PHP syntax; git diff check
  - broader: passed - `ORBIT_QUALITY_CHECK_CPU_BUDGET=2 composer quality-check` at f9f7a62d91daed8944a584ab66ca0e2b4a40a2a0; every subgate exited 0 on the warm-cache rerun; isolated core Pest passed 129 tests / 538 assertions after the first run reached the 13-minute gate timeout; final-check exited 0 with warning-only constrained-CPU timing; evidence `.orbit/quality-gates/quality-check-2026-07-18T080242Z-c67212a02d97.json`
  - runtime: not applicable - harness and reviewer contract only
- Blast radius: complete - evidence=bounded repository-wide search found no conflicting active feature-loop contract; result=the rule is consistently routed through HARNESS PROVE, implementing-features PROVE, and the general reviewer with focused Pest coverage and no parser, acceptance derivation, implementation, or reviewer lane added
- Review: passed - no actionable findings; human-judgment=not-required
- Reviewed feature tip: f9f7a62d91daed8944a584ab66ca0e2b4a40a2a0
- Acceptance venue: automated
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: f9f7a62d91daed8944a584ab66ca0e2b4a40a2a0
- Accepted main tip: 8b950b8433928788ea8fdf992e9e6a8a52a49989

## Status

- State: accepted
- Blocker: none

## Feedback

- Events: `.orbit/feedback.jsonl`

Allowed states are `frame`, `build`, `prove`, `accept`, `accepted`, `land`, and
`blocked`. Review must be `passed`, `fix`, or `escalate`. Acceptance must be
`pending`, `accepted`, `not-applicable`, or `changes-requested`. A terminal
review PASS must record `human-judgment=required|not-required` and the exact
committed HEAD in `Reviewed feature tip`. Blast radius must be either a stated
not-required reason or complete repository-wide evidence and result before
acceptance; `gaps` returns to BUILD.
Proof files retained by the compact archive must be cited as one exact
inline-code path; prose, directories, padded code spans, and partial paths are
not proof citations.
