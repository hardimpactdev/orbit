# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Session: feat-codex-loop-targeted-corrections
- Worktree: /Users/nckrtl/orbit/.worktrees/codex-loop-targeted-corrections
- Branch: codex/loop-targeted-corrections

## Goal

Ordinary feature delivery has one compact, exact-SHA loop: worker progress events cannot hide changed blockers or stale state, implementation completes with one atomic handoff and one retained proof receipt, automated acceptance validates the candidate once, and compact archives retain enough structured failure and timing evidence to diagnose real bottlenecks.

## Scope

- Owned: `HARNESS.md`, `.agents/skills/implementing-features/SKILL.md`, worker/acceptance/archive harness commands, and their focused gateway harness tests; primitive=feature delivery harness; transitions=success:one-candidate-one-proof-one-review-one-acceptance|failure:retain-exact-failed-gate-or-worker-state|retry:rearm-on-revised-worker-snapshot-or-return-concrete-defect|stop-restart:resume-from-SHA-bound-receipts-and-archive|stale:reject-or-surface-revised-identity-state
- Constraints: Preserve exact candidate identity, independent Opus-high review, fail-closed acceptance and LAND, resumability, tmux inspectability, human-only E2E, and compatibility for historical archives. Require focused Mago for changed production PHP before handoff. Inventory producers, consumers, and invariants in FRAME only when the goal changes a predicate, identity, vocabulary, or schema.
- Out of scope: Product runtime behavior, broad architectural refactors, release publication, human-only E2E lanes, and the separate firewall invariant/proof-rig candidate.

## Proof

- Verification:
  - focused: passed - 13 new regressions plus 216 adjacent acceptance/finalization checks; focused PHP syntax and Mago semantics clean
  - broader: passed - candidate=060b6fce0cf96e232c046c6e3b121f7bed4212f2; gate=quality-check; evidence=`.orbit/quality-gates/quality-check-2026-08-23T085007Z-d224f82345bf.json`
  - runtime: not applicable
- Blast radius: complete - evidence=`.orbit/workers/handoff/impl-harness-060b6fce0cf96e232c046c6e3b121f7bed4212f2.md` inventory plus reviewer search across active contract copies and executable consumers; result=all consumers agree and no orphaned reference was found
- Review: passed - fresh Claude Opus-high diff-first review found no DEFECT and four non-blocking POLISH observations; human-judgment=not-required; evidence=`.orbit/workers/handoff/review-harness-060b6fce0cf96e232c046c6e3b121f7bed4212f2.md`
- Reviewed feature tip: 060b6fce0cf96e232c046c6e3b121f7bed4212f2
- Acceptance venue: automated
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: 060b6fce0cf96e232c046c6e3b121f7bed4212f2
- Accepted main tip: 76be3a42484ee9654d381125d0ca0543319f0f47

## Status

- State: accepted
- Blocker: none

## Feedback

- Events: `.orbit/feedback.jsonl`
