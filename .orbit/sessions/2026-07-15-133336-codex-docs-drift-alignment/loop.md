# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: `solo://proj/4/scratchpad/docs-audit-final--309`
- Worktree: /Users/nckrtl/orbit/.worktrees/codex-docs-drift-alignment
- Branch: codex/docs-drift-alignment

## Goal

Resolve all 28 independently reviewed documentation-drift findings so Orbit's
authority docs, domain contracts, command signatures, JSON schemas, Doctor
routing, tests, and implementation describe one coherent current product model.

## Scope

- Owned: `PRODUCT_DECISIONS.md` for accepted direction-changing decisions;
  `apps/docs/content/**`; docs-owned lint/test code; and the focused Gateway,
  CLI, migration, and test seams required to make the accepted contracts true.
- Constraints: preserve generated `README.md` and `concepts.md` through
  Librarian; implement the final reviewed A1-A17, B1-B6, and C1-C5 directions;
  do not trigger manual E2E lanes; preserve unrelated primary-checkout edits.
- Out of scope: unrelated product or prose cleanup; dependency changes; manual
  E2E execution; changes outside the 28 accepted drift fixes.

## Proof

- Verification:
  - focused: passed - docs rules 79 tests/608 assertions; Agent installer 16/108; Doctor 82/692 plus controller 28/120; final critical Gateway set 89/476; process Gateway 266/1452; process CLI 78/303; AppShow 5/18; terminal lifecycle/removal Gateway set 44/233; final App removal review 10/64
  - broader: passed - exact clean candidate 73679f48aa8111a6fecc209c38ded1f3a6158659 passed composer quality-check across all 9 areas and 43 subgates; artifact .orbit/quality-gates/quality-check-2026-07-15T112350Z-76c172b4c266.json; artifact records exit 0, clean tree, and 43/43 zero-exit subgates; git diff --check passed
  - runtime: passed - retained-incus id=dev-d8a476 kind=operator_gateway host=beast proved bare app selectors fail app_instance_required, concrete development/production selectors persist app_instance, and same-name processes resolve distinct orbit_docs_development_main_instance-proof and orbit_docs_production_main_instance-proof units; prepared app-dev Agent lifecycle remained unavailable on both Incus and Docker because 10.6.0.4:9477 had no listener, so that seam is covered by focused tests
- Review: passed - independent general reviewer - human-judgment=not-required
- Reviewed feature tip: 73679f48aa8111a6fecc209c38ded1f3a6158659
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: 73679f48aa8111a6fecc209c38ded1f3a6158659
- Accepted main tip: 727d2486ba1a2d9a70d608b8dc2ab6dd0a82189d

## Status

- State: accepted
- Blocker: none

## Feedback

- Events: `.orbit/feedback.jsonl`

Allowed states are `frame`, `build`, `prove`, `accept`, `accepted`, `land`, and
`blocked`. Review must be `passed`, `fix`, or `escalate`. Acceptance must be
`pending`, `accepted`, `not-applicable`, or `changes-requested`. A terminal
review PASS must record `human-judgment=required|not-required` and the exact
committed HEAD in `Reviewed feature tip`.
