# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: solo://proj/4/scratchpad/orbit-feature-loop-e--341
- Worktree: /Users/nckrtl/orbit/.worktrees/codex-loop-prove-repair-transition
- Branch: codex/loop-prove-repair-transition

## Goal

Failed terminal proof stays in PROVE and follows the existing FIX -> BUILD -> PROVE repair cycle before acceptance can arm.

## Scope

- Owned: acceptance transition tooling/tests and aligned loop/harness/skill documentation as required by the diff.
- Constraints: no new lane or proof artifact; preserve fail-closed acceptance and exact candidate/reviewer identity; no E2E commands; no unrelated changes.
- Out of scope: proof venue routing, archive identity, LAND atomicity, broad redesign.

## Proof

- Verification:
  - focused: passed - FeatureAcceptanceTest.php after main merge: 109 passed (560 assertions) on 6c79e106d90c60f681b8f2e67fa0147a8da74b77
  - broader: passed - `.orbit/quality-gates/quality-check-2026-08-05T085314Z-64db4e06915d.json` (45/45 subgates; candidate 6c79e106d90c60f681b8f2e67fa0147a8da74b77; dirty=false); `git diff main...HEAD --check` clean
  - runtime: not applicable
- Blast radius: complete - evidence=repo-wide orbitLoopRuntimeProofProblem call-site and FIX -> BUILD -> PROVE phrasing sweep plus merged docs-automation venue interaction inspection; result=all consumers and vocabulary aligned, candidate delta byte-identical after main integration
- Review: passed - reviewer fable-loop-prove-repair-review--1397 - human-judgment=not-required
- Reviewed feature tip: 6c79e106d90c60f681b8f2e67fa0147a8da74b77
- Acceptance venue: automated
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: 6c79e106d90c60f681b8f2e67fa0147a8da74b77
- Accepted main tip: 39cb532c5bc9828103b4ed11ff9e1d124d5ab0d2

## Status

- State: land
- Blocker: none

## Feedback

- Events: `.orbit/feedback.jsonl`
