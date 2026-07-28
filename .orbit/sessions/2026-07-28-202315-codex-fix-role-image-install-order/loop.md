# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: codex://threads/019fa858-123f-7242-a29d-43f2b14b9d83
- Worktree: /Users/nckrtl/orbit/.worktrees/codex-fix-role-image-install-order
- Branch: codex/fix-role-image-install-order

## Goal

Required role images finish installing before an Orbit Agent self-restart can interrupt the in-flight fleet update command.

## Scope

- Owned: CLI fleet-update install ordering, its executable regression coverage, and the update-all technical contract.
- Constraints: preserve current Agent restart behavior and candidate image verification; keep the live topology on candidate-only release channels.
- Out of scope: unrelated Doctor drift, changing role image contents, GitHub release publication, or stable image promotion.

## Proof

- Verification:
  - focused: passed - CLI install and verify files, 25 tests and 178 assertions
  - broader: passed - isolated CLI lane, 2,375 tests and 9,849 assertions; exact-tip `composer quality-check` profile `2026-07-28T18-17-44Z-ffd4bd4b9d78`
  - runtime: passed - retained live candidate failure and causal evidence in `.orbit/evidence/release-blocker.txt`; post-merge RC confirmation remains an explicit release acceptance check
- Blast radius: complete - evidence=`rg -n "restart_agent_service_if_present|load_required_image_artifacts|ORBIT_ROLE_IMAGE_ARTIFACTS_JSON|ORBIT_ROLE_IMAGES_JSON" apps/cli apps/gateway packages apps/docs/content/domains/11_operation/2_update-all`; result=one installer owns restart and role-image ordering, one environment object supplies payloads, and focused tests/docs cover the contract
- Review: passed - human-judgment=not-required; exact-tip review found no actionable findings
- Reviewed feature tip: ffd4bd4b9d78af55c5bcccc5856d31fc141f276d
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: ffd4bd4b9d78af55c5bcccc5856d31fc141f276d
- Accepted main tip: 454815d944363447363fbf7d6ea5600c58c58d8a

## Status

- State: accepted
- Blocker: none

## Feedback

- Events: `.orbit/feedback.jsonl`

Allowed states are `frame`, `build`, `prove`, `accept`, `accepted`, `land`, and
`blocked`. Review must be `passed`, `fix`, or `escalate`. Acceptance must be
`pending`, `accepted`, `not-applicable`, or `changes-requested`. A terminal
review PASS must record `human-judgment=required|not-required` and the exact
committed HEAD in `Reviewed feature tip`. Blast radius must be `not-required -
reason` or `complete - evidence=repository-wide search, inventory, or lintable
check; result=summary` before acceptance; `gaps` returns to BUILD.
Proof files retained by the compact archive must be cited as one exact
inline-code path; prose, directories, padded code spans, and partial paths are
not proof citations.
