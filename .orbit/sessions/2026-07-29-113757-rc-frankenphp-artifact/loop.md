# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: none
- Worktree: `/Users/nckrtl/orbit/.worktrees/codex-rc-frankenphp-artifact`
- Branch: `codex/rc-frankenphp-artifact`

## Goal

Orbit release candidates deliver the private FrankenPHP runtime image to Linux
workload nodes as a checksummed topology artifact, allowing an exact candidate
to be exercised without node-local registry credentials.

## Scope

- Owned: release-candidate helper, its focused contract test, PHP runtime
  product documentation, and release skill guidance.
- Constraints: use immutable candidate paths and recorded checksums; do not
  create a GitHub release; do not modify Hauzer code; preserve the existing
  candidate image digest and stable-alias contract.
- Out of scope: changing registry authentication on workload nodes, changing
  runtime image contents, or promoting a runtime before exact live acceptance.

## Proof

- Verification:
  - focused: passed - 49 tests and 484 assertions across the release helper,
    workload updater, and CLI internal installer
  - broader: passed - `composer quality-check`
  - runtime: not applicable - helper behavior is covered by contract tests;
    replacement RC and Hauzer production proof follow after the accepted change
    lands on clean pushed main
- Blast radius: complete - evidence=`rg -n "role.image.artifact|role_image_artifact|FrankenPHP|candidate alias|candidate.*image" apps/gateway/tests apps/cli/tests`; result=manifest generation, gateway transport, node installer, and release helper contracts inventoried
- Review: passed - independent - no findings - human-judgment=not-required
- Reviewed feature tip: 9806ab9edd68677ef51ed4e6e59cbaac57889777
- Acceptance venue: automated
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: 9806ab9edd68677ef51ed4e6e59cbaac57889777
- Accepted main tip: f27424448723e614639e28e8b647952b2b75e2e3

## Status

- State: accepted
- Blocker: none

## Feedback

- Events: `.orbit/feedback.jsonl`

Allowed states are `frame`, `build`, `prove`, `accept`, `accepted`, `land`, and
`blocked`. Review must be `passed`, `fix`, or `escalate`. Acceptance must be
`pending`, `accepted`, `not-applicable`, or `changes-requested`. A terminal
review PASS must record `human-judgment=required|not-required` and the exact
committed HEAD in `Reviewed feature tip`. Blast radius must be either
`not-required` with a reason or `complete` with repository-wide evidence and a
result before acceptance; `gaps` returns to BUILD.
Proof files retained by the compact archive must be cited as one exact
inline-code path, for example `.orbit/evidence/runtime-proof.txt`; prose,
directories, padded code spans, and partial paths are not proof citations.
