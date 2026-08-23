# Orbit Feature Loop

- Session: feat-codex-native-release-sdk-bootstrap
- Worktree: /Users/nckrtl/orbit/.worktrees/codex-native-release-sdk-bootstrap
- Branch: codex/native-release-sdk-bootstrap

## Goal

An exact-commit native release worktree can build Orbit Desktop without pre-existing `packages/sdk-typescript/node_modules`; the desktop bundle builder installs the locked TypeScript SDK dependencies before the Tauri build hook compiles that SDK.

## Scope

- Owned: `bin/orbit-build-desktop-bundle` dependency bootstrap and its deterministic repository-tooling coverage
- Constraints: use the lockfile with `npm ci`; keep updater private material external; do not publish artifacts; do not run any `test:e2e` lane
- Out of scope: changing SDK dependencies, changing Desktop product behavior, Apple signing/notarization, candidate publication

## Proof

- Verification:
  - focused: passed - NativeReleaseAssetsBuilderTest RED->GREEN (9 passed); new guard fails on base 8ca5becb9
  - broader: passed - composer quality-check exit 0 at candidate (receipt quality-check-2026-08-23T204955Z-76f065f60aa7.json, git.dirty=false)
  - runtime: not applicable - automated repository tooling venue
- Blast radius: not-required - local desktop-bundle build tooling; flag pattern already used at bin/orbit-prepare-worktree:337; no product decision/transport/schema/vocabulary impact
- Review: passed - VERDICT=PASS human-judgment=not-required; reviewer=review-1; candidate=eea6763d9a2b6df1a92ca2edfcf8871a86dad4ce; ordering before Tauri build, fail-closed shell, lockfile npm ci, deterministic base-failing guard, no secrets/artifacts
- Reviewed feature tip: eea6763d9a2b6df1a92ca2edfcf8871a86dad4ce
- Acceptance venue: automated
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: eea6763d9a2b6df1a92ca2edfcf8871a86dad4ce
- Accepted main tip: 8ca5becb9cd96e5ea6812ba23816455f14301e67

## Status

- State: accepted
- Blocker: none

## Feedback

- Events: `.orbit/feedback.jsonl`
