# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: codex://threads/019f6975-2bcc-7421-b9b3-95ead3db81c3
- Worktree: /Users/nckrtl/orbit/.worktrees/codex-app-inventory-drilldown
- Branch: codex/app-inventory-drilldown

## Goal

`orbit app:list` renders each visible logical app once as a DataList item with repository, visible instance count, and visible workspace count, while `orbit app:show` renders the selected app's visible instances and nested workspaces with URLs and clearly app-scoped dependency posture.

## Scope

- Owned: `PRODUCT_DECISIONS.md`; app list/show docs under `apps/docs/content/domains/5_app/`; `apps/gateway` app list/show API controllers and focused tests; `apps/cli` app list/show renderers and focused tests.
- Constraints: preserve canonical JSON app entities; add relationship/count data under existing command-specific payloads; derive non-gateway visibility from concrete instance placement; keep dependency posture explicitly logical-app scoped; verify the integrated behavior on an isolated retained topology.
- Out of scope: per-instance or per-workspace dependency audit ownership; changing `app:instance`; creating releases or GitHub artifacts; manual E2E lanes.

## Proof

- Verification:
  - focused: passed - CLI 12 tests / 66 assertions; gateway 26 tests / 132 assertions
  - broader: passed - all CLI App command tests 140 tests / 653 assertions; gateway App API tests 127 tests / 721 assertions; exact accepted-tip `composer quality-check` passed; evidence=`.orbit/quality-gates/quality-check-2026-07-16T090916Z-717ce8d32389.json`
  - runtime: passed - retained Incus topology `dev-887d9a` (`operator_gateway`) proved human and JSON `app:list` / `app:show` output; evidence=`.orbit/evidence/app-inventory-drilldown/runtime-proof.md`
- Blast radius: complete - evidence=repository-wide app list/show contract search plus docs lint and all CLI App command/gateway App API tests; result=canonical app JSON remains compatible, new inventory counts are command-scoped, instance-derived visibility is covered, and no other app command contract required changes
- Review: passed - independent general reviewer found no actionable findings after current-main rebase; blast-radius=complete; human-judgment=not-required
- Reviewed feature tip: bbdfe5d36d1f0975e222096fa5533b48da6742b1
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: bbdfe5d36d1f0975e222096fa5533b48da6742b1
- Accepted main tip: c8f365f9f58ff317544bde9c70658afc790635a3

## Status

- State: accepted
- Blocker: none

## Feedback

- Events: `.orbit/feedback.jsonl`
