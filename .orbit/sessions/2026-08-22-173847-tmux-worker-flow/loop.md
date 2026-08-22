# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Session: feat-tmux-worker-flow
- Worktree: `/Users/nckrtl/orbit/.worktrees/tmux-worker-flow`
- Branch: `tmux-worker-flow`

## Goal

Orbit feature development runs as one tmux-based, machine-agnostic flow: `bin/orbit-worker-*` tools spawn, watch, and track worker agents in a per-feature tmux session and a `.orbit/workers/` registry; `bin/orbit-feature-land`, finalization checks, acceptance and feedback source references, session archive and capture, loop lint and template, `HARNESS.md`, skills, review personas, and the development graph no longer depend on Solo; proven by updated Pest coverage, `composer quality-check`, and one real spawned worker handoff.

## Scope

- Owned: new `bin/orbit-worker-spawn|watch|status|send|heartbeat|handoff`; `bin/orbit-feature-land`; `bin/orbit-finalization-solo-land.php` (renamed `bin/orbit-finalization-tmux-land.php`); `bin/orbit-feature-finalization-check`; `bin/orbit-command-classify.php`; `bin/orbit-codex-pre-tool-use-hook`; `bin/orbit-cleanup-checks.php`; `bin/orbit-feedback-events.php`; `bin/orbit-feature-acceptance`; `bin/orbit-feature-acceptance-contract.php`; `bin/orbit-feature-feedback`; `bin/orbit-agent-session-archive`; `bin/orbit-agent-session-capture`; `bin/orbit-session-archive`; `bin/orbit-session-capture-health.php`; `bin/orbit-session-index`; `bin/orbit-loop-lint.php`; `bin/orbit-prepare-worktree`; `LOOP.md.example`; `HARNESS.md`; `AGENT_FAST_PATH.md`; `.agents/skills/**` development-flow Solo references (delete `solo-todo-handoff`); `.agents/review-personas/*.md`; `docs/orbit-feature-development-graph.html`; `PRODUCT_DECISIONS.md`; `apps/gateway/tests/Feature/E2ESupport/*`; `apps/gateway/tests/Feature/Architecture/McpConfigurationTest.php`.
- Constraints: exactly one flow with no Solo fallback; identical behavior on macOS and Linux; preserve the role contract from `6603011b1` (Grok implements at the exact worktree, one fresh Claude Opus general reviewer per tip, owner dispatches substantive edits); historical archives and the `orbit solo:*` product surface stay untouched; LAND transition: drive merge, archive, and archive-commit with this worktree's new `bin/orbit-feature-land --one-step` from the primary checkout, then finish kill-session, remove-worktree, and delete-branch with the primary's binary once main contains the merge; design authority is `~/shared-knowledge/projects/orbit/superpowers/specs/2026-08-22-tmux-worker-development-flow-design.md`.
- Out of scope: the `orbit solo:*` extension product and its docs; `~/.config/ai` machine configs; release-candidate build relocation to beast; Tauri macOS app builds; Orbit product runtime behavior.

## Proof

- Verification:
  - focused: passed - at 02ac571f3: WorkerToolsTest 21/21, FeatureLandTest 68/68, FeatureFinalizationGateTest 183/183, AgentSessionArchiveTest 106/106, SessionArchiveTest 96/96, SessionIndexTest 17/17, FeatureAcceptanceTest 134/134, FeatureFeedbackTest 20/20, McpConfigurationTest 32/32, QualityGateArtifactsTest 43/43, bin/orbit-codex-pre-tool-use-hook-test and bin/orbit-prepare-worktree-test passed, composer docs-lint passed, hook fail-closed probes BLOCKED, lane-close capture and archive resolution ok on real workers (see evidence)
  - broader: passed - composer quality-check exit 0 on 02ac571f3 (46 subgates, clean tree), artifact `.orbit/quality-gates/quality-check-2026-08-22T150225Z-33626b709036.json`
  - runtime: passed - candidate=02ac571f3; venue=automated; environment=local-tmux; command=bin/orbit-worker-spawn --role=proof --cli=grok --brief=.orbit/workers/briefs/proof-7.md --name=proof-7 --json; expected=a real worker spawned into feat-tmux-worker-flow on this candidate and bin/orbit-worker-watch returned its handoff event; observed=spawn exit 0 at 2026-08-22T15:00:47Z and watch printed event=handoff id=proof-7 at 15:01:18Z; result=passed; evidence=`.orbit/evidence/2026-08-22-worker-tools-runtime-proof.md`
- Blast radius: complete - evidence=repository-wide `rg -n -i solo` over bin/, HARNESS.md, AGENT_FAST_PATH.md, LOOP.md.example, AGENTS.md, PRODUCT_DECISIONS.md, .agents, docs plus a removed-symbol sweep (orbit-finalization-solo-land, solo-todo-handoff, --solo-*, SOLO_*, discover-by-cwd, solo_process_not_found, Scratchpad:, solo://); result=zero executable Solo references; only allowed leftovers remain (prohibition sentences, rename comment, session-index historical allowlist, historical ledger entries and the format line naming the historical trail, legacy-packet fixtures, deliberate rejection tests, the untouched orbit solo:* product surface)
- Review: passed - human-judgment=not-required; reviewer review-9 (fresh Claude Opus general reviewer, `.orbit/workers/handoff/review-9.md`) after eight FIX rounds
- Reviewed feature tip: 02ac571f390e100cabdee51fe766143a26a20950
- Acceptance venue: automated
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: 02ac571f390e100cabdee51fe766143a26a20950
- Accepted main tip: 6603011b139a2d11cebb08d3cc5c75255684d387

## Status

- State: accepted
- Blocker: none

## Feedback

- Events: `.orbit/feedback.jsonl`
