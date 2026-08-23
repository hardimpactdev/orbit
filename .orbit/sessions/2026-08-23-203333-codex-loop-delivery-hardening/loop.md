# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Session: feat-codex-loop-delivery-hardening
- Worktree: /home/nckrtl/orbit/.worktrees/codex-loop-delivery-hardening
- Branch: codex/loop-delivery-hardening

## Goal

Reduce avoidable Orbit feature-loop restarts and late scope splits by pinning worker launch commands, moving PHP style checks before candidate commits, deriving one acceptance venue before dispatch, and keeping the owner active until the loop reaches a terminal state.

## Scope

- Owned: `bin/orbit-worker-spawn`, `bin/orbit-worker-registry.php`, worker-tool coverage, `HARNESS.md`, and `.agents/skills/implementing-features/SKILL.md`
- Constraints: remove unused launcher override behavior; preserve role-pinned launcher vectors and tmux worker semantics; keep the loop simpler; automated acceptance only
- Out of scope: active macOS feature branches; Incus local-versus-remote transport; product behavior; release behavior; generic loop metrics or new workflow lanes

## Proof

- Verification:
  - focused: passed - RED extra-args regression failed for all 3 datasets against prior behavior; GREEN passed 3 tests/12 assertions; final WorkerTools launcher coverage passed 7 tests, `McpConfigurationTest` passed 32 tests, the focused quality-contract test passed, and focused Mago formatting/linting passed for every changed PHP file before the final commit
  - broader: passed - `composer quality-check` on clean candidate `ab7eef830ecd0d23530d82f980770ce61d31974a` exited 0 in 147s; artifact `.orbit/quality-gates/quality-check-2026-08-23T182644Z-fcb8a567e7c5.json`
  - runtime: not applicable
- Blast radius: complete - evidence=`.orbit/workers/handoff/review-1-ab7eef830ecd0d23530d82f980770ce61d31974a.md` complete-diff deletion accounting plus repository-wide launcher-caller, command-vector, contract-consumer, and size-cap searches; result=no gaps or unrelated drift
- Review: passed - independent Claude Opus delta review; human-judgment=not-required; handoff=`.orbit/workers/handoff/review-1-ab7eef830ecd0d23530d82f980770ce61d31974a.md`
- Reviewed feature tip: ab7eef830ecd0d23530d82f980770ce61d31974a
- Acceptance venue: automated
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: ab7eef830ecd0d23530d82f980770ce61d31974a
- Accepted main tip: 89c836eeba2c2321e11b91f9db5e19cb99ccdc81

## Status

- State: accepted
- Blocker: none

## Feedback

- Events: `.orbit/feedback.jsonl`
