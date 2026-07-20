# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: `/Users/nckrtl/shared-knowledge/projects/orbit/superpowers/plans/2026-07-20-project-instance-vocabulary.md`
- Worktree: `/Users/nckrtl/orbit/.worktrees/codex-project-instance-vocabulary`
- Branch: `codex/project-instance-vocabulary`

## Goal

Orbit exposes `Project -> Instance -> Workspace` as its canonical identity
hierarchy across product docs, CLI, gateway HTTP/JSON, SDK, authorization, and
diagnostics. The logical record is implemented as `Project`,
the concrete runtime remains implemented as `AppInstance`, and each workspace
belongs to exactly one instance.

## Scope

- Owned: `PRODUCT_DECISIONS.md`, `apps/docs/content/**`, `apps/cli/**`,
  `apps/gateway/**`, `packages/core/**`, `packages/sdk/**`, generated command
  catalog/unit-map artifacts, focused tests, and the forward fleet migration.
- Constraints: no public legacy aliases; keep dotted `project.instance`
  selectors; use flat `project:*`, `instance:*`, and `workspace:*` commands;
  preserve authorization behavior while renaming permissions; retain workspace
  behavior and app-runtime semantics; keep private app-named database identifiers
  so a failed gateway update can restore its previous image safely.
- Out of scope: renaming the `app-dev`/`app-prod` node roles, Laravel `APP_*`
  configuration, Laravel Cloud application terminology, app runtime container
  terminology, or unrelated placement-ownership cleanup.

## Proof

- Verification:
  - focused: passed - gateway project/instance payload, removal, migration, retained permission compatibility, and retained-sync command tests passed
  - broader: passed - gateway 5,182 tests / 30,264 assertions; CLI 2,370 tests / 9,820 assertions; E2E app 374 tests / 2,155 assertions; exact-tip quality receipt `.orbit/quality-gates/profiles/2026-07-20T15-41-17Z-2f0b75b70a8d/gateway_pest.junit.xml`
  - runtime: passed - exact tip `2f0b75b70a8d67cdbba4a98da5704342a832ea16` passed retained Incus command/permission proof and rendered host-macos dashboard proof; `.orbit/evidence/runtime-proof.txt`
- Blast radius: complete - evidence=repository-wide command, payload-key, selector, permission, docs-vocabulary, and direct E2E assertion scans plus full monorepo quality lanes and retained migrated-grant proof; result=public workload identity uses project/instance/workspace while infrastructure and private rollback app terminology remains classified
- Review: passed - human-judgment=not-required
- Reviewed feature tip: 2f0b75b70a8d67cdbba4a98da5704342a832ea16
- Acceptance venue: host-macos
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: 2f0b75b70a8d67cdbba4a98da5704342a832ea16
- Accepted main tip: 8122a8e871dfb92579cd1c2a9e83cdbae250c9a0

## Status

- State: accepted
- Blocker: none

## Feedback

- Events: `.orbit/feedback.jsonl`

Allowed states are `frame`, `build`, `prove`, `accept`, `accepted`, `land`, and
`blocked`. Review must be `passed`, `fix`, or `escalate`. Acceptance must be
`pending`, `accepted`, `not-applicable`, or `changes-requested`. A terminal
review PASS must record `human-judgment=required|not-required` and the exact
committed HEAD in `Reviewed feature tip`. Blast radius must record either a
reasoned not-required status or complete evidence and result before acceptance;
`gaps` returns to BUILD.
Proof files retained by the compact archive must be cited as one exact
inline-code path, for example `.orbit/evidence/runtime-proof.txt`; prose,
directories, padded code spans, and partial paths are not proof citations.
