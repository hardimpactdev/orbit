# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Session: feat-release-0-1-198
- Worktree: /Users/nckrtl/orbit/.worktrees/release-0-1-198
- Branch: release-0-1-198

## Goal

Every Orbit macOS desktop release archive contains a resource-sealed app bundle whose complete signature verifies before the archive and DMG are created.

## Scope

- Owned: `bin/orbit-build-desktop-bundle` and focused gateway release-builder coverage; primitive=macOS app bundle seal; transitions=success:seal and verify before artifact creation|failure:stop when codesign, verification, or CodeResources is missing|retry:rebuild the immutable release candidate|stop-restart:installed app consumes the signed updater handoff|stale:reject an archive built without a resource manifest
- Constraints: preserve the existing Tauri updater signing key and artifact signature; use an Apple signing identity when configured and an ad-hoc bundle signature otherwise; do not run `composer test:e2e*`.
- Out of scope: Apple Developer ID procurement, notarization, GitHub publication, production deployment inheritance, and zero-downtime deployment.

## Slices

| Slice | State | Checkpoint |
| --- | --- | --- |
| `.orbit/slices/01-seal-macos-release-bundle.md` | complete | 3f99f10d71a7180b106654baa44dc49897a19b14 |

## Proof

- Verification:
  - focused: passed - regression red then green with 12 tests and 76 assertions
  - broader: passed - `composer quality-check`; `.orbit/quality-gates/quality-check-2026-08-26T135419Z-ac165ba3e4c5.json`
  - runtime: not applicable
- Blast radius: complete - evidence=repository-wide `rg` inventory of desktop archive creation, updater signing, codesign, and installer consumers across `bin`, `apps`, and `packages`; result=`bin/orbit-build-desktop-bundle` is the sole app-bundle artifact producer and the focused builder regression covers its seal-before-archive boundary while updater verification and installation stay unchanged
- Review: passed - independent review found no correctness, security, shell-safety, TDD, or Orbit-convention findings - human-judgment=not-required
- Reviewed feature tip: 3f99f10d71a7180b106654baa44dc49897a19b14
- Acceptance venue: automated
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: 3f99f10d71a7180b106654baa44dc49897a19b14
- Accepted main tip: ac474889fe3072cf8510d435837a08bf8028875f

## Status

- State: accepted
- Blocker: none

## Feedback

- Events: `.orbit/feedback.jsonl`
