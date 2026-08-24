# Orbit Feature Loop

- Session: feat-codex-native-release-signer-invocation
- Worktree: /Users/nckrtl/orbit/.worktrees/codex-native-release-signer-invocation
- Branch: codex/native-release-signer-invocation

## Goal

The native Desktop bundle builder signs its updater archive through the installed local Tauri CLI, so a clean exact-commit Mini build produces a non-empty updater signature instead of failing with `sh: tauri: command not found`.

## Scope

- Owned: `bin/orbit-build-desktop-bundle` signer invocation and deterministic repository-tooling coverage
- Constraints: use the locked local `apps/macos` Tauri CLI; preserve fail-closed signing; never expose updater private material; do not publish artifacts; do not run any `test:e2e` lane
- Out of scope: updater key rotation, Desktop behavior, Apple signing/notarization, release-candidate publication

## Proof

- Verification:
  - focused: passed - candidate=f4249db8bdcd525a2f26c5624dbe40fd5e89b892 NativeReleaseAssetsBuilderTest signer invocation rejects npx and requires npm run tauri -- signer sign before the signature existence check
  - broader: passed - candidate=f4249db8bdcd525a2f26c5624dbe40fd5e89b892 composer quality-check exit_code=0 dirty=false `.orbit/quality-gates/quality-check-2026-08-23T210739Z-5c9bd895525c.json`
  - runtime: not applicable - automated repository tooling venue; supporting Mini probe LOCAL_TAURI_SIGNER_PROBE=PASS `.orbit/evidence/local-tauri-signer-probe.md`
- Blast radius: not-required - local signer invocation fix in `bin/orbit-build-desktop-bundle`; bounded repo-wide `rg` found no remaining references to the old `npx --yes --prefix @tauri-apps/cli signer` pattern, the caller chain `bin/orbit-build-native-release-assets` is intact, and the `tauri` npm script plus `@tauri-apps/cli` devDependency exist in `apps/macos`; no product decision, ownership boundary, transport, shared vocabulary, or shared schema changed
- Review: passed - human-judgment=not-required; independent general reviewer, deterministic repository-tooling proof
- Reviewed feature tip: f4249db8bdcd525a2f26c5624dbe40fd5e89b892
- Acceptance venue: automated
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: f4249db8bdcd525a2f26c5624dbe40fd5e89b892
- Accepted main tip: 17be5aa46911f7d33037433d401c9abf106ca4fb

## Status

- State: accepted
- Blocker: none

## Feedback

- Events: `.orbit/feedback.jsonl`
