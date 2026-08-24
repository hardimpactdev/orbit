# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Session: feat-codex-fix-macos-login-path
- Worktree: /home/nckrtl/orbit/.worktrees/codex-fix-macos-login-path
- Branch: codex/fix-macos-login-path

## Goal

Orbit Desktop refreshes and verifies its launch-at-login registration against
the current macOS app bundle, including after the app moves from a build
worktree into `/Applications`.

## Scope

- Owned: `apps/macos/src/main.rs`, `apps/macos/src/paths.rs`, and the matching
  architecture and node lifecycle docs; primitive=macOS LaunchAgent registration;
  transitions=success:current executable recorded and verified|failure:menu reports unavailable|retry:next Desktop launch retries enablement|stop-restart:Desktop relaunch|stale:stale executable path is rejected
- Constraints: Preserve Desktop-owned Agent lifecycle and use Mini for exact
  native candidate proof.
- Out of scope: Apple Developer ID signing, notarization, and unrelated legacy
  desktop applications.

## Proof

- Verification:
  - focused: passed - `cargo test` (47 tests) and Librarian lint (0 errors)
  - broader: passed - `composer quality-check`; candidate=c80e217d6972af933484523bd162dee5da2ae658; evidence=`.orbit/quality-gates/quality-check-2026-08-24T010547Z-c6517704f04c.json`; result=46 subgates, exit 0, clean exact candidate
  - runtime: passed - candidate=c80e217d6972af933484523bd162dee5da2ae658; venue=host-macos; environment=live; target=Mini; expected=stale login registration is replaced with the current app path and Desktop stop/login-start also stops/starts its Agent child; observed=plist pointed to the installed exact-candidate app, STOP_PASS desktop=83590 agent=83634, LOGIN_START_PASS desktop=83707 agent=83757 parent=83707; result=passed; evidence=`.orbit/evidence/host-macos-login-path-c80e217d6972.txt`
- Blast radius: complete - evidence=`.orbit/evidence/host-macos-login-path-c80e217d6972.txt`; result=repository-wide lifecycle inventory found no unhandled producer or consumer
- Review: passed - human-judgment=not-required; reviewer=independent-code-quality; result=no blocking findings
- Reviewed feature tip: c80e217d6972af933484523bd162dee5da2ae658
- Acceptance venue: host-macos
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: c80e217d6972af933484523bd162dee5da2ae658
- Accepted main tip: 3c9321b663a92451840afef1c3d393ffa01ffb92

## Status

- State: accepted
- Blocker: none

## Feedback

- Events: `.orbit/feedback.jsonl`
