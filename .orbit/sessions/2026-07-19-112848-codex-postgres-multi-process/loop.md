# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: `/Users/nckrtl/.codex/attachments/56fc6b99-044f-40cb-a303-34a350fefa10/pasted-text.txt`
- Worktree: `/Users/nckrtl/orbit/.worktrees/codex-postgres-multi-process`
- Branch: `codex/postgres-multi-process`

## Goal

Orbit can create independently configured PostgreSQL 16 and 18 managed-process instances on one node without endpoint or identity collisions, while analytics remains bound to its selected PostgreSQL process and legacy ambiguity fails closed.

## Scope

- Owned: process and analytics authority docs; `PRODUCT_DECISIONS.md`; gateway process catalogue, persistence, validation, analytics endpoint resolution, role settings, migrations, and focused tests; CLI `process:add` input/payload/output tests.
- Constraints: keep `postgres` generic; preserve explicit version family and concrete image metadata; typed service options only; generated credentials remain encrypted and secret-free; reject endpoint conflicts before side effects; preserve the existing PostgreSQL 16/Plausible state; use `--version` publicly while normalizing Symfony's reserved option internally.
- Out of scope: live `database1` mutation or deployment, PostgreSQL/Plausible upgrade or recreation, data migration, Mealou importer work, raw Docker provisioning, and per-version or per-consumer PostgreSQL service identifiers.

## Proof

- Verification:
  - focused: passed - gateway process catalog/API/analytics/migration/role suites, CLI process command suites, and the corrected update-runner manifest handoff fixture
  - broader: passed - root `composer quality-check` passed all unit, lint, analysis, format, Rector, and Rust subgates; gateway passed 5103/5103 and CLI passed 2349/2349
  - runtime: passed - `.orbit/evidence/postgres-multi-process-retained-proof.txt`
- Blast radius: complete - evidence=docs lint, generated command catalog, gateway and CLI Mago analysis, full gateway and CLI suites, retained operator+gateway+dev topology; result=process, node-role, analytics, migration, docs, and CLI seams covered
- Review: passed - human-judgment=not-required; all independent-review findings and the final quality-fixture delta are closed
- Reviewed feature tip: 9faff21cb5a7918ae5767892a0361a94674e06f2
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: 9faff21cb5a7918ae5767892a0361a94674e06f2
- Accepted main tip: 25169b42a9e6102e510080521fed1375f5c886aa

## Status

- State: accepted
- Blocker: none

## Feedback

- Events: `.orbit/feedback.jsonl`
