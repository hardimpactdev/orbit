# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: `.orbit/loop.md`
- Worktree: `/Users/nckrtl/.codex/worktrees/fix-managed-file-host-prefix`
- Branch: `codex/fix-managed-file-host-prefix`

## Goal

Gateway-local managed-file probe and write operations map logical host paths through `ORBIT_HOST_PATH_PREFIX`, allowing gateway-issued route certificates to reach the host-mounted Caddy filesystem.

## Scope

- Owned: `apps/cli/app/Services/Convergence/LocalManagedFileAction.php`, its focused CLI regression coverage, and live `s3.orbit` route recovery.
- Constraints: preserve logical-path validation before mapping; do not expose certificate material; do not alter unrelated fleet drift.
- Out of scope: the 19 pre-existing fleet doctor issues and unrelated bootstrap baseline failures.

## Proof

- Verification:
  - focused: passed - RED confirmed logical `/etc/orbit` path remained unmapped; GREEN CLI 14 tests/56 assertions and gateway proxy fixer 26 tests/269 assertions at 25e14550e9a93f933cfa42b207d03ccbecb18688
  - broader: passed - ORBIT_QUALITY_CHECK_CPU_BUDGET=1 composer quality-check passed all 9 units; receipt `.orbit/quality-gates/quality-check-2026-07-17T055637Z-8961d6322bbc.json`
  - runtime: passed - retained Incus topology dev-4d1309; evidence `.orbit/evidence/runtime-proof.txt`
- Blast radius: complete - evidence=repository-wide ORBIT_HOST_PATH_PREFIX and managed-file consumer search; result=gateway stack mount and no-prefix consumers aligned
- Review: passed - human-judgment=not-required
- Reviewed feature tip: 25e14550e9a93f933cfa42b207d03ccbecb18688
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: 25e14550e9a93f933cfa42b207d03ccbecb18688
- Accepted main tip: d8cb57585cda82ce711b8bd23468b9a2da7692bc

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
reason` or `complete - evidence=repository-wide search, inventory, or
lintable check; result=summary` before acceptance; `gaps` returns to BUILD.
Proof files retained by the compact archive must be cited as one exact
inline-code path, for example `.orbit/evidence/runtime-proof.txt`; prose,
directories, padded code spans, and partial paths are not proof citations.
