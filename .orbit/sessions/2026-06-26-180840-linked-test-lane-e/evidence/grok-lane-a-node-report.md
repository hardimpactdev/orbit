# Grok Lane A Linked-Test Audit (partial)

- Families: node, agent-ide, cf-zone, doctor, cf-cache
- Commands audited: 16 (see manifest)
- Missing refs audited: 70
- replace-high-confidence count: 49
- uncertain-unreviewed count: 21 (11 prior uncertain + 6 needs-new-test + 4 e2e-do-not-link-routine)
- remove-no-current-test count: 0

Worktree: `/Users/nckrtl/orbit/.worktrees/linked-test-catalog-drift`  
Branch: `linked-test-catalog-drift`  
Baseline: `f90c817d3`

All 70 manifest paths verified absent on disk. Migration pattern matches landed `tool:install`: CLI → `apps/cli/tests/Feature/Commands/**`, gateway → `apps/gateway/tests/Feature/Http/Api/**`, node writes consolidated in `NodeWriteCommandTest.php`.

## replace-high-confidence (49)

| command | missing path | source doc line(s) | replacement(s) | evidence |
| --- | --- | --- | --- | --- |
| agent-ide:message | `.../AgentIde/AgentIdeMessageCommandTest.php` | `15_agent-ide/.../1_agent-ide-message.md:168` | `apps/cli/tests/Feature/Commands/AgentIde/AgentIdeMessageCommandTest.php`; `apps/gateway/tests/Feature/Http/Api/AgentIdeMessageControllerTest.php` | CLI posts `/api/agent-ide/message`, target/stdin/validation; API covers delivery, adapter diagnostics, auth denial |
| agent-ide:message | `.../AgentIdeMessageHumanRendererTest.php` | `6.1_agent-ide-message_output-render_human.md:123` | `apps/cli/.../AgentIde/AgentIdeMessageCommandTest.php` | Human success prose for app/workspace delivery |
| agent-ide:message | `.../AgentIdeMessageJsonRendererTest.php` | `6.2_agent-ide-message_output-render_json.md:143` | `apps/cli/.../AgentIde/AgentIdeMessageCommandTest.php` | JSON envelope + error data pass-through |
| agent-ide:message | `.../AgentIdeMessageNonInteractiveInputModeTest.php` | `5.2_agent-ide-message_input-mode_non-interactive.md:45` | `apps/cli/.../AgentIde/AgentIdeMessageCommandTest.php` | Missing/conflicting input rejected before gateway IO |
| cf-cache:flush | `.../CfCacheFlushInputModeTest.php` | `5.1_cf-cache-flush_input-mode_interactive.md:32`; `1_cf-cache-flush.md:81`; `5.2_..._non-interactive.md:21` | `apps/cli/tests/Feature/Commands/Cloudflare/CloudflareWriteCommandsTest.php` | Interactive zone prompt + JSON missing-zone validation |
| cf-cache:flush | `.../CfCacheFlushRendererTest.php` | `6.1_cf-cache-flush_output-render_human.md:45`; `6.2_..._json.md:66`; `1_cf-cache-flush.md:82` | `CloudflareRenderCommandsTest.php`; `CloudflareWriteCommandsTest.php` | Human progress-tree flush; JSON validation/errors |
| cf-zone:list | `.../CfZoneListCommandTest.php` | `1_cf-zone-list.md:65` | `CloudflareReadCommandsTest.php`; `CloudflareControllerTest.php` | CLI forwards zones API; gateway auth + provider listing |
| cf-zone:list | `.../CfZoneListRendererTest.php` | `1_cf-zone-list.md:66`; `6.1_..._human.md:50`; `6.2_..._json.md:65` | `CloudflareReadCommandsTest.php` | Human table/empty state; JSON meta count |
| doctor | `.../Operations/DoctorCommandContractTest.php` | `2_doctor_on-client.md:122`; `3_doctor_on-gateway-node.md:135`; `7_doctor_scope-and-authorization.md:44`; `node-doctor.md:363` | `apps/cli/tests/Feature/Commands/Operation/DoctorCommandTest.php`; `apps/gateway/tests/Feature/Http/Api/DoctorRunControllerTest.php` | CLI scope/panel/JSON/stream; API verify scope + auth |
| node role:add | `.../Nodes/NodeRoleAddCommandTest.php` | `1_node-role-add.md:89` (+19,24,28,32) | `NodeWriteCommandTest.php`; `NodeRoleAddControllerTest.php` | CLI post/render/validation; API add/reconverge/reject gateway role |
| node role:list | `.../Nodes/NodeRoleListCommandTest.php` | `1_node-role-list.md:57`; `6.2_..._json.md:47` | `NodeRoleListCommandTest.php`; `NodeRoleListControllerTest.php` | CLI JSON/human/default-node; API list shape |
| node role:remove | `.../Nodes/NodeRoleRemoveCommandTest.php` | `1_node-role-remove.md:69` (+23,24,26,34) | `NodeWriteCommandTest.php`; `NodeRoleRemoveControllerTest.php` | CLI force/purge render; API blocked/force/purge cleanup |
| node:agent-ide | `.../Nodes/NodeAgentIdeCommandTest.php` | `1_node-agent-ide.md:180` (+55,61,102,202) | `NodeWriteCommandTest.php`; `NodeAgentIdeControllerTest.php` | CLI set/clear/converged; API grant/validation/not-found |
| node:agent-ide | `.../NodeAgentIdeHumanRendererTest.php` | `6.1_node-agent-ide_output-render_human.md:108` | `NodeWriteCommandTest.php` | Human set/converged/clear/failure prose |
| node:agent-ide | `.../NodeAgentIdeJsonRendererTest.php` | `6.2_node-agent-ide_output-render_json.md:208` | `NodeWriteCommandTest.php`; `NodeAgentIdeControllerTest.php` | JSON posting + API envelopes |
| node:agent-ide | `.../NodeAgentIdeNonInteractiveInputModeTest.php` | `5.2_node-agent-ide_input-mode_non-interactive.md:61` | `NodeWriteCommandTest.php`; `NodeAgentIdeControllerTest.php` | Explicit args required; API missing/unsupported adapter errors |
| node:default | `apps/cli/.../NodeDefaultHumanRendererTest.php` | `6.1_node-default_output-render_human.md:117`; `1_node-default.md:175` | `NodeDefaultCommandTest.php` | `human renderer` describe block |
| node:default | `apps/cli/.../NodeDefaultJsonRendererTest.php` | `1_node-default.md:174`; `6.2_..._json.md:218` | `NodeDefaultCommandTest.php` | `JSON envelope shape` describe block |
| node:default | `apps/cli/.../NodeDefaultNonInteractiveInputModeTest.php` | `1_node-default.md:173`; `5.2_..._non-interactive.md:63` | `NodeDefaultCommandTest.php` | show/set/clear + mutually exclusive guard |
| node:default | `apps/cli/.../NodeDefaultOnClientTest.php` | `2_node-default_on-client.md:104` | `NodeDefaultCommandTest.php` | Local show/clear; set validates via mocked gateway list |
| node:default | `apps/cli/.../NodeDefaultOnGatewayHostTest.php` | `3_node-default_on-gateway-node.md:39` | `NodeDefaultCommandTest.php` | Local-only config; no gateway default routes |
| node:default | `apps/gateway/.../Nodes/NodeDefaultCommandTest.php` | `1_node-default.md:171` (+38,61,88,103,115,216) | `NodeDefaultCommandTest.php` | Old gateway-placed test superseded by CLI-owned test |
| node:grant | `.../NodeAccessCommandsTest.php` | `1_node-grant.md:171` (+61,228) | `NodeWriteCommandTest.php`; `NodeGrantControllerTest.php` | Grant create/idempotence/policy split across CLI+API |
| node:grant | `.../Nodes/NodeGrantCommandTest.php` | `1_node-grant.md:170` (+60,110,227) | `NodeWriteCommandTest.php`; `NodeGrantControllerTest.php` | Command contract covered jointly |
| node:grant | `.../NodeGrantHumanRendererTest.php` | `6.1_node-grant_output-render_human.md:116` | `NodeWriteCommandTest.php` | Human grant/idempotent/warning prose (lines 1329–1415) |
| node:new | `.../NodeNewCommandTest.php` | `3_node-new_on-gateway-node.md:240` | `NodeWriteCommandTest.php`; `NodeStoreControllerTest.php`; `NodeStoreStreamControllerTest.php` | CLI stream post; API provisions app-dev + SSE creation |
| node:new | `.../NodeNewHumanRendererTest.php` | `6.1_node-new_output-render_human.md:142` | `NodeNewStreamCommandTest.php`; `NodeWriteCommandTest.php` | Streamed progress tree/footer assertions |
| node:new | `.../NodeNewInputContractTest.php` | `1_node-new.md:376` | `NodeWriteCommandTest.php` | Role mutual exclusion, canonical role validation |
| node:new | `.../NodeNewJsonRendererTest.php` | `6.2_node-new_output-render_json.md:497` | `NodeWriteCommandTest.php`; `NodeNewStreamCommandTest.php` | JSON complete frame + stream failure envelope |
| node:new | `.../NodeNewNonInteractiveInputModeTest.php` | `5.2_node-new_input-mode_non-interactive.md:109` | `NodeWriteCommandTest.php` | Non-interactive validation/normalization |
| node:permissions | `.../NodePermissionsCommandTest.php` | `1_node-permissions.md:154` (+26,34,35) | `NodeWriteCommandTest.php`; `NodePermissionsControllerTest.php` | CLI mode/render; API read/replace/add/remove/auth |
| node:remove | `.../NodeAccessCommandsTest.php` | `1_node-remove.md:200` (+58,243) | `NodeWriteCommandTest.php`; `NodeRemoveControllerTest.php` | Remove+grant cleanup via API + CLI delete |
| node:remove | `.../NodeRemoveCommandTest.php` | `1_node-remove.md:198` (+57,58,100,157,242) | `NodeWriteCommandTest.php`; `NodeRemoveControllerTest.php` | Lifecycle, force, self-removal, denial |
| node:remove | `.../NodeRemoveDevelopmentDnsWarningTest.php` | `1_node-remove.md:199` (+164,250) | `apps/gateway/tests/Feature/Http/Api/NodeRemoveDevelopmentDnsWarningTest.php` | Direct relocation; same test names/behavior |
| node:remove | `.../NodeRemoveHumanRendererTest.php` | `6.1_node-remove_output-render_human.md:163` | `NodeWriteCommandTest.php` | Human tree + auth failure prose |
| node:remove | `.../NodeRemoveJsonRendererTest.php` | `6.2_node-remove_output-render_json.md:249` | `NodeWriteCommandTest.php`; `NodeRemoveControllerTest.php` | JSON delete + API envelopes |
| node:remove | `.../NodeRemoveNonInteractiveInputModeTest.php` | `5.2_node-remove_input-mode_non-interactive.md:64` | `NodeWriteCommandTest.php` | `--force` gating + name validation |
| node:revoke | `.../NodeAccessCommandsTest.php` | `1_node-revoke.md:161` (+revoke refs) | `NodeWriteCommandTest.php`; `NodeRevokeControllerTest.php` | Revoke/idempotent/self-lockout/consent |
| node:revoke | `.../NodeRevokeHumanRendererTest.php` | `6.1_node-revoke_output-render_human.md:116` | `NodeWriteCommandTest.php` | Human tree/idempotent/self-lockout (656–733) |
| node:revoke | `.../NodeRevokeJsonRendererTest.php` | `6.2_node-revoke_output-render_json.md:175` | `NodeWriteCommandTest.php`; `NodeRevokeControllerTest.php` | JSON revoke + API payloads |
| node:revoke | `.../NodeRevokeNonInteractiveInputModeTest.php` | `5.2_node-revoke_input-mode_non-interactive.md:64` | `NodeWriteCommandTest.php` | Missing `--force` + arg validation |
| node:show | `.../Nodes/NodeShowCommandTest.php` | `1_node-show.md:136` (+75,63,135,172) | `NodeShowCommandTest.php`; `NodeShowControllerTest.php` | CLI default/JSON/human; API registry read/not-found |
| node:show | `.../NodeShowHumanRendererTest.php` | `6.1_node-show_output-render_human.md:141` | `NodeShowCommandTest.php` | Human field rendering |
| node:show | `.../NodeShowJsonRendererTest.php` | `6.2_node-show_output-render_json.md:178` | `NodeShowCommandTest.php` | Canonical JSON envelope |
| node:show | `.../NodeShowNonInteractiveInputModeTest.php` | `5.2_node-show_input-mode_non-interactive.md:69` | `NodeShowCommandTest.php` | Default resolution + missing-name validation |
| node:update | `.../Nodes/NodeUpdateCommandTest.php` | `1_node-update.md:276` (+63,68,99,148,252) | `NodeWriteCommandTest.php`; `NodeUpdateControllerTest.php` | CLI post/render/drift; API field/TLD/no-op |
| node:update | `.../NodeUpdateHumanRendererTest.php` | `6.1_node-update_output-render_human.md:154` | `NodeWriteCommandTest.php` | Human tree/no-op/drift (1432–1513) |
| node:update | `.../NodeUpdateJsonRendererTest.php` | `6.2_node-update_output-render_json.md:259` | `NodeWriteCommandTest.php`; `NodeUpdateControllerTest.php` | JSON update + API envelopes |
| node:update | `.../NodeUpdateNonInteractiveInputModeTest.php` | `6.2_node-update_output-render_json.md:253` | `NodeWriteCommandTest.php` | Required-input validation dataset |

## uncertain-unreviewed (21)

Prior audit flagged these; not safe for first-patch linkage without further test work or doc narrowing.

| command | missing path | prior sub-class | rationale (abbrev) |
| --- | --- | --- | --- |
| agent-ide:message | `AgentIdeMessageInteractiveInputModeTest.php` | uncertain | Only one interactive prompt test vs extensive doc claims |
| cf-cache:flush | `CfCacheFlushCommandTest.php` | uncertain | No gateway feature test for `CloudflareController::flushCache` |
| node role:add | `NodeRoleJsonRendererTest.php` | uncertain | No envelope-discrimination test |
| node role:remove | `NodeRoleJsonRendererTest.php` | uncertain | Same |
| node:grant | `NodeGrantJsonRendererTest.php` | uncertain | Docs claim every `error.code`; tests cover subset |
| node:grant | `NodeGrantOnOperatorNodeContractTest.php` | uncertain | Mocked CLI HTTP ≠ WireGuard operator forwarding |
| node:new | `E2E/Ephemeral/NodeNewGatewayBootstrapTest.php` | e2e-do-not-link-routine | Real-node smoke; do not routine-link |
| node:new | `E2E/Ephemeral/NodeNewOperatorForwardingTest.php` | e2e-do-not-link-routine | Same |
| node:new | `E2E/NodeNewDevelopmentAppTest.php` | e2e-do-not-link-routine | Same |
| node:new | `E2E/NodeNewProductionAppTest.php` | e2e-do-not-link-routine | Same |
| node:new | `NodeNewInteractiveInputModeTest.php` | needs-new-test | No interactive prompt tests |
| node:new | `NodeNewOnOperatorNodeContractTest.php` | uncertain | Bootstrap only; no post-gateway-add forwarding proof |
| node:permissions | `NodePermissionsJsonRendererTest.php` | uncertain | No envelope-discrimination test |
| node:remove | `NodeRemoveInteractiveInputModeTest.php` | needs-new-test | No interactive confirmation tests |
| node:remove | `NodeRemoveOnOperatorNodeContractTest.php` | uncertain | No operator-topology forwarding proof |
| node:revoke | `NodeRevokeInteractiveInputModeTest.php` | needs-new-test | No interactive confirmation tests |
| node:revoke | `NodeRevokeOnOperatorNodeContractTest.php` | uncertain | Same operator-forwarding gap |
| node:show | `NodeShowInteractiveInputModeTest.php` | needs-new-test | No data-table prompt tests |
| node:update | `NodeUpdateInteractiveInputModeTest.php` | needs-new-test | No interactive field-prompt tests |
| node:update | `NodeUpdateOnOperatorNodeContractTest.php` | uncertain | Same operator-forwarding gap |
| node:agent-ide | `NodeAgentIdeInteractiveInputModeTest.php` | needs-new-test | No interactive prompt tests |

## First-patch recommendation (high-confidence only)

1. **`node:default`** — 6 refs → `apps/cli/tests/Feature/Commands/Node/NodeDefaultCommandTest.php`
2. **`node:remove` DNS warning** — 3 doc lines → `apps/gateway/tests/Feature/Http/Api/NodeRemoveDevelopmentDnsWarningTest.php`
3. **`cf-zone:list`** — `CloudflareReadCommandsTest.php` + `CloudflareControllerTest.php`
4. **`doctor`** — `DoctorCommandTest.php` + `DoctorRunControllerTest.php`
5. **`node role:list`** — `NodeRoleListCommandTest.php` + `NodeRoleListControllerTest.php`

Defer all 21 `uncertain-unreviewed` rows until tests exist or coverage descriptions are narrowed.