# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: none
- Worktree: `/Users/nckrtl/orbit/.worktrees/fix-security-audit`
- Branch: `fix-security-audit`

## Goal

Encrypt gateway-owned WireGuard private material and enforce owner-only permissions for credential-bearing TLS, Reverb, and gateway configuration without breaking provisioning or runtime reads.

## Scope

- Owned: gateway WireGuard persistence and forward migration; gateway TLS and config-root installers; CLI websocket runtime secret files; directly corresponding product documentation and regression tests.
- Constraints: preserve public certificate readability; preserve root-run container access; forward-migrate existing plaintext rows; add no dependencies; do not trigger manual E2E lanes.
- Out of scope: broader authorization redesign, unrelated package upgrades, and standing fleet mutation.

## Proof

- Verification:
  - focused: passed - dependency audits, `bin/orbit-secret-scan`, gateway route inventory, and focused baseline Pest rerun completed before edits; remediation regressions failed as intended; final independent verification passed 59 gateway tests / 466 assertions and 16 CLI tests / 129 assertions, plus shell syntax, scoped Mago, and diff checks
  - broader: passed - exact candidate passed the serialized root aggregate with every Pest, Mago, Rector, docs, and Cargo subgate at exit zero; gateway 4,958 tests / 28,838 assertions; CLI 2,330 / 9,634; docs 169 / 11,565; Core 129 / 538; receipt `.orbit/quality-gates/quality-check-2026-07-18T010133Z-e9563b20280c.json`; evidence `.orbit/evidence/security-audit.md`
  - runtime: passed - retained Incus topology `dev-803eac` (`operator_gateway_app-dev_websocket`) on `beast`; exact source-mounted convergence repaired deliberately loosened gateway and websocket credential modes to 0700/0600 while the Reverb container remained running and could read its config; evidence `.orbit/evidence/runtime-proof.txt`
- Blast radius: complete - evidence=dependency audits, secret scan, 176-route middleware inventory, dangerous-call/outbound-HTTP/raw-query inventory, all credential writers and startup/convergence callers, host-path prefix mappings, and TLS/Reverb runtime readers in `.orbit/evidence/security-audit.md`; result=stored credentials, secure creation ordering, startup repair, routine convergence, and mapped host TLS keys are covered
- Review: passed - human-judgment=not-required - independent review found no actionable issues after repository-wide credential persistence, permission-writer, startup/convergence, and installer-path inventories
- Reviewed feature tip: 093c6a3266fcd330c36f683a68094d26de655c06
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: 093c6a3266fcd330c36f683a68094d26de655c06
- Accepted main tip: e057ec6db64eccf42babe2f73a31049ada4ddcc7

## Status

- State: accepted
- Blocker: none

## Feedback

- Events: `.orbit/feedback.jsonl`

Allowed states are `frame`, `build`, `prove`, `accept`, `accepted`, `land`, and
`blocked`. Review must be `passed`, `fix`, or `escalate`. Acceptance must be
`pending`, `accepted`, `not-applicable`, or `changes-requested`. A terminal
review PASS must record `human-judgment=required|not-required` and the exact
committed HEAD in `Reviewed feature tip`. Blast radius must be either a stated
not-required reason or complete repository-wide evidence and result before
acceptance; `gaps` returns to BUILD.
Proof files retained by the compact archive must be cited as one exact
inline-code path, for example `.orbit/evidence/runtime-proof.txt`; prose,
directories, padded code spans, and partial paths are not proof citations.
