# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Session: feat-codex-beast-mini-native-assets
- Worktree: /Users/nckrtl/orbit/.worktrees/codex-beast-mini-native-assets
- Branch: codex/beast-mini-native-assets

## Goal

Beast can assemble an exact-commit Orbit release candidate while Mini builds and returns the native Darwin ARM64 Agent bundle over LAN-only SSH, with fail-closed identity, inventory, checksum, and architecture verification before candidate publication.

## Scope

- Owned: `bin/orbit-release-build-worktree`; `bin/orbit-build-native-release-assets`; `bin/orbit-release-candidate`; focused gateway release-helper tests; `.agents/skills/release/SKILL.md`; `apps/docs/content/tech-stack.md`; `PRODUCT_DECISIONS.md`
- Constraints: Mini is `nckrtl@192.168.6.10`; Beast is `nckrtl@192.168.6.20`; full 40-character SHA is authoritative; preserve all primary-checkout dirt; no WireGuard fallback; no candidate publication or fleet update during proof; never run `composer test:e2e*`
- Out of scope: PHP CLI runtime matrix; Tauri tray packaging/signing/notarization; live `update:all`; GitHub release publication; unrelated release or topology behavior

## Proof

- Verification:
  - focused: passed - Bash syntax; 65 focused Pest tests with 684 assertions; Mago format check; `composer docs-lint` at `d85dde660b96fb11f50356cce984c9674f94225a`
  - broader: passed - `composer quality-check` at `d85dde660b96fb11f50356cce984c9674f94225a`; final-check has warning-only timing variance across unrelated Pest lanes
  - runtime: not applicable - diff-derived venue is automated; required first-release Beast/Mini rehearsal is unverified because the owner host is outside `192.168.6.0/24`, and WireGuard fallback is forbidden
- Blast radius: complete - evidence=independent repository-wide searches and inventories for Mach-O consumers, candidate state keys, Agent build call sites, release workflows, LAN and WireGuard addresses, ignored worktree state, docs authority, and cleanup paths; result=no unclassified consumer or unresolved affected surface remains
- Review: passed - human-judgment=not-required; fresh Claude Opus review independently reproduced Apple and GNU Mach-O verification, confirmed all five prior findings resolved, and found no further actionable issue
- Reviewed feature tip: d85dde660b96fb11f50356cce984c9674f94225a
- Acceptance venue: automated
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: d85dde660b96fb11f50356cce984c9674f94225a
- Accepted main tip: 9f81735744acff98ef4b5819973c60fdb4d31185

## Status

- State: accepted
- Blocker: none

## Feedback

- Events: `.orbit/feedback.jsonl`
