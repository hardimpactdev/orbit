# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: Codex task 019fa8f4-96e7-70d3-b9a0-e52f607f84df
- Worktree: /Users/nckrtl/orbit/.worktrees/codex-fleet-agent-install-error
- Branch: codex/fleet-agent-install-error

## Goal

When a fleet installer returns a non-zero result for an Agent-eligible node,
Orbit preserves that real failure output instead of replacing it with a
secondary Agent confirmation error.

## Scope

- Owned: fleet update installer result ordering and focused gateway coverage.
- Constraints: keep successful Agent confirmation strict; no GitHub release.
- Out of scope: changing artifact installation, retry policy, or node topology.

## Proof

- Verification:
  - focused: passed - 26 tests / 213 assertions for workload updates and Agent
    result inspection; scoped Mago formatting and analysis passed.
  - broader: passed - exact feature tip passed `composer quality-check` with
    receipt
    `.orbit/quality-gates/quality-check-2026-07-29T141454Z-66fcc75b5877.json`.
  - runtime: passed - retained Incus topology `dev-7105ed`
    (`operator_gateway`, gateway role on beast) ran the exact source hashes and
    preserved the installer stderr across the real WorkloadNodeUpdater boundary;
    evidence `.orbit/evidence/fleet-agent-install-error-retained-topology.txt`.
- Blast radius: complete - evidence=`rg -n "FleetUpdateNodeInstaller|expectedAgentInstallWasConfirmed|WorkloadNodeUpdater" apps/gateway/app apps/gateway/tests`; result=the shared installer and all workload update consumers retain strict successful-install confirmation while preserving non-zero results.
- Review: passed - independent exact-tip review found no findings; human-judgment=not-required
- Reviewed feature tip: 49f01bd81923e1769905d0b86993ee07061d2164
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: 49f01bd81923e1769905d0b86993ee07061d2164
- Accepted main tip: 33409d2d3b221c71be197e98a8f3522f0ac3ed43

## Status

- State: accepted
- Blocker: none

## Feedback

- Events: `.orbit/feedback.jsonl`
