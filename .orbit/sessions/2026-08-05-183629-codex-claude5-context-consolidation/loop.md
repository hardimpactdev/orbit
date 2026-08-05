# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: /Users/nckrtl/shared-knowledge/projects/orbit/superpowers/2026-08-05-claude5-context-engineering.md (approved design; slice 1)
- Worktree: /Users/nckrtl/orbit/.worktrees/codex-claude5-context-consolidation
- Branch: codex/claude5-context-consolidation

## Goal

Consolidate the implementing-path instruction contract onto canonical
`HARNESS.md` with zero semantic change to any gate: implementing-features
SKILL.md at most 6 KB and phase/pointer based, Orbit-authored AGENTS.md section
at most 6 KB, combined implementing-path contract reduced at least 30%, retained-incus
named on the CLI fast-path row, post-feature-analyzer persona tombstoned as
historical, reviewer FIX vs same-candidate proof retry named as distinct
transitions, and all eight high-stakes constraints still explicit — proven
RED->GREEN by the updated architecture contract Pest tests and an identical
before/after fresh-agent probe set.

## Scope

- Owned: AGENTS.md (Orbit-authored section only), AGENT_FAST_PATH.md,
  HARNESS.md, .agents/skills/implementing-features/SKILL.md,
  .agents/review-personas/post-feature-analyzer.md,
  apps/gateway/tests/Feature/Architecture/McpConfigurationTest.php, and the
  slice evidence artifacts cited in Proof
- Constraints: no gate softened; HARNESS.md remains canonical; every deleted
  sentence maps to a surviving canonical statement; eight high-stakes
  constraints stay explicit on the implementing path; general.md stays fully
  self-contained; never run/delegate composer test:e2e*; no push, no land, no
  cleanup — clean committed candidate plus evidence for independent review
- Out of scope: slice 2 E2E hook enforcement; Boost-generated AGENTS block;
  cli-command.md, docs-librarian.md, tauri-agent.md, general.md;
  apps/docs/content/ product docs and PRODUCT_DECISIONS.md;
  handling-feature-requests/e2e-verification-lanes skills

## Proof

- Verification:
  - focused: passed - `bin/orbit-gateway-pest --compact tests/Feature/Architecture/McpConfigurationTest.php tests/Feature/E2ESupport/FeatureFinalizationGateTest.php tests/Feature/E2ESupport/QualityGateArtifactsTest.php` 230/230 on post-merge HEAD 0e2300e67 (`.orbit/evidence/green-contract-tests-post-main-merge.json`; slice RED: `.orbit/evidence/red-contract-tests-before-consolidation.json`; byte-ceiling RED 35482>33160 then GREEN 33057/margin 103: `.orbit/evidence/green-contract-tests-after-fix-round.json`; ceiling unchanged post-merge)
  - broader: passed - `composer quality-check` exit 0 on post-merge candidate 0e2300e67557f2811b0737badcf0941fcb3a8c5a (main 3da61146f merged, conflict-free), artifact `.orbit/quality-gates/quality-check-2026-08-05T162635Z-4f419487b8fd.json` (git dirty: false; all subgates 0); `composer docs-lint` exit 0/0 errors post-merge; three earlier post-merge gate attempts failed only on infrastructure contention from concurrent host gates (cli_pest 143, flaky cadence test exit 1, core_pest 143 — each lane green on narrow rerun: cli 2500/2500, core 174/174), final run executed after competing quality-check processes exited; probes: `.orbit/evidence/red-baseline-probe-process-1448.md` -> `.orbit/evidence/postchange-probe-process-1451.md` verdict IMPROVED; map: `.orbit/evidence/consolidation-old-to-new-map.md`
  - runtime: not applicable
- Blast radius: complete - evidence=deterministic repository-wide contract checks (McpConfigurationTest byte-ceiling at most 33160 plus needle assertions across McpConfigurationTest/FeatureFinalizationGateTest/QualityGateArtifactsTest, and rg sweeps proving no active file references the analyzer persona or deleted clauses); result=canonical anchors verified on the implementing path, no orphaned references (independent reviewer Solo process 1453, VERDICT PASS, BLAST_RADIUS complete)
- Review: passed - human-judgment=not-required (independent general reviewer, Solo process 1453, two FIX rounds resolved: byte ceiling + line wraps, main movement)
- Reviewed feature tip: 0e2300e67557f2811b0737badcf0941fcb3a8c5a
- Acceptance venue: automated
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: 0e2300e67557f2811b0737badcf0941fcb3a8c5a
- Accepted main tip: 3da61146f3e5cddf932973bada97cd9ae93a5cbf

## Status

- State: accepted
- Blocker: none

## Feedback

- Events: `.orbit/feedback.jsonl`
