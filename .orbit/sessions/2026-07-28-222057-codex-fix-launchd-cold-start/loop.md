# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: conversation request to try the targeted launchd cold-start optimization
- Worktree: `/Users/nckrtl/orbit/.worktrees/codex-fix-launchd-cold-start`
- Branch: `codex/fix-launchd-cold-start`

## Goal

Starting an unloaded Orbit-owned macOS LaunchAgent bootstraps it without an
immediate forced restart, while starting an already loaded agent still
kickstarts it.

## Scope

- Owned: `apps/cli/app/Services/Processes/LocalLaunchdServiceAction.php`,
  `apps/cli/tests/Feature/InternalProcessLaunchdServiceCommandTest.php`
- Constraints: preserve the documented launchd runtime and hibernation
  contracts; prove the cold-start path on the implementing Mac.
- Out of scope: Linux/systemd, Supervisor, Caddy wake routing, hibernation
  policy, GitHub release, and release-candidate deployment.

## Proof

- Verification:
  - focused: passed - 11 Pest tests, 34 assertions; Mago format check
  - broader: passed - `composer quality-check` at
    `3e9cfed3fe77f323a50a9a39a24862d665432bbc`
  - runtime: passed - host topology kind=host-macos; host=nick.local;
    os=macOS 27.0; command=source candidate LocalLaunchdServiceAction stop,
    verify unloaded, timed start, inspect launchctl state;
    evidence=`.orbit/evidence/host-macos-launchd-cold-start.txt`; result=0.11s
    cold start and running state
- Blast radius: not-required - platform-local launchd optimization with no
  shared schema, transport, vocabulary, product contract, or Linux/systemd
  impact
- Review: passed - human-judgment=not-required; no actionable findings
- Reviewed feature tip: 3e9cfed3fe77f323a50a9a39a24862d665432bbc
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: 3e9cfed3fe77f323a50a9a39a24862d665432bbc
- Accepted main tip: 06a4f298f0d58a9833214ba5158a49a93e8892a0

## Status

- State: accepted
- Blocker: none

## Feedback

- Events: `.orbit/feedback.jsonl`
