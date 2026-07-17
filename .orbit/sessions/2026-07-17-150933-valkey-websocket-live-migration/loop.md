# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: live Valkey release-candidate migration
- Worktree: /Users/nckrtl/orbit/.worktrees/release-live-valkey-0-1-190
- Branch: release-live-valkey-0-1-190

## Goal

Deploy the Valkey-only WebSocket release candidate and converge the live WebSocket and Valkey runtimes through supported Orbit restore paths.

## Scope

- Owned: WebSocket node doctor restore routing, candidate artifacts, live WebSocket and Valkey runtime migration.
- Constraints: no GitHub release or final image promotion; preserve unrelated live topology drift.
- Out of scope: pre-existing non-WebSocket fleet health issues.

## Proof

- Verification:
  - focused: passed - evidence=`.orbit/evidence/valkey-websocket-restore-proof.txt`
  - broader: passed - evidence=`.orbit/evidence/valkey-websocket-restore-proof.txt`
  - runtime: passed - evidence=`.orbit/evidence/retained-valkey-websocket-proof.txt`
- Blast radius: complete - evidence=repository-wide issue-code and restore-path search; result=all probe, reconciliation, verification, test, and documentation consumers covered with no gaps
- Review: passed - human-judgment=not-required
- Reviewed feature tip: 2aa8666e5bf1408f28fc33aa76d3cd511ffdf59a
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: 2aa8666e5bf1408f28fc33aa76d3cd511ffdf59a
- Accepted main tip: a3d280b960858f7ba70cae84c6555fc927e02e60

## Status

- State: accepted
- Blocker: none

## Feedback

- Events: `.orbit/feedback.jsonl`

Allowed states are `frame`, `build`, `prove`, `accept`, `accepted`, `land`, and
`blocked`. Review must be `passed`, `fix`, or `escalate`. Acceptance must be
`pending`, `accepted`, `not-applicable`, or `changes-requested`. A terminal
review PASS must record `human-judgment=required|not-required` and the exact
committed HEAD in `Reviewed feature tip`. Blast radius must record either a
concrete local-change reason or complete search evidence and its result before
acceptance; `gaps` returns to BUILD.
Proof files retained by the compact archive must be cited as one exact
inline-code path; prose, directories, padded code spans, and partial paths are
not proof citations.
