# Orbit Feature Loop

- Scratchpad: solo://proj/4/scratchpad/orbit-feature-loop-e--341
- Worktree: /Users/nckrtl/orbit/.worktrees/codex-loop-scope-transition-framing
- Branch: codex/loop-scope-transition-framing

## Goal

Stateful, lifecycle, and concrete UX feature scopes record the exact requested
primitive and relevant transitions compactly on the existing Scope Owned row;
ordinary/local loops omit the optional clause and stay concise.

## Scope

- Owned: HARNESS.md, LOOP.md.example, .agents/skills/implementing-features/SKILL.md, bin/orbit-loop-contract.php, bin/orbit-codex-pre-tool-use-hook, apps/gateway/tests/Feature/E2ESupport/FeatureFinalizationGateTest.php; primitive=compact Scope Owned framing clause; transitions=success:optional clause present with primitive plus five known transition keys and deterministic lint passes|failure:incomplete, duplicate, unknown keys or template placeholders fail lint with actionable output|retry:repair Scope clause and re-lint|stop-restart:n/a|stale:omit clause for ordinary loops; legacy packets remain valid
- Constraints: no new spec artifact, lane, ceremony, or semantic grader; deterministic validation only; no composer test:e2e*; preserve unrelated work
- Out of scope: product behavior docs unless real authority conflict; other worktrees; push/merge/archive/cleanup until LAND is validated

## Proof

- Verification:
  - focused: passed - FeatureFinalizationGate framing suite including Owned-row boundary (17 focused / 59 assertions); full FeatureFinalizationGateTest 156 passed; Fable rerun 157/408; php -l on bin/orbit-loop-contract.php; mago format via bin/orbit-gateway-vendor-bin mago format tests/Feature/E2ESupport/FeatureFinalizationGateTest.php
  - broader: passed - composer quality-check exit 0; artifact `.orbit/quality-gates/quality-check-2026-08-05T110701Z-ac530c3f5acd.json`; commit bf1cad7284ad0b46211199adb4e50f6dbc3485cc; dirty=false; 45/45 subgates exit 0; file sha256 a5a7a182d8c70306fe00b2c00834fd58308aa61e1cd3212cba4d523c98a2edcb; pest profiles directory 2026-08-05T11-05-50Z-bf1cad7284ad under quality-gates profiles (not archived)
  - runtime: not applicable
- Blast radius: complete - evidence=full-tree primitive=/transitions= marker sweep hits only the six diff files; 216 tracked archived loop packets scanned with zero marker-bearing Owned rows; no other Scope Owned parser in bin/, gateway, CLI, packages; compact_loop_problems has no callers outside the hook; result=no unowned Scope framing consumers or legacy Owned clauses to migrate
- Review: passed - Fable Solo process 1419 - human-judgment=not-required
- Reviewed feature tip: bf1cad7284ad0b46211199adb4e50f6dbc3485cc
- Fable review packet: `.orbit/release-evidence/2026-08-05-loop-scope-transition-framing/fable-review.txt`
- Acceptance venue: automated
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: bf1cad7284ad0b46211199adb4e50f6dbc3485cc
- Accepted main tip: b6b587ff412bdf1512f84a242c10afcb388feef1

## Status

- State: land
- Blocker: none

## Feedback

- Events: `.orbit/feedback.jsonl`
- Relevant surface feature-loop-framing: empty
