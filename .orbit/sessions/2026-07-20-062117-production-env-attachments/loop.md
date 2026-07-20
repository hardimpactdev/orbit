# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: none
- Worktree: /Users/nckrtl/orbit/.worktrees/codex-production-env-attachments
- Branch: codex/production-env-attachments

## Goal

Database connection doctor restore can securely read and update Orbit-managed
production and development app `.env` files on Linux and macOS, allowing attached
database credentials to converge on app-prod and app-dev nodes.

## Scope

- Owned: `apps/cli/app/Services/EnvFiles/LocalEnvFileAction.php`, its focused CLI coverage, and authority docs only if the established attachment contract needs clarification.
- Constraints: retain operation-token authorization and exact app-root `.env` validation; prove production and development attachment restoration against live Mealou targets after release.
- Out of scope: arbitrary remote file access, non-env files, unrelated doctor drift, Mealou product behavior, and prepared E2E commands.

## Proof

- Verification:
  - focused: passed - `apps/cli/vendor/bin/pest tests/Feature/InternalEnvFileCommandTest.php --compact` (15 tests, 31 assertions); CLI Mago format, lint, and analyze exited 0
  - broader: passed - serialized `composer quality-check` (all 45 subgates exited 0; receipt `.orbit/quality-gates/quality-check-2026-07-20T040918Z-ae1a40bbb7bd.json`); `composer quality-gate:final-check` exited 0
  - runtime: passed - production, Linux development, macOS development, dual PostgreSQL versions, shared-catalogue privileges, weekly synchronization, and the authenticated browser workflow are recorded in `.orbit/evidence/runtime-proof.txt`
- Blast radius: complete - evidence=bounded repository-wide inventory of 122 timeout-related surfaces across Agent, CLI, gateway, docs, and SDK plus exact-tip quality and fleet inventory; result=the raw gateway validation gap is closed and no affected surface remains unresolved
- Review: passed - human-judgment=not-required; no actionable findings remain
- Reviewed feature tip: 194994db38159c917898bafa2492eb899200f27f
- Acceptance venue: automated
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: 194994db38159c917898bafa2492eb899200f27f
- Accepted main tip: 194994db38159c917898bafa2492eb899200f27f

## Status

- State: accepted
- Blocker: none

## Feedback

- Events: `.orbit/feedback.jsonl`

Allowed states are `frame`, `build`, `prove`, `accept`, `accepted`, `land`, and
`blocked`. Review must be `passed`, `fix`, or `escalate`. Acceptance must be
`pending`, `accepted`, `not-applicable`, or `changes-requested`. A terminal
review PASS must record `human-judgment=required|not-required` and the exact
committed HEAD in `Reviewed feature tip`. Blast radius must be `not-required -
reason` or `complete - evidence=repository-wide search, inventory, or
lintable check; result=summary` before acceptance; `gaps` returns to BUILD.
Proof files retained by the compact archive must be cited as one exact
inline-code path, for example `.orbit/evidence/runtime-proof.txt`; prose,
directories, padded code spans, and partial paths are not proof citations.
