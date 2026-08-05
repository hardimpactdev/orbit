# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: solo://proj/4/scratchpad/orbit-feature-loop-e--341
- Worktree: /Users/nckrtl/orbit/.worktrees/codex-loop-atomic-land
- Branch: codex/loop-atomic-land

## Goal

Make LAND operationally atomic as a resumable, ownership-safe saga via
`bin/orbit-feature-land`: merge, archive/index commit, stop only session-owned
processes, delete only the session-owned Solo project, remove only the exact
clean merged worktree and branch, with idempotent resume and no unrelated
mutation.

## Scope

- Owned: `bin/orbit-feature-land`; finalization Solo process-stop/project-delete
  classification; tracked archive/index cleanup gate; HARNESS/implementing-features
  LAND wiring; Pest coverage with fixture Git + fake Solo CLI. primitive=bin/orbit-feature-land; transitions=success:main-updated-archive-committed-session-cleaned|failure:stop-at-failed-phase-with-next-action|retry:rerun-from-observed-phase|stop-restart:resume-without-repeating-completed-mutations|stale:reject-ownership-or-identity-drift
- Constraints: no daemon/queue/dashboard/standing analyzer; no new product docs;
  no composer test:e2e*; no live destructive cleanup in tests; reuse archive and
  finalization primitives; every destructive mutation passes
  `bin/orbit-feature-finalization-check` then executes separately; Solo ownership
  is exact canonical project path == feature worktree path.
- Out of scope: Slice 7+; product behavior; generic transaction frameworks;
  semantic graders.

## Proof

- Verification:
  - focused: passed - merge candidate 1d9deacb42810f202ac39b45af6e1ca79652564d; FeatureLandTest 38 passed (126 assertions); FeatureFinalizationGateTest 157 passed (408 assertions); orbit-codex-pre-tool-use-hook-test passed; php -l clean on bin/orbit-feature-land, bin/orbit-finalization-solo-land.php, bin/orbit-codex-pre-tool-use-hook, bin/orbit-feature-finalization-check, FeatureLandTest.php, FeatureFinalizationGateTest.php; gateway Mago format --check already formatted; git diff --check clean
  - broader: passed - composer quality-check on 1d9deacb42810f202ac39b45af6e1ca79652564d dirty=false exit_code=0 45/45 subgates=0 via `.orbit/quality-gates/quality-check-2026-08-05T132343Z-b69ba0529c59.json` sha256=be8cd8d7ed27c5f9f0fd5d7190fc6a3464b2a66e715d3a46e99a8fb23eed7eed duration_seconds=73 mode=check
  - runtime: not applicable
- Blast radius: complete - evidence=Fable mechanical merge-tree equality, shared test overlap preserved (vocabulary cutover + Slice 6 finalization coverage), bin repair unchanged, all seven review surfaces covered; result=framework/bin LAND saga + finalization ownership/archive gate + harness/skill wiring closed on automated venue with no live Solo destructive cleanup
- Review: passed - reviewer Fable/Claude Solo process 1431 - human-judgment=not-required; findings none with non-actionable quote-unaware fail-closed residual noted
- Reviewed feature tip: 1d9deacb42810f202ac39b45af6e1ca79652564d
- Acceptance venue: automated
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: 1d9deacb42810f202ac39b45af6e1ca79652564d
- Accepted main tip: 10833ea27f23e85b0f3f469257732c5a5d8f1cb6

## Status

- State: accepted
- Blocker: none

## Feedback

- Events: `.orbit/feedback.jsonl`
- FRAME feedback surface `feature-loop-landing`: no prior matched records (`[]`)
- Integrated current main `10833ea27f23e85b0f3f469257732c5a5d8f1cb6` into
  `codex/loop-atomic-land` with non-rebase merge commit
  `1d9deacb42810f202ac39b45af6e1ca79652564d` (parents
  `f3a57d0d059159a7e75a45ec619af032471e51b3` +
  `10833ea27f23e85b0f3f469257732c5a5d8f1cb6`).
  Auto-merged `apps/gateway/tests/Feature/E2ESupport/FeatureFinalizationGateTest.php`
  with no conflict markers and no manual resolution; Slice 6 helpers
  (`commit_finalization_session_archive` and related) retained alongside main's
  app vocabulary cutover. Re-proved focused suite + clean quality-check on that
  tip. Fable final review PASS on exact candidate
  1d9deacb42810f202ac39b45af6e1ca79652564d (reviewer process 1431;
  human-judgment=not-required; findings none). Automated acceptance recorded for
  feature 1d9deacb42810f202ac39b45af6e1ca79652564d against main
  10833ea27f23e85b0f3f469257732c5a5d8f1cb6. LAND not started.
