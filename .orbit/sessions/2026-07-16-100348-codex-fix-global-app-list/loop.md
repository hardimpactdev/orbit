# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: none
- Worktree: `/Users/nckrtl/orbit/.worktrees/codex-fix-global-app-list`
- Branch: `codex/fix-global-app-list`

## Goal

`orbit app:list` returns every logical app visible through the caller's
authorized concrete app instances exactly once, without resolving or accepting
a node scope.

## Scope

- Owned: `PRODUCT_DECISIONS.md`; `apps/docs/content/domains/5_app/3_app-list/**`;
  `.agents/skills/orbit/references/app.md`;
  `apps/docs/content/architecture.md`;
  `apps/docs/content/domains/authorization-matrix.md`;
  `apps/cli/app/Commands/Concerns/PromptsForGatewayRegistryEntities.php`;
  `apps/cli/app/Commands/App/AppListCommand.php`;
  `apps/cli/app/Commands/App/AppListQueryResolver.php`;
  `apps/cli/mago-analyzer-baseline.toml`;
  `apps/cli/tests/Feature/Commands/App/AppListCommandTest.php`;
  `apps/gateway/app/Http/Controllers/Api/AppListController.php`;
  `apps/gateway/mago-analyzer-baseline.toml`;
  `apps/gateway/tests/Feature/Http/Api/AppListControllerTest.php`;
  `packages/sdk/src/Requests/Apps/ListAppsRequest.php`;
  `packages/sdk/tests/Unit/GatewaySdkContractDriftTest.php`;
  `packages/sdk/tests/Unit/Requests/Apps/ListAppsRequestTest.php`.
- Constraints: logical apps are not node-scoped; non-gateway visibility derives
  only from concrete Orbit instances on nodes where the caller has `app:read`;
  hidden instance and workspace placement must not leak; no data migration; no
  GitHub release.
- Out of scope: redesigning the canonical app JSON entity, removing logical-app
  default metadata columns, or changing app-instance/workspace management
  commands.

## Proof

- Verification:
  - focused: passed - CLI `app:list` and `app:show` tests (12 tests, 50
    assertions), gateway `AppListControllerTest` (13 tests, 43 assertions),
    baseline-free Mago analysis for the changed gateway controller, scoped Mago
    format checks, `composer docs-lint`, generated command-catalog checks, and
    `git diff --check`.
  - broader: passed - `composer quality-check` passed all apps and packages for
    committed feature tip `b18fad7902a2a913be78cfdaa4e43d37009de82b`.
    Artifact:
    `.orbit/quality-gates/quality-check-2026-07-16T073557Z-bbb78c33af17.json`.
  - runtime: passed - retained Incus topology `dev-eb4624`
    (`operator_gateway`) on `beast`, checkout roles `operator,gateway`, exact
    changed CLI/controller hashes matched, and live CLI-to-gateway proof
    confirmed one global table, JSON logical-app uniqueness across two
    instances, placement-aware workspace URLs, and rejection of `--node`.
    Evidence: `.orbit/evidence/app-list-global-retained-topology.md`.
  - release: passed - current-version candidate refresh
    `20260716T074046Z-b18fad790` was built from pushed `origin/main`, activated
    on the live-test channel, and accepted by a successful fleet `update:all`
    with no new doctor regressions. Evidence:
    `.orbit/evidence/release-candidate-live-20260716T074046Z-b18fad790.md`.
- Blast radius: complete - evidence=repository-wide rg search for retired app-list node option, resolver, API query, SDK construction, owning-node wording, and regenerated command catalog; result=removed every global app-list node scope while preserving node inputs on placement-scoped families
- Review: passed - human-judgment=not-required
- Reviewed feature tip: b18fad7902a2a913be78cfdaa4e43d37009de82b
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: b18fad7902a2a913be78cfdaa4e43d37009de82b
- Accepted main tip: 284b11926332176e1f8be0195c0a34f9a5166783

## Status

- State: land
- Blocker: none

## Feedback

- Events: `.orbit/feedback.jsonl`
