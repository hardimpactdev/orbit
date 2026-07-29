# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad:
- Worktree: `/Users/nckrtl/orbit/.worktrees/codex-cache-clear-runtime-user`
- Branch: `codex/cache-clear-runtime-user`

## Goal

Laravel cache clearing triggered by instance and workspace env application deletes
bootstrap cache files as the app runtime user, including on production nodes.

## Scope

- Owned: `LocalAppCacheClearAction`, its focused CLI tests, instance/workspace env
  contracts, retained/live runtime proof, and RC rollout.
- Constraints: preserve unrelated work; do not change Hauzer code; do not create
  a GitHub release; do not expose environment secrets.
- Out of scope: the separate Beast Orbit Agent artifact-confirmation race.

## Proof

- Verification:
  - focused: passed - `apps/cli/tests/Feature/InternalAppCacheClearCommandTest.php`
    (3 tests, 12 assertions), scoped Mago format/lint, and docs lint.
  - broader: passed - `composer quality-check`; receipt
    `.orbit/quality-gates/quality-check-2026-07-29T112707Z-e8d09947fbff.json`.
  - runtime: passed - retained Incus topology `dev-3b1d54`, production
    `instance:env set --apply`; evidence `.orbit/evidence/runtime-proof.txt`
- Blast radius: complete - evidence=`rg -n "LocalAppCacheClearAction|internal:app-cache:clear|bootstrap cache" apps packages` plus bounded gateway caller searches; result=both env-apply callers use the reviewed action, both contracts are updated, and no caller surface remains unresolved
- Review: passed - human-judgment=not-required; findings=none; retained production proof closed the sole prior finding
- Reviewed feature tip: f94cdba778f46f7228c7223d1326cebbb160dade
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: f94cdba778f46f7228c7223d1326cebbb160dade
- Accepted main tip: 21c2cb47fa2e4f7cca24a62b4b2319d8951729db

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
a reason` or `complete - evidence=repository-wide search, inventory, or
lintable check; result=summary` before acceptance; `gaps` returns to BUILD.
Proof files retained by the compact archive must be cited as one exact
inline-code path, for example `.orbit/evidence/runtime-proof.txt`; prose,
directories, padded code spans, and partial paths are not proof citations.
