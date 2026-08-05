# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: none
- Worktree: `/Users/nckrtl/orbit/.worktrees/codex-app-instance-workspace-vocabulary`
- Branch: `codex/app-instance-workspace-vocabulary`

## Goal

Atomic Orbit vocabulary migration so the canonical active workload hierarchy is
**App → Instance → Workspace**: logical Project workload surfaces become App;
concrete AppInstance/app_instances/app_instance_id become Instance/instances/instance_id;
public CLI/API/permissions/docs/skills/catalogs use App vocabulary with no
aliases, dual schema, or compatibility layer. Design authority:
`/Users/nckrtl/Library/Mobile Documents/com~apple~CloudDocs/shared-knowledge/projects/orbit/superpowers/specs/2026-08-05-app-instance-workspace-vocabulary-design.md`.
Base/main at dispatch: `4b2b7105952fa1ef1641339cc26a86c91db5a08f`.

## Scope

- Owned:
  - PRODUCT_DECISIONS.md newest entry superseding 2026-07-20 Project hierarchy
  - apps/docs/content authority (domain `5_project` → `5_app`) and generated catalogs
  - Gateway models/schema/API/permissions (Project→App, AppInstance→Instance;
    app_instances→instances; app_instance_id→instance_id; project:*→app:*;
    /api/projects→/api/apps; project:read|write→app:read|write)
  - CLI commands, core contracts, PHP/TS SDKs, OpenAPI, macOS UI strings/types
  - Deterministic guards rejecting active Project workload and AppInstance surfaces
  - Bundled `.agents/skills/orbit` source and references (no provider install)
- Constraints:
  - Keep app-dev and app-prod node roles exactly
  - Do not rename Laravel App\, app(), APP_*, monorepo apps/, Codex App/codex:app,
    Solo project commands, git/generic project-management wording
  - Historical migrations, dated decision text, immutable history, archived plans stay
  - No aliases, redirects, fallbacks, dual models/schema, or deprecated fields
  - Work only in this worktree; no push/merge/release/live nodes/e2e
- Out of scope:
  - Provider skill installs (Codex after landing)
  - Live fleet cutover / publish / primary checkout mutation
  - Redesign of ownership, hibernation, drivers, deploy, process key/label
  - composer test:e2e*

## Proof

- Verification:
  - focused: passed - gateway vocabulary/migration 14 passed; CLI instance/app 17 passed; macOS frontend 6 passed; SDK 140 passed; docs glued-option rule 2 cases/9 assertions; domains lint 0 errors; integrated main d8838d365d5f5f7567f40a05d3bcab5fc26d0922
  - broader: passed - composer quality-check exit 0; artifact `.orbit/quality-gates/quality-check-2026-08-05T131106Z-7f19cae2f352.json`; git.commit 7b40ac905f762e004d0baa05c803c4adc6d2ab28; dirty=false; every subgate 0
  - runtime: passed - candidate=7b40ac905f762e004d0baa05c803c4adc6d2ab28; venue=host-macos; environment=dev-fixture; target=host nick macOS; expected=App vocabulary dashboard and node-detail tables APPS/Apps plus APP INSTANCE ENVIRONMENT STATUS and PROCESS APP RUNTIME STATUS with app-dev/app-prod unchanged and empty browser errors; observed=APPS=2 and Apps list, Instances orbit-docs/local/development/ready, Processes queue/orbit-docs/launchd/running, roles unchanged, browser errors empty, screenshots host-macos-app-instance-dashboard.png and host-macos-app-instance-dashboard-instances.png retained; result=passed; evidence=`.orbit/evidence/runtime-proof.txt`
- Blast radius: complete - evidence=full main...HEAD residual re-scan (CLI signatures/help/headers, skill, product docs including activity/app-new, macOS headers, gateway models/routes/OpenAPI/SDK, migration purity+grant-seed tests, glued-option rule+wiring+tests+false-positive cases, quality-gate SHA/dirty/subgates, no correction-commit Mago suppressions); result=App→Instance→Workspace cutover remains complete; both prior docs findings fixed; glued-option guard correctly rejects --appdocs while accepting --app=docs/--apply/--node=app-1 with no residual gaps
- Review: passed - human-judgment=not-required - VERDICT=PASS
- Reviewed feature tip: 7b40ac905f762e004d0baa05c803c4adc6d2ab28
- Acceptance venue: host-macos
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: 7b40ac905f762e004d0baa05c803c4adc6d2ab28
- Accepted main tip: d8838d365d5f5f7567f40a05d3bcab5fc26d0922

## Status

- State: accepted
- Blocker: none

## Feedback

- Events: `.orbit/feedback.jsonl`
