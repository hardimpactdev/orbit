# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad:
- Worktree: `/Users/nckrtl/orbit/.worktrees/codex-fix-boot-runtime-convergence`
- Branch: `codex/fix-boot-runtime-convergence`

## Goal

After a physical node reboot or post-boot address flap, Orbit automatically
restores the current always-on proxy and application runtime containers, and
Doctor detects and repairs stopped or detached current runtime containers.

## Scope

- Owned: container restart intent; shared Agent and boot-convergence systemd
  rendering across bootstrap, provisioning, and fleet update; process/proxy
  Doctor detection and repair; product docs and focused coverage.
- Constraints: only current Orbit-managed containers whose rendered restart
  policy is `always` may be started; stale containers and `never` or explicitly
  stopped intent must stay stopped; deploy private RC artifacts without a
  GitHub release; prove the result on the live Beast node with a physical reboot.
- Out of scope: the separate production Dutch Laravel Foundation deployment,
  unrelated service/process drift, and redesigning node provisioning.

## Proof

- Verification:
  - focused: passed - CLI 17 tests/121 assertions; core 2/25; Librarian 0 errors (pre-existing warnings only); `git diff --check` clean
  - broader: passed - `composer quality-check` at `2368a57f5c29` with receipt `.orbit/quality-gates/quality-check-2026-07-27T102403Z-d569b8764291.json`
  - runtime: passed - live Beast reboot proof in `.orbit/evidence/beast-reboot-proof.txt` and live fleet correction proof in `.orbit/evidence/live-rc-fleet-proof.txt`
- Blast radius: complete - evidence=`.orbit/evidence/blast-radius.txt`; result=Agent and boot convergence units no longer require a fleet-specific WireGuard unit name, while bootstrap still owns wg-orbit provisioning
- Review: passed - independent review - human-judgment=not-required
- Reviewed feature tip: 2368a57f5c29f69ddfedacf564e9497e19612643
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: 2368a57f5c29f69ddfedacf564e9497e19612643
- Accepted main tip: 2368a57f5c29f69ddfedacf564e9497e19612643

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
inline-code path, as in `.orbit/evidence/beast-reboot-proof.txt`; prose,
directories, padded code spans, and partial paths are not proof citations.
