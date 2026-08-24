# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Session: feat-codex-fix-macos-bundle-version
- Worktree: /home/nckrtl/orbit/.worktrees/codex-fix-macos-bundle-version
- Branch: codex/fix-macos-bundle-version

## Goal

Build a macOS Desktop release bundle whose Info.plist version exactly matches
the root Orbit release version.

## Scope

- Owned: macOS desktop bundle build command and its focused release-builder tests.
- Constraints: preserve the updater public-key overlay and fail closed on bundle version drift.
- Out of scope: product behavior, release version bumping, and Apple notarization.

## Proof

- Verification:
  - focused: passed - NativeReleaseAssetsBuilderTest.php (11 tests, 60 assertions), bash -n, git diff --check
  - broader: passed - composer quality-check
  - runtime: passed - candidate=3476d98b7820b9978affb4089f027f2ef4ada1e6; venue=host-macos; environment=dev-fixture; target=Mini Darwin arm64; expected=CFBundleShortVersionString and CFBundleVersion equal 0.1.196; observed=both bundle version fields equal 0.1.196; result=passed; evidence=`.orbit/evidence/macos-bundle-version-3476d98b7.md`
- Blast radius: complete - evidence=independent review of the build script and focused release-builder coverage; result=Tauri config wiring and both macOS bundle version fields are covered
- Review: passed - independent reviewer found no actionable findings; human-judgment=not-required
- Reviewed feature tip: 3476d98b7820b9978affb4089f027f2ef4ada1e6
- Acceptance venue: automated
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: 3476d98b7820b9978affb4089f027f2ef4ada1e6
- Accepted main tip: 744004f624c7d6b3ed2b44aee6c35da1f893e5c5

## Status

- State: accepted
- Blocker: none

## Feedback

- Events: `.orbit/feedback.jsonl`
