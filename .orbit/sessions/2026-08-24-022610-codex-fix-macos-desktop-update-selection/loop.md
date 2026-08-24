# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Session: feat-codex-fix-macos-desktop-update-selection
- Worktree: /home/nckrtl/orbit/.worktrees/codex-fix-macos-desktop-update-selection
- Branch: codex/fix-macos-desktop-update-selection

## Goal

`update:all` selects every active supported non-gateway node regardless of roles or `managed`, updates each reachable Linux node with CLI and Agent and each reachable Mac with Desktop, Agent, and CLI, and skips any unreachable node before mutation without failing the remaining fleet.

## Scope

- Owned: Fleet update target selection, universal pre-mutation reachability skip, selected-macOS Desktop-artifact predicates, focused gateway Pest coverage, update/node product docs, and the dated product decision; primitive=all-active-node-fleet-update; transitions=success:all-reachable-nodes-updated-and-unreachable-nodes-skipped|failure:post-mutation-error-remains-failed|retry:rerun-update-all-after-node-becomes-reachable|stop-restart:Desktop-quit-stops-Agent-and-causes-pre-mutation-skip|stale:n/a
- Constraints: `managed` and workload roles must not control `update:all` inclusion or Agent artifact installation; include every active supported non-gateway node with valid WireGuard identity; install CLI+Agent on reachable Linux and Desktop+Agent+CLI on reachable macOS; exclude inactive/removed/unsupported/gateway/caller-duplicate records; skip only when unreachable before the first mutation; preserve all later failures; do not run or delegate `composer test:e2e*`.
- Out of scope: Changing node roles or the separate meaning of `managed` for Agent intent, Apple notarization, unrelated gateway Doctor drift, or GitHub release publication.
- Predicate inventory: Producers are node active state, gateway identity, platform/Agent eligibility, caller identity, and pre-mutation reachability; consumers are `FleetUpdateTargetSelector`, fleet version planning/counts, `WorkloadNodeUpdater`, Desktop artifact/pending handoff construction, operation event/result renderers, and fleet verification. Dangerous invariants are caller deduplication, gateway exclusion, unsupported/inactive exclusion, pre-mutation-only skip, post-mutation failure preservation, and platform-correct Desktop payloads.

## Proof

- Verification:
  - focused: passed - `WorkloadNodeUpdaterTest.php` (40 passed); `FleetVersionProbeTest.php` (15 passed, including the Ubuntu gateway all-current regression); `NodeAgentEligibilityTest.php` (7 passed); `NodeCommandTransportSelectorTest.php` (13 passed); `FleetUpdateAgentRestartReadinessTest.php` (1 passed with a real final probe); boundary tests for the scoped Agent config/service builders and target selector; focused Mago format/lint/analyze on changed PHP
  - broader: passed - `composer quality-check` exit 0 on clean candidate `f9a9f40793167ea32f6b202bef8d32cccd5fbd18`; artifact `.orbit/quality-gates/quality-check-2026-08-24T001837Z-6c30635284e3.json`; duration 138s
  - runtime: passed - candidate=f9a9f40793167ea32f6b202bef8d32cccd5fbd18; venue=retained-incus; environment=dev-fixture plus NMBP macos-27-arm64; target=reachable roleless unmanaged Linux node with current CLI and missing Agent plus native NMBP Desktop staging plus current Ubuntu gateway boundary; expected=the Linux node installs and verifies its missing Agent regardless of roles or management state, the Mac stages verified Desktop plus Agent plus CLI with restart-ready handoff, unreachable nodes skip before mutation, and a current Ubuntu gateway stays outside workload Agent drift; observed=app-dev-1 was unmanaged with no roles, a current CLI, and no Agent identity, then completed, passed Agent restart readiness and Agent verification, recorded the expected Agent hash, and became current, while NMBP installed isolated CLI and Agent artifacts, staged the Desktop archive, wrote a 0600 restart-ready handoff owned by Desktop, and kept the existing Agent PID unchanged, offline Linux and Mac retained their platform-specific skip results, and the Ubuntu gateway regression reported zero outdated nodes and all current; result=passed; evidence=`.orbit/evidence/retained-dev-b77c00/runtime-proof.md`
- Blast radius: complete - evidence=repository-wide rg inventory of all eligibility, Agent-artifact probe, Agent-intent, skip-vocabulary, docs, and renderer consumers; result=all affected surfaces were independently resolved with no gaps
- Review: passed - human-judgment=not-required; independent Claude reviewer closed all prior findings and DEFECT 4, confirmed `BLAST_RADIUS: complete`, and returned `VERDICT: PASS` for exact candidate `f9a9f40793167ea32f6b202bef8d32cccd5fbd18`
- Reviewed feature tip: f9a9f40793167ea32f6b202bef8d32cccd5fbd18
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: f9a9f40793167ea32f6b202bef8d32cccd5fbd18
- Accepted main tip: 9d9d91c093136c33de09085ff2d4fffcc45cea44

## Status

- State: accepted
- Blocker: none

## Feedback

- Events: `.orbit/feedback.jsonl`
