# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: none
- Worktree: `/Users/nckrtl/orbit/.worktrees/codex-fix-launchd-hibernation-wake`
- Branch: `codex/fix-launchd-hibernation-wake`

## Goal

Starting a hibernated process scope on macOS enables its launchd service before
bootstrapping it, so a browser-triggered wake can start disabled process units.

## Scope

- Owned: launchd process lifecycle action and its CLI regression test.
- Constraints: deploy only through the live-test release-candidate channel; do
  not publish a GitHub release or promote stable images.
- Out of scope: changing the one-hour idle threshold, ten-minute sweep cadence,
  or the standard Caddy pre-check architecture.

## Proof

- Verification:
  - focused: passed - `apps/cli` launchd command tests: 10 tests, 32 assertions; scoped Mago format and lint clean
  - broader: passed - `composer quality-check`; receipt `.orbit/quality-gates/quality-check-2026-07-28T164628Z-8121d30269fe.json`
  - runtime: passed - host-macos at feature tip `9faca7513075988eee5c3973da8cd10c3be57747`; a real disabled temporary LaunchAgent was started through `LocalLaunchdServiceAction`, observed loaded/running with pid 20259, then stopped, booted out, and its plist removed; evidence `.orbit/evidence/runtime-proof.txt`. NMBP replacement-candidate proof remains part of release acceptance.
- Blast radius: complete - evidence=`rg "launchctl.*bootstrap|bootstrap.*launchctl|launchctl.*enable" apps packages`; result=the lifecycle action is the single launchd start ordering owner and the focused command test covers its public path.
- Review: passed - independent exact-tip review found no source or test defects; human-judgment=not-required
- Reviewed feature tip: 9faca7513075988eee5c3973da8cd10c3be57747
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: 9faca7513075988eee5c3973da8cd10c3be57747
- Accepted main tip: 7027446c5f5ae3d90ca7b704309601cc17778ecc

## Status

- State: land
- Blocker: none

## Feedback

- Events: `.orbit/feedback.jsonl`

Allowed states are `frame`, `build`, `prove`, `accept`, `accepted`, `land`, and
`blocked`. Review must be `passed`, `fix`, or `escalate`. Acceptance must be
`pending`, `accepted`, `not-applicable`, or `changes-requested`. A terminal
review PASS must record `human-judgment=required|not-required` and the exact
committed HEAD in `Reviewed feature tip`. Blast radius must be `not-required -
reason` or record `complete` with repository-wide evidence and a result before
acceptance; `gaps` returns to BUILD.
Proof files retained by the compact archive must be cited as one exact
inline-code path, for example `.orbit/evidence/runtime-proof.txt`; prose,
directories, padded code spans, and partial paths are not proof citations.
