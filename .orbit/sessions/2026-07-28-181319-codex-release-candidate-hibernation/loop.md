# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: live release-candidate hibernation rollout
- Worktree: /Users/nckrtl/orbit/.worktrees/codex-release-candidate-hibernation
- Branch: codex/release-candidate-hibernation

## Goal

Process Doctor does not report an explicitly hibernated app-development instance or workspace Docker runtime as down, while continuing to report stopped active, node-owned, and production runtimes.

## Scope

- Owned: `apps/gateway/app/Services/Processes/ProcessesProbe.php`, its focused Pest coverage, and `apps/docs/content/domains/7_process/process-doctor.md`
- Constraints: preserve the Caddy marker as hibernation authority; fail open to existing Doctor drift when marker state is unavailable; verify the replacement candidate on the live topology
- Out of scope: restoring unrelated live Doctor drift, changing the one-hour idle threshold or ten-minute sweep, GitHub releases/tags, and stable image promotion

## Proof

- Verification:
  - focused: passed - `bin/orbit-gateway-pest --compact tests/Unit/Services/Processes/ProcessesProbeTest.php` (55 tests, 251 assertions), focused Mago format check, and `composer docs-lint`
  - broader: passed - `composer quality-check`, `composer docs-lint`, and `composer quality-gate:final-check` at 6dd315f91f9cdbc6ca30e76beb7fe7bef0d6dcb7; receipt `.orbit/quality-gates/quality-check-2026-07-28T160704Z-9e74bd7c8a96.json`
  - runtime: passed - retained Incus topology dev-4b040b, app-dev-1, Solo terminal 1122; stopped active runtime produced `process.runtime_unit_down`, then the same stopped unit produced 0 issues with its explicit Caddy hibernation marker; evidence `.orbit/evidence/runtime-proof.txt`
- Blast radius: complete - evidence=bounded repository search across Process Doctor reporting and restoration, hibernation services, focused tests, and product docs; result=no unresolved consumers or ownership surfaces
- Review: passed - human-judgment=not-required; no actionable findings after optional marker transport failures were made fail-open
- Reviewed feature tip: 6dd315f91f9cdbc6ca30e76beb7fe7bef0d6dcb7
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: 6dd315f91f9cdbc6ca30e76beb7fe7bef0d6dcb7
- Accepted main tip: c78a2837ba2d6fd89a17e1519c374ddf0c13b1a4

## Status

- State: land
- Blocker: none

## Feedback

- Events: `.orbit/feedback.jsonl`

Allowed states are `frame`, `build`, `prove`, `accept`, `accepted`, `land`, and
`blocked`. Review must be `passed`, `fix`, or `escalate`. Acceptance must be
`pending`, `accepted`, `not-applicable`, or `changes-requested`. A terminal
review PASS must record `human-judgment=required|not-required` and the exact
committed HEAD in `Reviewed feature tip`. Blast radius must record whether it
was required and cite repository-wide evidence before acceptance; gaps return
to BUILD.
Proof files retained by the compact archive must be cited as one exact
inline-code path, for example `.orbit/evidence/runtime-proof.txt`; prose,
directories, padded code spans, and partial paths are not proof citations.
