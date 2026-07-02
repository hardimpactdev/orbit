# Grok Lane E Deploy Linked-Test Remediation

- Solo process: `2063` (`lane-e-deploy-linked-test-audit`)
- Worktree: `/Users/nckrtl/orbit/.worktrees/linked-test-catalog-drift`
- Branch: `linked-test-catalog-drift`
- Scope: `deploy` command family only (`apps/docs/content/domains/10_deploy/**`)
- Baseline missing deploy refs: 27 (stale `apps/gateway/tests/Feature/Commands/Deploy/*` and `DeployCommandContractTest.php`)
- Domain-doc stale refs after remediation: 0

## Changed files (24)

All under `apps/docs/content/domains/10_deploy/`:

- `1_deploy-step-add/technical/1_deploy-step-add.md`
- `1_deploy-step-add/technical/5.1_deploy-step-add_input-mode_interactive.md`
- `1_deploy-step-add/technical/5.2_deploy-step-add_input-mode_non-interactive.md`
- `1_deploy-step-add/technical/6.1_deploy-step-add_output-render_human.md`
- `1_deploy-step-add/technical/6.2_deploy-step-add_output-render_json.md`
- `2_deploy-step-list/technical/1_deploy-step-list.md`
- `2_deploy-step-list/technical/6.1_deploy-step-list_output-render_human.md`
- `2_deploy-step-list/technical/6.2_deploy-step-list_output-render_json.md`
- `3_deploy-step-remove/technical/1_deploy-step-remove.md`
- `3_deploy-step-remove/technical/5.1_deploy-step-remove_input-mode_interactive.md`
- `3_deploy-step-remove/technical/5.2_deploy-step-remove_input-mode_non-interactive.md`
- `3_deploy-step-remove/technical/6.1_deploy-step-remove_output-render_human.md`
- `3_deploy-step-remove/technical/6.2_deploy-step-remove_output-render_json.md`
- `4_deploy-run/technical/1_deploy-run.md`
- `4_deploy-run/technical/5.1_deploy-run_input-mode_interactive.md`
- `4_deploy-run/technical/5.2_deploy-run_input-mode_non-interactive.md`
- `4_deploy-run/technical/6.1_deploy-run_output-render_human.md`
- `4_deploy-run/technical/6.2_deploy-run_output-render_json.md`
- `5_deploy-history/technical/1_deploy-history.md`
- `5_deploy-history/technical/6.1_deploy-history_output-render_human.md`
- `5_deploy-history/technical/6.2_deploy-history_output-render_json.md`
- `6_deploy-log/technical/1_deploy-log.md`
- `6_deploy-log/technical/6.1_deploy-log_output-render_human.md`
- `6_deploy-log/technical/6.2_deploy-log_output-render_json.md`

## Test files inspected

CLI:

- `apps/cli/tests/Feature/Commands/Deploy/DeployWriteCommandsTest.php`
- `apps/cli/tests/Feature/Commands/Deploy/DeployReadCommandsTest.php`
- `apps/cli/tests/Feature/Commands/Deploy/DeployInteractiveInputModeTest.php`
- `apps/cli/tests/Feature/Commands/Deploy/DeployRunStreamCommandTest.php`
- `apps/cli/tests/Feature/Commands/LogStreamCommandTest.php`

Gateway:

- `apps/gateway/tests/Feature/Http/Api/DeployControllerTest.php`
- `apps/gateway/tests/Feature/Actions/Deploy/DeployStepActionsTest.php`
- `apps/gateway/tests/Unit/Services/Deploy/DeployManagerContainerRoutingTest.php`

Confirmed absent (removed from docs, not substituted):

- `apps/gateway/tests/Feature/Commands/Deploy/*` (entire retired tree)
- `apps/gateway/tests/Unit/Services/Deploy/DeployCommandContractTest.php`

## Coverage rows added or narrowed

| command / surface | linked test(s) | narrowed from stale claim |
| --- | --- | --- |
| `deploy:step-add` canonical | `DeployWriteCommandsTest.php`, `DeployControllerTest.php`, `DeployStepActionsTest.php` | Dropped nonexistent gateway command/contract tests; split CLI vs gateway ownership |
| `deploy:step-add` input/renderers | `DeployInteractiveInputModeTest.php`, `DeployWriteCommandsTest.php` | Removed prompt-ID / every-error-code overclaims |
| `deploy:step-list` | `DeployReadCommandsTest.php`, `DeployControllerTest.php` | Replaced missing gateway command/contract tests |
| `deploy:step-remove` | `DeployWriteCommandsTest.php`, `DeployInteractiveInputModeTest.php`, `DeployStepActionsTest.php` | Same pattern as step-add |
| `deploy:run` | `DeployWriteCommandsTest.php`, `DeployInteractiveInputModeTest.php`, `DeployRunStreamCommandTest.php`, `DeployManagerContainerRoutingTest.php` | Kept only routing test that exists; removed fictional full-lifecycle command test prose |
| `deploy:history` | `DeployReadCommandsTest.php` | Narrowed human renderer; JSON limited to exercised envelope paths |
| `deploy:log` | `DeployInteractiveInputModeTest.php`, `LogStreamCommandTest.php` | Human log rendering only where asserted |

## Coverage gaps left (explicit prose in docs)

- All six deploy commands: production-app eligibility, exhaustive documented `error.code` matrices, and app-doctor handoff behavior.
- `deploy:step-add`: order/retention gateway validation, interactive abort/title prompts, human failure output.
- `deploy:step-list`: production-app eligibility and human failure output.
- `deploy:step-remove`: step-not-found and consent-failure renderer paths beyond CLI gate tests.
- `deploy:run`: progress-tree rendering, foreground JSON success shape, pipeline-empty and step-failure semantics, history-write failures.
- `deploy:history`: `limit_capped` pagination metadata and human failure prose.
- `deploy:log`: entire JSON renderer (`6.2_deploy-log_output-render_json.md` has no linked test); run header, truncation prose, authorization/run-not-found failures.

## Uncertainty

- `DeployControllerTest.php` only exercises list/create authorization, not DELETE/run/log/history API surfaces; kept only where list/create behavior is documented.
- `DeployStepActionsTest.php` proves gateway order insertion/removal but not CLI-visible title/command validation rules.
- `DeployManagerContainerRoutingTest.php` covers execution routing deeply but not CLI stream UX; left as the only routine backend proof for `deploy:run` execution policy.
- `command-catalog.json` still lists the 27 stale deploy paths until the serialized catalog-regeneration lane runs; domain docs are remediated independently.

## Commands run

```bash
cd /Users/nckrtl/orbit/.worktrees/linked-test-catalog-drift
pwd
git branch --show-current
git status --short --branch
rg -n "apps/gateway/tests/Feature/Commands/Deploy|DeployCommandContractTest|apps/e2e" apps/docs/content/domains/10_deploy
git diff --name-only -- apps/docs/content/domains/10_deploy
php .orbit/evidence/measure-catalog-drift.php  # still shows 27 deploy refs in generated catalog (expected pre-regeneration)
```

## Blockers / risks

- **Blocked on feature owner for catalog sync:** `apps/docs/content/generated/command-catalog.json` and `CommandCatalogTest.php` were intentionally not edited in this lane; drift measurement against the catalog will still report 27 deploy missing refs until regeneration.
- **Risk:** Some canonical contract rows remain broad in product prose while linked tests are narrower; coverage-gap paragraphs record the delta, but docs-librarian review should confirm no remaining overclaims in behavior sections outside Test Mapping.