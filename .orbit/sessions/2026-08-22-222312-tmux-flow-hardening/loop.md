# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Session: feat-tmux-flow-hardening
- Worktree: /Users/nckrtl/orbit/.worktrees/tmux-flow-hardening
- Branch: tmux-flow-hardening

## Goal

Orbit's tmux worker flow fails closed for absent cleanup sessions, refuses
unaccepted zero-delta LAND plans, and writes a deterministic searchable worker
bootstrap marker independent of CLI TUI echo while reliably submitting that
bootstrap to interactive agents.

## Scope

- Owned: `bin/orbit-finalization-tmux-land.php`, `bin/orbit-feature-land`,
  `bin/orbit-worker-spawn`, and their focused gateway feature tests.
- Constraints: Grok implements through the configured tmux worker; Claude Fable
  performs a fresh read-only review of the exact candidate; preserve legitimate
  idempotent cleanup only when linked-worktree ownership and the landed archive
  gate can still be proved; tests must demonstrate each regression before its
  fix; do not run human-only E2E lanes.
- Out of scope: product/Solo behavior, worker default model changes, generic
  verifier tooling, and changes to the documented LAND cleanup order.

## Proof

- Verification:
  - focused: passed - 282 LAND, worker-tool, and finalization-gate tests; 790 assertions
  - broader: passed - composer quality-check; 47 recorded subgates passed at candidate 17f8ca13ec602eb953082e0b70c3d84f80fa7475
  - runtime: passed - candidate=17f8ca13ec602eb953082e0b70c3d84f80fa7475; venue=automated; environment=local tmux; command=orbit-worker-spawn with real Claude Fable review-2 and Grok probe-grok; expected=one contiguous marker and automatic submitted bootstrap with clean exact-tip handoff; observed=both agents started without intervention, each log has one exact line-start marker, and both wrote exact-tip clean handoffs; result=pass; evidence=.orbit/workers/logs/review-2.log,.orbit/workers/logs/probe-grok.log,.orbit/workers/handoff/review-2.md,.orbit/workers/handoff/probe-grok.md
- Blast radius: not-required - harness-only scripts and focused tests; no product authority, transport, vocabulary, or schema changed
- Review: passed - fresh Claude Fable review-2; no actionable findings; human-judgment=not-required
- Reviewed feature tip: 17f8ca13ec602eb953082e0b70c3d84f80fa7475
- Acceptance venue: automated
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: 17f8ca13ec602eb953082e0b70c3d84f80fa7475
- Accepted main tip: d631318731897d0285938b6dbd67cf566d988da7

## Status

- State: accepted
- Blocker: none

## Feedback

- Events: `.orbit/feedback.jsonl`
