# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: not required
- Worktree: /Users/nckrtl/orbit/.worktrees/codex-solo-grok-claude-feature-loop
- Branch: codex/solo-grok-claude-feature-loop

## Goal

Orbit feature development uses the existing main Orbit Solo project to dispatch
substantive BUILD edits to Grok in the exact feature worktree, then dispatches
one fresh read-only Claude Opus review against that same worktree.

## Scope

- Owned: HARNESS.md, feature intake and handoff skills, the implementing-features
  skill and metadata, the feature-development graph, LAND behavior, and their
  focused contract tests.
- Constraints: Never create a Solo project for an Orbit worktree; Desktop Codex
  remains the sole feature owner; Grok gets no model override; Claude review
  uses an explicit Opus override; no agent runs composer test:e2e*.
- Out of scope: deleting historical Solo projects, Solo application internals,
  global Grok or Claude configuration, product behavior, and standing
  specialist review lanes.

## Proof

- Verification:
  - focused: passed - `bin/orbit-gateway-pest --compact tests/Feature/Architecture/McpConfigurationTest.php tests/Feature/E2ESupport/FeatureLandTest.php tests/Feature/E2ESupport/FeatureFinalizationGateTest.php` (259 tests, 986 assertions)
  - broader: passed - `composer quality-check` (profile `2026-08-22T09-42-31Z-9c62f874e54f`)
  - runtime: not applicable
- Blast radius: complete - evidence=repo-wide role and Solo ownership rg sweep plus graph JSON parse; result=no live stale role or ownership contracts
- Review: passed - Claude Opus Solo process 1599; no actionable findings; human-judgment=not-required
- Reviewed feature tip: 9c62f874e54f423c834ac68e1ec436113b3385ca
- Acceptance venue: automated
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: 9c62f874e54f423c834ac68e1ec436113b3385ca
- Accepted main tip: bea1cc28fcefb3b97241339267a18e8d3db55f64

## Status

- State: accepted
- Blocker: none

## Feedback

- Events: `.orbit/feedback.jsonl`
