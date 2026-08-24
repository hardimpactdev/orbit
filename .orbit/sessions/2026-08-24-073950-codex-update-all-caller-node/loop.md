# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Session: feat-codex-update-all-caller-node
- Worktree: /home/nckrtl/orbit/.worktrees/codex-update-all-caller-node
- Branch: codex/update-all-caller-node

## Goal

Make `update:all` update the registered calling node through normal workload
fan-out and caller-local CLI replacement, so NMBP and every other active
supported non-gateway node receive the Agent, Desktop, and CLI artifacts that
apply to that node.

## Scope

- Owned: gateway fleet target selection, update-plan consumers, focused Pest coverage, and the `update:all` product contract; primitive=registered caller target identity; transitions=success:caller receives workload artifacts and local CLI replacement|failure:post-mutation failure remains required|retry:same immutable plan retries safely|stop-restart:Desktop stages its restart-ready handoff|stale:unreachable pre-mutation caller skips without stopping the fleet.
- Constraints: keep gateway-first ordering; retain caller-local CLI replacement; do not filter by `managed` or roles; preserve pre-mutation unreachable-node skip behavior; never run `composer test:e2e*`; prove the final candidate on retained Incus, Mini, and NMBP before release acceptance. Producers are the gateway target selector and persisted update plan. Consumers are workload updating, progress events, fleet verification, caller-local replacement, and release acceptance. Dangerous invariants are gateway exclusion, no post-mutation skip, no duplicate local result, and Desktop-owned Agent restart.
- Out of scope: new Tauri UI behavior, a version bump, and public GitHub publication before explicit approval.

## Slices

| Slice | State | Checkpoint |
| --- | --- | --- |
| `.orbit/slices/01-caller-workload-fanout.md` | complete | 094726ac48bf35a8ee04e715603e8f64c5d2d348 |

## Proof

- Verification:
  - focused: passed - `bin/orbit-gateway-pest --compact tests/Unit/Services/Operations/FleetUpdateTargetSelectorTest.php tests/Feature/Services/Operations/WorkloadNodeUpdaterTest.php` (43 tests, 412 assertions); focused Mago format and lint passed for all changed PHP files.
  - broader: passed - `composer quality-check` passed on exact candidate `094726ac48bf35a8ee04e715603e8f64c5d2d348`; receipt `.orbit/quality-gates/quality-check-2026-08-24T051144Z-5227bc42828b.json`.
  - runtime: passed - candidate=094726ac48bf35a8ee04e715603e8f64c5d2d348; venue=retained-incus; environment=dev-fixture; command=exact-source WorkloadNodeUpdater for update:all caller agent-1 on topology dev-b54280; expected=registered caller is both a named workload target and a distinct local CLI target, its applicable CLI and Agent artifacts install, and an unreachable node skips before mutation without stopping fan-out; observed=progress targets were gateway/local/agent-1/operator-1, agent-1 completed CLI and Agent installation with exact manifest hashes, and operator-1 skipped with orbit_agent_not_running; result=passed; evidence=`.orbit/evidence/094726ac-retained-incus-caller-proof.txt`
- Blast radius: complete - evidence=Claude general reviewer grepped all four other `workloadNodes()` consumers for caller special-casing and searched docs/code repo-wide for stale caller-exclusion language; result=no consumer conflict or stale exclusion contract remains.
- Review: passed - human-judgment=not-required; reviewer=Claude general reviewer `caller-fanout-general-review`; no blocking findings; exact diff, all selector consumers, docs, tests, structured retained runtime receipt, failure handling, and caller-local separation verified; one non-blocking polish note identified a possibly stale Mago suppression.
- Reviewed feature tip: 094726ac48bf35a8ee04e715603e8f64c5d2d348
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: 094726ac48bf35a8ee04e715603e8f64c5d2d348
- Accepted main tip: bdcccfb125203b732871b9a3151a5b131ca7ae0b

## Status

- State: accepted
- Blocker: none

## Feedback

- Events: `.orbit/feedback.jsonl`
