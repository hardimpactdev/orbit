# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: `/Users/nckrtl/shared-knowledge/projects/orbit/superpowers/plans/2026-07-16-workspace-env-ownership.md`
- Worktree: `/Users/nckrtl/orbit/.worktrees/codex-workspace-env-ownership`
- Branch: `codex/workspace-env-ownership`

## Goal

Workspace and app-instance environment intent applies only to its explicit owner, workspace setup preserves workspace-owned `.env` state, and output exposes exact scope and target paths.

## Scope

- Owned: `PRODUCT_DECISIONS.md`, app/workspace env docs, gateway env persistence/API/services/routes, CLI app/workspace env commands, workspace setup initialization and setup-step safety, retained-Incus gateway API startup ordering, focused tests.
- Constraints: TDD; non-secret explicit env values only; production-like values allowed; no parent/sibling/remote mutation; retained topology commands only, no manual `composer test:e2e*` lanes.
- Out of scope: secret env storage, generic full-inheritance policy, deploy-provider environment APIs, unrelated workspace setup refactors.

## Proof

- Verification:
  - focused: passed - final review-fix regression slice 25 tests/159 assertions; expanded affected gateway slice 71 tests/441 assertions; CLI affected slice 223 tests/483 assertions; Incus acquisition readiness 10 tests/63 assertions including fresh and snapshot-reset gateway API and source-mounted launcher ordering; quality scheduler regression file 43 tests/497 assertions including behavioral CPU-budget-1 dependency ordering; scoped Mago analysis and formatting clean; composer docs-lint passed
  - broader: passed - evidence=`.orbit/quality-gates/quality-check-2026-07-16T224617Z-195bfd9cafd6.json`; result=exact clean-tip low-concurrency composer quality-check passed every app and package, including full Gateway and unsharded CLI suites, both Rust apps, Core, SDK, docs, E2E harness checks, and Reverb
  - runtime: passed - evidence=`.orbit/evidence/runtime-proof.txt`; result=fresh retained topology dev-b9aadf acquired from final runtime code; complete workspace map applied without parent mutation across setup convergence, quoted inheritance rejection, and idempotent repeat
- Blast radius: complete - evidence=repository-wide parent-env consumer inventory plus app-instance authorization attribute, resolver, middleware, hostname endpoint, migration, and lifecycle guard review; result=no unresolved ownership or authorization surfaces
- Review: passed - human-judgment=not-required; no actionable findings; all prior authorization, complete-map apply, quoted-inheritance, and low-budget scheduler findings closed
- Reviewed feature tip: 4cd2b3a2555b44ca1b92165aa730a9b3e66deeec
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: 4cd2b3a2555b44ca1b92165aa730a9b3e66deeec
- Accepted main tip: 748905e31a393cd267d720ccff3fbd40b569750c

## Status

- State: accepted
- Blocker: none

## Feedback

- Events: `.orbit/feedback.jsonl`

Allowed states are `frame`, `build`, `prove`, `accept`, `accepted`, `land`, and
`blocked`. Review must be `passed`, `fix`, or `escalate`. Acceptance must be
`pending`, `accepted`, `not-applicable`, or `changes-requested`. A terminal
review PASS must record `human-judgment=required|not-required` and the exact
committed HEAD in `Reviewed feature tip`. Blast radius must be either a
documented not-required reason or complete evidence with a result summary
before acceptance; `gaps` returns to BUILD.
Proof files retained by the compact archive must be cited as one exact
inline-code path, for example `.orbit/evidence/runtime-proof.txt`; prose,
directories, padded code spans, and partial paths are not proof citations.
