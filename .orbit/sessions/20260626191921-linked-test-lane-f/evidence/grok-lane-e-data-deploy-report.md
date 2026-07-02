# Grok Lane E Linked-Test Audit (partial, evidence-backed)

- Families: database, deploy, cf-dns, cf-cache-rule
- Commands audited: 22
- Missing refs audited: 67
- replace-high-confidence count: 45
- uncertain-unreviewed count: 22
  - uncertain (partial overlap): 12
  - remove-no-current-test: 7
  - needs-new-test: 3
- e2e-do-not-link-routine count: 0

Worktree: `/Users/nckrtl/orbit/.worktrees/linked-test-catalog-drift` · branch `linked-test-catalog-drift`

## Verification performed

- All 67 manifest paths confirmed **MISSING** on disk.
- Replacement search limited to `apps/cli/tests`, `apps/gateway/tests`, `packages/core/tests`, `packages/sdk/tests`, `apps/e2e/tests`.
- Pattern: stale `apps/gateway/tests/Feature/Commands/*` → consolidated `apps/cli/tests/Feature/Commands/*`.

## replace-high-confidence (45 rows)

| command | missing path | source doc line(s) | replacement(s) | evidence |
| --- | --- | --- | --- | --- |
| deploy:step-add | `DeployStepAddHumanRendererTest.php` | `10_deploy/1_deploy-step-add/technical/6.1_deploy-step-add_output-render_human.md:35` | `apps/cli/tests/Feature/Commands/Deploy/DeployWriteCommandsTest.php` | L244–308: human success lines, retention/unlimited |
| deploy:step-add | `DeployStepAddInteractiveInputTest.php` | `10_deploy/1_deploy-step-add/technical/5.1_deploy-step-add_input-mode_interactive.md:37` | `apps/cli/tests/Feature/Commands/Deploy/DeployInteractiveInputModeTest.php` | L9–34: App+Command prompts, POST `/api/deploy/steps` |
| deploy:step-add | `DeployStepAddJsonRendererTest.php` | `10_deploy/1_deploy-step-add/technical/6.2_deploy-step-add_output-render_json.md:68` | `apps/cli/tests/Feature/Commands/Deploy/DeployWriteCommandsTest.php` | L9–55: `--json` envelope `success.data.step`, `meta.action=created` |
| deploy:step-add | `DeployStepAddNonInteractiveInputTest.php` | `10_deploy/1_deploy-step-add/technical/5.2_deploy-step-add_input-mode_non-interactive.md:26` | `apps/cli/tests/Feature/Commands/Deploy/DeployWriteCommandsTest.php` | L57–97: missing command + invalid timeout, `Http::assertNothingSent()` |
| deploy:step-remove | `DeployStepRemoveHumanRendererTest.php` | `10_deploy/3_deploy-step-remove/technical/6.1_deploy-step-remove_output-render_human.md:36` | `apps/cli/tests/Feature/Commands/Deploy/DeployWriteCommandsTest.php` | L310–342: removed-step summary, `history preserved` |
| deploy:step-remove | `DeployStepRemoveInteractiveInputTest.php` | `10_deploy/3_deploy-step-remove/technical/5.1_deploy-step-remove_input-mode_interactive.md:38` | `apps/cli/tests/Feature/Commands/Deploy/DeployInteractiveInputModeTest.php` | L63–90: prompts + `expectsConfirmation` |
| deploy:step-remove | `DeployStepRemoveJsonRendererTest.php` | `10_deploy/3_deploy-step-remove/technical/6.2_deploy-step-remove_output-render_json.md:70` | `apps/cli/tests/Feature/Commands/Deploy/DeployWriteCommandsTest.php` | L120–160: JSON DELETE success, `history_preserved` |
| deploy:step-remove | `DeployStepRemoveNonInteractiveInputTest.php` | `10_deploy/3_deploy-step-remove/technical/5.2_deploy-step-remove_input-mode_non-interactive.md:26` | `apps/cli/tests/Feature/Commands/Deploy/DeployWriteCommandsTest.php` | L99–118: `destructive_consent_required` before HTTP |
| deploy:run | `DeployRunInteractiveInputTest.php` | `10_deploy/4_deploy-run/technical/5.1_deploy-run_input-mode_interactive.md:34` | `apps/cli/tests/Feature/Commands/Deploy/DeployInteractiveInputModeTest.php` | L36–61: App prompt, stream POST `/api/deploy/run` |
| deploy:run | `DeployRunNonInteractiveInputTest.php` | `10_deploy/4_deploy-run/technical/5.2_deploy-run_input-mode_non-interactive.md:29` | `apps/cli/tests/Feature/Commands/Deploy/DeployWriteCommandsTest.php` | L205–222: missing `app` validation before gateway |
| deploy:log | `DeployLogHumanRendererTest.php` | `10_deploy/6_deploy-log/technical/6.1_deploy-log_output-render_human.md:51` | `apps/cli/tests/Feature/Commands/LogStreamCommandTest.php` | L64–111: step status + stdout/stderr grouping |
| deploy:history | `DeployHistoryCommandTest.php` | `10_deploy/5_deploy-history/technical/1_deploy-history.md:77` | `apps/cli/tests/Feature/Commands/Deploy/DeployReadCommandsTest.php` | `describe('deploy:history')` L8–215: list, limit, empty, auth passthrough |
| deploy:history | `DeployHistoryJsonRendererTest.php` | `10_deploy/5_deploy-history/technical/6.2_deploy-history_output-render_json.md:89` | `apps/cli/tests/Feature/Commands/Deploy/DeployReadCommandsTest.php` | L9–50, L147–166: JSON envelope + meta |
| deploy:step-list | `DeployStepListCommandTest.php` | `10_deploy/2_deploy-step-list/technical/1_deploy-step-list.md:68` | `apps/cli/tests/Feature/Commands/Deploy/DeployReadCommandsTest.php` | `describe('deploy:step-list')` L217–390 |
| deploy:step-list | `DeployStepListJsonRendererTest.php` | `10_deploy/2_deploy-step-list/technical/6.2_deploy-step-list_output-render_json.md:71` | `apps/cli/tests/Feature/Commands/Deploy/DeployReadCommandsTest.php` | L218–260: JSON steps + meta count |
| cf-cache-rule:remove | `CfCacheRuleRemoveRendererTest.php` | `12_cf/7_cf-cache-rule-remove/technical/1_cf-cache-rule-remove.md:84` (+ 6.1/6.2) | `CloudflareRenderCommandsTest.php`; `CloudflareWriteCommandsTest.php` | Render L215–246 human tree; Write L226–233 JSON DELETE |
| cf-dns:remove | `CfDnsRemoveRendererTest.php` | `12_cf/4_cf-dns-remove/technical/1_cf-dns-remove.md:80` (+ 6.1/6.2) | `apps/cli/tests/Feature/Commands/Cloudflare/CloudflareRenderCommandsTest.php` | L97–148: progress tree, removed line, no-tree w/o force |
| cf-cache-rule:add | `CfCacheRuleAddRendererTest.php` | `12_cf/6_cf-cache-rule-add/technical/1_cf-cache-rule-add.md:76` (+ 6.1/6.2) | `apps/cli/tests/Feature/Commands/Cloudflare/CloudflareRenderCommandsTest.php` | L179–213: progress tree + ready line |
| cf-dns:add | `CfDnsAddRendererTest.php` | `12_cf/3_cf-dns-add/technical/1_cf-dns-add.md:79` (+ 6.1/6.2) | `apps/cli/tests/Feature/Commands/Cloudflare/CloudflareRenderCommandsTest.php` | L8–95: created, already-present, failure prose |
| cf-dns:list | `CfDnsListCommandTest.php` | `12_cf/2_cf-dns-list/technical/1_cf-dns-list.md:73` | `apps/cli/tests/Feature/Commands/Cloudflare/CloudflareReadCommandsTest.php` | `describe('cf-dns:list')` L103–255 |
| cf-dns:list | `CfDnsListRendererTest.php` | `12_cf/2_cf-dns-list/technical/1_cf-dns-list.md:74` (+ 6.1/6.2) | `apps/cli/tests/Feature/Commands/Cloudflare/CloudflareReadCommandsTest.php` | L104–205: human table + JSON envelope |
| database:add | `DatabaseAddJsonRendererTest.php` | `18_database/3_database-add/technical/6.2_database-add_output-render_json.md:32` | `apps/cli/tests/Feature/Commands/Database/DatabaseWriteCommandsTest.php` | L77–125: `--json` connection slug |
| database:add | `DatabaseRegistryCommandTest.php` | `18_database/3_database-add/technical/6.1_database-add_output-render_human.md:26` | `apps/cli/tests/Feature/Commands/Database/DatabaseWriteCommandsTest.php` | L525–547: human created line |
| database:attach | `DatabaseAttachCommandTest.php` | `18_database/6_database-attach/technical/1_database-attach.md:79` | `apps/cli/tests/Feature/Commands/Database/DatabaseWriteCommandsTest.php` | L176–314: scope validation, attach payloads |
| database:attach | `DatabaseAttachJsonRendererTest.php` | `18_database/6_database-attach/technical/6.2_database-attach_output-render_json.md:26` | `apps/cli/tests/Feature/Commands/Database/DatabaseWriteCommandsTest.php` | L176–239 |
| database:attach | `DatabaseRegistryCommandTest.php` | `18_database/6_database-attach/technical/6.1_database-attach_output-render_human.md:26` | `apps/cli/tests/Feature/Commands/Database/DatabaseWriteCommandsTest.php` | L594–644 |
| database:detach | `DatabaseDetachCommandTest.php` | `18_database/7_database-detach/technical/1_database-detach.md:75` | `apps/cli/tests/Feature/Commands/Database/DatabaseWriteCommandsTest.php` | L241–343 |
| database:detach | `DatabaseDetachJsonRendererTest.php` | `18_database/7_database-detach/technical/6.2_database-detach_output-render_json.md:26` | `apps/cli/tests/Feature/Commands/Database/DatabaseWriteCommandsTest.php` | L241–271 |
| database:detach | `DatabaseRegistryCommandTest.php` | `18_database/7_database-detach/technical/6.1_database-detach_output-render_human.md:26` | `apps/cli/tests/Feature/Commands/Database/DatabaseWriteCommandsTest.php` | L646–671 |
| database:list | `DatabaseListCommandTest.php` | `18_database/1_database-list/technical/1_database-list.md:84` | `apps/cli/tests/Feature/Commands/Database/DatabaseReadCommandsTest.php` | `describe('database:list')` L8–167 |
| database:list | `DatabaseListJsonRendererTest.php` | `18_database/1_database-list/technical/1_database-list.md:85`; `6.2:55` | `apps/cli/tests/Feature/Commands/Database/DatabaseReadCommandsTest.php` | L9–91 |
| database:list | `DatabaseRegistryCommandTest.php` | `18_database/1_database-list/technical/6.1_database-list_output-render_human.md:26` | `apps/cli/tests/Feature/Commands/Database/DatabaseReadCommandsTest.php` | L106–166 |
| database:remove | `DatabaseRemoveCommandTest.php` | `18_database/5_database-remove/technical/5.1:37`; `1_database-remove.md:79` | `apps/cli/tests/Feature/Commands/Database/DatabaseWriteCommandsTest.php` | L369–438: force + interactive confirm |
| database:remove | `DatabaseRemoveJsonRendererTest.php` | `18_database/5_database-remove/technical/6.2_database-remove_output-render_json.md:26` | `apps/cli/tests/Feature/Commands/Database/DatabaseWriteCommandsTest.php` | L369–415 |
| database:remove | `DatabaseRegistryCommandTest.php` | `18_database/5_database-remove/technical/6.1_database-remove_output-render_human.md:26` | `apps/cli/tests/Feature/Commands/Database/DatabaseWriteCommandsTest.php` | L571–592 |
| database:show | `DatabaseRegistryCommandTest.php` | `18_database/2_database-show/technical/6.1_database-show_output-render_human.md:47` | `apps/cli/tests/Feature/Commands/Database/DatabaseReadCommandsTest.php` | L237–320: show-detail tree |
| database:update | `DatabaseUpdateCommandTest.php` | `18_database/4_database-update/technical/1_database-update.md:79` | `apps/cli/tests/Feature/Commands/Database/DatabaseWriteCommandsTest.php` | L127–174 |
| database:update | `DatabaseUpdateJsonRendererTest.php` | `18_database/4_database-update/technical/6.2_database-update_output-render_json.md:26` | `apps/cli/tests/Feature/Commands/Database/DatabaseWriteCommandsTest.php` | L127–154 |
| database:update | `DatabaseRegistryCommandTest.php` | `18_database/4_database-update/technical/6.1_database-update_output-render_human.md:26` | `apps/cli/tests/Feature/Commands/Database/DatabaseWriteCommandsTest.php` | L549–569 |
| database:describe | `DatabaseDescribeCommandTest.php` | `18_database/11_database-describe/technical/1_database-describe.md:71` | `apps/cli/tests/Feature/Commands/Database/DatabaseReadCommandsTest.php` | `describe('database:describe')` L474–515 |
| database:describe | `DatabaseSchemaCommandTest.php` | `18_database/11_database-describe/technical/6.1_database-describe_output-render_human.md:25` | `apps/cli/tests/Feature/Commands/Database/DatabaseReadCommandsTest.php` | L517–554: describe human table |
| database:query | `DatabaseQueryCommandTest.php` | `18_database/8_database-query/technical/1_database-query.md:79` | `apps/cli/tests/Feature/Commands/Database/DatabaseWriteCommandsTest.php` | L440–506 |
| database:query | `DatabaseQueryJsonRendererTest.php` | `18_database/8_database-query/technical/6.2_database-query_output-render_json.md:26` | `apps/cli/tests/Feature/Commands/Database/DatabaseWriteCommandsTest.php` | L440–487: strict JSON w/o `--json` |
| database:tables | `DatabaseTablesCommandTest.php` | `18_database/9_database-tables/technical/1_database-tables.md:71` | `apps/cli/tests/Feature/Commands/Database/DatabaseReadCommandsTest.php` | `describe('database:tables')` L323–402 |
| database:schema | `DatabaseSchemaCommandTest.php` | `18_database/10_database-schema/technical/1_database-schema.md:71` | `apps/cli/tests/Feature/Commands/Database/DatabaseReadCommandsTest.php` | `describe('database:schema')` L405–472 |

Paths above are under `apps/gateway/tests/Feature/Commands/{Deploy,Database,Cloudflare}/` unless noted as `DeployCommandContractTest.php` (unit).

## uncertain-unreviewed (22 rows)

Not re-emitted row-by-row in this partial report. Prior pass bucketed as:

| bucket | count | missing paths (summary) |
| --- | --- | --- |
| uncertain (partial CLI/gateway overlap, doc overclaims) | 12 | `DeployStepAddCommandTest`, `DeployStepRemoveCommandTest`, `DeployCommandTest`, `DeployRunJsonRendererTest`, `DeployLogCommandTest`, `CfCacheRuleRemoveCommandTest`, `CfDnsRemoveCommandTest`, `CfCacheRuleAddCommandTest`, `CfDnsAddCommandTest`, `DatabaseAddCommandTest`, `DatabaseShowCommandTest`, `DatabaseShowJsonRendererTest` |
| remove-no-current-test | 7 | six `DeployCommandContractTest.php` refs (all deploy commands) + `database:tables` stale `DatabaseSchemaCommandTest` human row |
| needs-new-test | 3 | `DeployLogJsonRendererTest`, `CfDnsRemoveInputModeTest`, `CfCacheRuleRemoveInputModeTest` |

## First-patch recommendation (high-confidence only)

1. Repoint the 45 rows above from `apps/gateway/tests/Feature/Commands/...` → listed `apps/cli/tests/Feature/Commands/...` files; **narrow** coverage text (drop “every `error.code`” unless proven).
2. In the same patch, **remove** the seven `remove-no-current-test` rows (do not substitute).
3. **Defer** all 22 `uncertain-unreviewed` rows until a follow-up lane or new tests land.

CLI replacement spine:

- Deploy: `DeployWriteCommandsTest.php`, `DeployReadCommandsTest.php`, `DeployInteractiveInputModeTest.php`, `LogStreamCommandTest.php`
- Cloudflare: `CloudflareReadCommandsTest.php`, `CloudflareWriteCommandsTest.php`, `CloudflareRenderCommandsTest.php`
- Database: `DatabaseReadCommandsTest.php`, `DatabaseWriteCommandsTest.php`