# Grok Lane F Linked-Test Audit (Partial)

Worktree: `/Users/nckrtl/orbit/.worktrees/linked-test-catalog-drift`
Baseline: `f90c817d3cbe08584e7859de087272d5995694f2`
Audit date: 2026-06-26
Format: partial — full detail for `replace-high-confidence` only; all other missing refs marked `uncertain-unreviewed` pending consolidated remediation slice.

## Summary
- Families: schedule, firewall, tool, vpn-client, cf-ssl, vpn-web-ui
- Commands audited: cf-ssl:disable, cf-ssl:enable, firewall:allow, firewall:deny, firewall:list, firewall:remove, schedule:add, schedule:list, schedule:logs, schedule:remove, schedule:run, schedule:show, tool:credentials, tool:list, tool:reconfigure, tool:remove, tool:show, tool:update, vpn-client:disable, vpn-client:enable, vpn-client:list, vpn-client:new, vpn-client:remove, vpn-web-ui:change-password
- Missing refs audited: 75
- replace-high-confidence count: 50
- uncertain-unreviewed count: 25
- remove-no-current-test count: (within uncertain-unreviewed) 10
- needs-new-test count: (within uncertain-unreviewed) 1
- e2e-do-not-link-routine count: 0

## Context
All 75 stale paths verified **MISSING** on disk. Gateway-local `apps/gateway/tests/Feature/Commands/*` command tests were retired; proof now lives in `apps/cli/tests/Feature/Commands/**` (CLI contract/renderers) plus `apps/gateway/tests/Feature/Http/Api/**` and unit tests where authorization or backend behavior is described — mirroring the landed `tool:install` slice.

---

## replace-high-confidence (50 rows — full detail)

| command | missing path | source doc line(s) | classification | replacement(s) | evidence / rationale |
| --- | --- | --- | --- | --- | --- |
| schedule:add | apps/gateway/tests/Feature/Commands/Schedule/ScheduleAddCommandTest.php | 9_schedule/1_schedule-add/technical/1_schedule-add.md:105 | replace-high-confidence | apps/cli/tests/Feature/Commands/Schedule/ScheduleWriteCommandTest.php | L10-209: POST /api/schedules, target/source validation, default node, gateway error pass-through. |
| schedule:add | apps/gateway/tests/Feature/Commands/Schedule/ScheduleAddNonInteractiveInputTest.php | 9_schedule/1_schedule-add/technical/5.2_schedule-add_input-mode_non-interactive.md:23 | replace-high-confidence | apps/cli/tests/Feature/Commands/Schedule/ScheduleWriteCommandTest.php | L44-78 default node; L83-121 mutual exclusion + required fields; Http::assertNothingSent(). |
| schedule:remove | apps/gateway/tests/Feature/Commands/Schedule/ScheduleRemoveCommandTest.php | 9_schedule/4_schedule-remove/technical/1_schedule-remove.md:90 | replace-high-confidence | apps/cli/tests/Feature/Commands/Schedule/ScheduleWriteCommandTest.php | L211-263: destructive consent, DELETE payload, scheduler_pickup meta. |
| schedule:remove | apps/gateway/tests/Feature/Commands/Schedule/ScheduleRemoveInteractiveInputTest.php | 9_schedule/4_schedule-remove/technical/5.1_schedule-remove_input-mode_interactive.md:33 | replace-high-confidence | apps/cli/tests/Feature/Commands/Schedule/ScheduleWriteCommandTest.php | L266-284: expectsConfirmation before DELETE. |
| schedule:remove | apps/gateway/tests/Feature/Commands/Schedule/ScheduleRemoveNonInteractiveInputTest.php | 9_schedule/4_schedule-remove/technical/5.2_schedule-remove_input-mode_non-interactive.md:21 | replace-high-confidence | apps/cli/tests/Feature/Commands/Schedule/ScheduleWriteCommandTest.php | L211-230: missing --force => destructive_consent_required; no HTTP sent. |
| schedule:logs | apps/gateway/tests/Feature/Commands/Schedule/ScheduleLogsCommandTest.php | 9_schedule/6_schedule-logs/technical/1_schedule-logs.md:84 | replace-high-confidence | apps/cli/tests/Feature/Commands/Schedule/ScheduleLogsCommandTest.php | L14-49 JSON filter forwarding; L75+ interactive schedule selection. |
| schedule:logs | apps/gateway/tests/Feature/Commands/Schedule/ScheduleLogsHumanRendererTest.php | 9_schedule/6_schedule-logs/technical/6.1_schedule-logs_output-render_human.md:31 | replace-high-confidence | apps/cli/tests/Feature/Commands/Schedule/ScheduleLogsCommandTest.php | L52-72: stdout/stderr human rendering. |
| schedule:run | apps/gateway/tests/Feature/Commands/Schedule/ScheduleRunCommandTest.php | 9_schedule/5_schedule-run/technical/1_schedule-run.md:94 | replace-high-confidence | apps/cli/tests/Feature/Commands/Schedule/ScheduleWriteCommandTest.php | L286-339: POST run endpoint, scope filters, schedule.run_failed envelope. |
| schedule:show | apps/gateway/tests/Feature/Commands/Schedule/ScheduleReadCommandTest.php | 9_schedule/3_schedule-show/technical/6.1_schedule-show_output-render_human.md:55 | replace-high-confidence | apps/cli/tests/Feature/Commands/Schedule/ScheduleShowCommandTest.php | L65-78: human show-detail + last-run summary prose. |
| schedule:show | apps/gateway/tests/Feature/Commands/Schedule/ScheduleShowCommandTest.php | 9_schedule/3_schedule-show/technical/1_schedule-show.md:77 | replace-high-confidence | apps/cli/tests/Feature/Commands/Schedule/ScheduleShowCommandTest.php | Lookup/filter forwarding and gateway error pass-through. |
| schedule:list | apps/gateway/tests/Feature/Commands/Schedule/ScheduleListCommandTest.php | 9_schedule/2_schedule-list/technical/1_schedule-list.md:73 | replace-high-confidence | apps/cli/tests/Feature/Commands/Schedule/ScheduleListCommandTest.php | Filter forwarding, human table with last-run summary, authorization_failed pass-through. |
| firewall:allow | apps/gateway/tests/Feature/Commands/Firewall/FirewallAllowCommandTest.php | 4_firewall/2_firewall-allow/technical/1_firewall-allow.md:90; 5.1:36; 5.2:22 | replace-high-confidence | apps/cli/tests/Feature/Commands/Firewall/FirewallWriteCommandTest.php; FirewallInteractiveInputModeTest.php | Write L10-149 validation+POST; Interactive L20-52 prompts. |
| firewall:allow | apps/gateway/tests/Feature/Commands/Firewall/FirewallAllowHumanRendererTest.php | 4_firewall/2_firewall-allow/technical/6.1_firewall-allow_output-render_human.md:39 | replace-high-confidence | apps/cli/tests/Feature/Commands/Firewall/FirewallWriteCommandTest.php | L248-349: progress tree + backend apply failure recovery. |
| firewall:allow | apps/gateway/tests/Feature/Commands/Firewall/FirewallAllowJsonRendererTest.php | 4_firewall/2_firewall-allow/technical/6.2_firewall-allow_output-render_json.md:81 | replace-high-confidence | apps/cli/tests/Feature/Commands/Firewall/FirewallWriteCommandTest.php | L10-61 JSON success envelope + POST assertion. |
| firewall:deny | apps/gateway/tests/Feature/Commands/Firewall/FirewallDenyCommandTest.php | 4_firewall/3_firewall-deny/technical/1_firewall-deny.md:90; 5.1:36; 5.2:22 | replace-high-confidence | apps/cli/tests/Feature/Commands/Firewall/FirewallWriteCommandTest.php; FirewallInteractiveInputModeTest.php | Write L63-107 default-node deny; Interactive L54-78 prompts. |
| firewall:deny | apps/gateway/tests/Feature/Commands/Firewall/FirewallDenyHumanRendererTest.php | 4_firewall/3_firewall-deny/technical/6.1_firewall-deny_output-render_human.md:39 | replace-high-confidence | apps/cli/tests/Feature/Commands/Firewall/FirewallWriteCommandTest.php | L292-328 deny progress tree prose. |
| firewall:deny | apps/gateway/tests/Feature/Commands/Firewall/FirewallDenyJsonRendererTest.php | 4_firewall/3_firewall-deny/technical/6.2_firewall-deny_output-render_json.md:81 | replace-high-confidence | apps/cli/tests/Feature/Commands/Firewall/FirewallWriteCommandTest.php | Deny JSON payload in write suite L63-107. |
| firewall:remove | apps/gateway/tests/Feature/Commands/Firewall/FirewallRemoveCommandTest.php | 4_firewall/4_firewall-remove/technical/1_firewall-remove.md:86; 5.1:35; 5.2:23 | replace-high-confidence | apps/cli/tests/Feature/Commands/Firewall/FirewallWriteCommandTest.php; FirewallInteractiveInputModeTest.php | Force/consent DELETE L172-228; interactive confirmation L80-108. |
| firewall:remove | apps/gateway/tests/Feature/Commands/Firewall/FirewallRemoveHumanRendererTest.php | 4_firewall/4_firewall-remove/technical/6.1_firewall-remove_output-render_human.md:42 | replace-high-confidence | apps/cli/tests/Feature/Commands/Firewall/FirewallWriteCommandTest.php | L351-427: removed footer, idempotent absence, cleanup failure recovery. |
| firewall:remove | apps/gateway/tests/Feature/Commands/Firewall/FirewallRemoveJsonRendererTest.php | 4_firewall/4_firewall-remove/technical/6.2_firewall-remove_output-render_json.md:85 | replace-high-confidence | apps/cli/tests/Feature/Commands/Firewall/FirewallWriteCommandTest.php | Remove JSON success with destructive_consent L190-228. |
| firewall:list | apps/gateway/tests/Feature/Commands/Firewall/FirewallListCommandTest.php | 4_firewall/1_firewall-list/technical/1_firewall-list.md:69 | replace-high-confidence | apps/cli/tests/Feature/Commands/Firewall/FirewallListCommandTest.php | GET forwarding, filters, authorization_failed L195-206. |
| firewall:list | apps/gateway/tests/Feature/Commands/Firewall/FirewallListJsonRendererTest.php | 4_firewall/1_firewall-list/technical/6.2_firewall-list_output-render_json.md:62 | replace-high-confidence | apps/cli/tests/Feature/Commands/Firewall/FirewallListCommandTest.php | JSON list envelope L30-40. |
| tool:list | apps/gateway/tests/Feature/Commands/Tools/ToolListCommandTest.php | 3_tool/1_tool-list/technical/1_tool-list.md:70; 6.1:35 | replace-high-confidence | apps/cli/tests/Feature/Commands/Tool/ToolListCommandTest.php; apps/gateway/tests/Feature/Http/Api/ToolListControllerTest.php | CLI list/JSON/human; gateway authorization in ToolListControllerTest. |
| tool:list | apps/gateway/tests/Feature/Commands/Tools/ToolListJsonRendererTest.php | 3_tool/1_tool-list/technical/6.2_tool-list_output-render_json.md:66 | replace-high-confidence | apps/cli/tests/Feature/Commands/Tool/ToolListCommandTest.php | L9-39 canonical JSON envelope + filter forwarding. |
| tool:show | apps/gateway/tests/Feature/Commands/Tools/ToolShowCommandTest.php | 3_tool/2_tool-show/technical/1_tool-show.md:88; 6.1:55 | replace-high-confidence | apps/cli/tests/Feature/Commands/Tool/ToolShowCommandTest.php; apps/gateway/tests/Feature/Http/Api/ToolShowControllerTest.php | CLI show detail human + live flag forwarding. |
| tool:remove | apps/gateway/tests/Feature/Commands/Tools/ToolRemoveCommandTest.php | 3_tool/4_tool-remove/technical/1_tool-remove.md:88; 5.1:25; 5.2:24; 6.1:56 | replace-high-confidence | apps/cli/tests/Feature/Commands/Tool/ToolWriteCommandTest.php; apps/gateway/tests/Feature/Http/Api/ToolRemoveControllerTest.php | CLI consent/interactive remove L197-260; gateway API consent. |
| tool:remove | apps/gateway/tests/Feature/Commands/Tools/ToolRemoveJsonRendererTest.php | 3_tool/4_tool-remove/technical/1_tool-remove.md:89; 6.2:92 | replace-high-confidence | apps/cli/tests/Feature/Commands/Tool/ToolWriteCommandTest.php | L197-228: --json destructive consent + DELETE assertions. |
| tool:update | apps/gateway/tests/Feature/Commands/Tools/ToolUpdateCommandTest.php | 3_tool/9_tool-update/technical/1_tool-update.md:76; 6.1:39 | replace-high-confidence | apps/cli/tests/Feature/Commands/Tool/ToolWriteCommandTest.php; ToolStreamCommandTest.php | Streamed update single/bulk (Write L266-337, Stream L94-171). |
| tool:update | apps/gateway/tests/Feature/Commands/Tools/ToolUpdateJsonRendererTest.php | 3_tool/9_tool-update/technical/6.2_tool-update_output-render_json.md:62 | replace-high-confidence | apps/cli/tests/Feature/Commands/Tool/ToolStreamCommandTest.php | Stream complete frame / gateway stream request shape. |
| tool:credentials | apps/gateway/tests/Feature/Commands/Tools/ToolCredentialsCommandTest.php | 3_tool/10_tool-credentials/technical/1_tool-credentials.md:73; 5.1:19; 5.2:17; 6.1:45 | replace-high-confidence | apps/cli/tests/Feature/Commands/Tool/ToolCredentialsCommandTest.php; apps/gateway/tests/Feature/Http/Api/ToolCredentialsControllerTest.php | CLI JSON/human/interactive/default node L13-146+. |
| tool:credentials | apps/gateway/tests/Feature/Commands/Tools/ToolCredentialsJsonRendererTest.php | 3_tool/10_tool-credentials/technical/6.2_tool-credentials_output-render_json.md:67 | replace-high-confidence | apps/cli/tests/Feature/Commands/Tool/ToolCredentialsCommandTest.php | L13-50 JSON credentials verbatim + redaction assertions. |
| tool:reconfigure | apps/gateway/tests/Feature/Commands/Tools/ToolReconfigureCommandTest.php | 3_tool/12_tool-reconfigure/technical/1_tool-reconfigure.md:85; 5.1:19; 5.2:17; 6.1:39 | replace-high-confidence | apps/cli/tests/Feature/Commands/Tool/ToolWriteCommandTest.php; ToolStreamCommandTest.php | Stream reconfigure payloads (Write L339-347, Stream L171-181). |
| tool:reconfigure | apps/gateway/tests/Feature/Commands/Tools/ToolReconfigureJsonRendererTest.php | 3_tool/12_tool-reconfigure/technical/6.2_tool-reconfigure_output-render_json.md:62 | replace-high-confidence | apps/cli/tests/Feature/Commands/Tool/ToolStreamCommandTest.php | Stream JSON frames for tool:reconfigure. |
| vpn-client:list | apps/gateway/tests/Feature/Commands/Vpn/VpnClientListCommandTest.php | 13_vpn/1_vpn-client-list/technical/1_vpn-client-list.md:86 | replace-high-confidence | apps/cli/tests/Feature/Commands/Vpn/VpnCommandsTest.php; VpnControllerActivityTest.php; VpnClientManagerTest.php | CLI list L34-131; API grant denial L103-127; peer classification L16-38. Trim stale SSH doc wording. |
| vpn-client:list | apps/gateway/tests/Feature/Commands/Vpn/VpnClientListRendererTest.php | 13_vpn/1_vpn-client-list/technical/1_vpn-client-list.md:87; 6.1:54; 6.2:69 | replace-high-confidence | apps/cli/tests/Feature/Commands/Vpn/VpnCommandsTest.php | Human table L70-131 + JSON list L34-67. |
| vpn-client:new | apps/gateway/tests/Feature/Commands/Vpn/VpnClientNewCommandTest.php | 13_vpn/2_vpn-client-new/technical/1_vpn-client-new.md:101 | replace-high-confidence | apps/cli/tests/Feature/Commands/Vpn/VpnCommandsTest.php; VpnClientManagerTest.php; VpnControllerActivityTest.php | CLI create L150-261; node-name collision L41-56; write grant denial L196-219. |
| vpn-client:new | apps/gateway/tests/Feature/Commands/Vpn/VpnClientNewRendererTest.php | 13_vpn/2_vpn-client-new/technical/1_vpn-client-new.md:102; 6.1:59; 6.2:72 | replace-high-confidence | apps/cli/tests/Feature/Commands/Vpn/VpnCommandsTest.php | Human progress tree L492-590 + JSON validation failures. |
| vpn-client:enable | apps/gateway/tests/Feature/Commands/Vpn/VpnClientEnableCommandTest.php | 13_vpn/3_vpn-client-enable/technical/1_vpn-client-enable.md:79 | replace-high-confidence | apps/cli/tests/Feature/Commands/Vpn/VpnCommandsTest.php; VpnControllerActivityTest.php | CLI enable POST L261-287; API enable L162-194. |
| vpn-client:enable | apps/gateway/tests/Feature/Commands/Vpn/VpnClientEnableRendererTest.php | 13_vpn/3_vpn-client-enable/technical/1_vpn-client-enable.md:80; 6.1:49; 6.2:65 | replace-high-confidence | apps/cli/tests/Feature/Commands/Vpn/VpnCommandsTest.php | Enable/disable renderer datasets L608-698. |
| vpn-client:disable | apps/gateway/tests/Feature/Commands/Vpn/VpnClientDisableCommandTest.php | 13_vpn/4_vpn-client-disable/technical/1_vpn-client-disable.md:80 | replace-high-confidence | apps/cli/tests/Feature/Commands/Vpn/VpnCommandsTest.php; VpnControllerActivityTest.php | CLI disable POST; API disable L186-194. |
| vpn-client:disable | apps/gateway/tests/Feature/Commands/Vpn/VpnClientDisableRendererTest.php | 13_vpn/4_vpn-client-disable/technical/1_vpn-client-disable.md:81; 6.1:49; 6.2:65 | replace-high-confidence | apps/cli/tests/Feature/Commands/Vpn/VpnCommandsTest.php | Shared enable/disable renderer tests L608-698. |
| vpn-client:remove | apps/gateway/tests/Feature/Commands/Vpn/VpnClientRemoveCommandTest.php | 13_vpn/5_vpn-client-remove/technical/1_vpn-client-remove.md:90 | replace-high-confidence | apps/cli/tests/Feature/Commands/Vpn/VpnCommandsTest.php; VpnClientManagerTest.php | CLI force gate + DELETE L290-343; node-peer protection in manager test. |
| vpn-client:remove | apps/gateway/tests/Feature/Commands/Vpn/VpnClientRemoveInteractiveInputModeTest.php | 13_vpn/5_vpn-client-remove/technical/1_vpn-client-remove.md:91; 5.1:40 | replace-high-confidence | apps/cli/tests/Feature/Commands/Vpn/VpnCommandsTest.php | L345-366 interactive name prompt before DELETE. |
| vpn-client:remove | apps/gateway/tests/Feature/Commands/Vpn/VpnClientRemoveNonInteractiveInputModeTest.php | 13_vpn/5_vpn-client-remove/technical/1_vpn-client-remove.md:92; 5.2:29 | replace-high-confidence | apps/cli/tests/Feature/Commands/Vpn/VpnCommandsTest.php | L290-313 missing --force before HTTP. |
| vpn-client:remove | apps/gateway/tests/Feature/Commands/Vpn/VpnClientRemoveRendererTest.php | 13_vpn/5_vpn-client-remove/technical/1_vpn-client-remove.md:93; 6.1:60; 6.2:78 | replace-high-confidence | apps/cli/tests/Feature/Commands/Vpn/VpnCommandsTest.php | Human remove tree + missing-force prose L699-776. |
| vpn-web-ui:change-password | apps/gateway/tests/Feature/Commands/Vpn/VpnWebUiChangePasswordCommandTest.php | 13_vpn/6_vpn-web-ui-change-password/technical/1_vpn-web-ui-change-password.md:97 | replace-high-confidence | apps/cli/tests/Feature/Commands/Vpn/VpnCommandsTest.php; VpnControllerActivityTest.php | CLI rotation JSON/interactive/validation L368-450. |
| vpn-web-ui:change-password | apps/gateway/tests/Feature/Commands/Vpn/VpnWebUiChangePasswordInteractiveInputModeTest.php | 13_vpn/6_vpn-web-ui-change-password/technical/1_vpn-web-ui-change-password.md:98; 5.1:43 | replace-high-confidence | apps/cli/tests/Feature/Commands/Vpn/VpnCommandsTest.php | L405-432 password + confirmation prompts; secret redaction. |
| vpn-web-ui:change-password | apps/gateway/tests/Feature/Commands/Vpn/VpnWebUiChangePasswordNonInteractiveInputModeTest.php | 13_vpn/6_vpn-web-ui-change-password/technical/1_vpn-web-ui-change-password.md:99; 5.2:32 | replace-high-confidence | apps/cli/tests/Feature/Commands/Vpn/VpnCommandsTest.php | L434-450 missing password/short password/missing force before HTTP. |
| vpn-web-ui:change-password | apps/gateway/tests/Feature/Commands/Vpn/VpnWebUiChangePasswordRendererTest.php | 13_vpn/6_vpn-web-ui-change-password/technical/1_vpn-web-ui-change-password.md:100; 6.1:59; 6.2:78 | replace-high-confidence | apps/cli/tests/Feature/Commands/Vpn/VpnCommandsTest.php | Human tree w/o secret leak L778-817; failure prose L818-846. |
| cf-ssl:disable | apps/gateway/tests/Feature/Commands/Cloudflare/CfSslDisableRendererTest.php | 12_cf/9_cf-ssl-disable/technical/1_cf-ssl-disable.md:78; 6.1:46; 6.2:67 | replace-high-confidence | apps/cli/tests/Feature/Commands/Cloudflare/CloudflareRenderCommandsTest.php; CloudflareWriteCommandsTest.php | Human disable tree Render L279-307; JSON missing-consent Write L252-279. |

---

## uncertain-unreviewed (25 rows — compact)

Prior scan notes included for remediation planning; not promoted to replace-high-confidence in this partial report.

| command | missing path | prior-scan hint | classification |
| --- | --- | --- | --- |
| schedule:add | ScheduleAddHumanRendererTest.php | CLI progress tree exists; application-drift recovery unproven | uncertain-unreviewed |
| schedule:add | ScheduleAddInteractiveInputTest.php | No schedule:add interactive tests in CLI suite | uncertain-unreviewed |
| schedule:add | ScheduleAddJsonRendererTest.php | Partial JSON coverage; exhaustive error.code claim unmet | uncertain-unreviewed |
| schedule:add | ScheduleCommandContractTest.php | File never existed; no SchedulePayload unit test | uncertain-unreviewed |
| schedule:remove | ScheduleRemoveHumanRendererTest.php | Cleanup-drift recovery output unproven | uncertain-unreviewed |
| schedule:remove | ScheduleRemoveJsonRendererTest.php | Partial JSON; every error.code unproven | uncertain-unreviewed |
| schedule:remove | ScheduleCommandContractTest.php | File never existed | uncertain-unreviewed |
| schedule:logs | ScheduleLogsJsonRendererTest.php | Core JSON ok; run-not-found/log-read codes partial | uncertain-unreviewed |
| schedule:logs | ScheduleCommandContractTest.php | File never existed | uncertain-unreviewed |
| schedule:run | ScheduleRunHumanRendererTest.php | No streamed output / history-write failure cases | uncertain-unreviewed |
| schedule:run | ScheduleRunJsonRendererTest.php | Partial JSON failure shapes | uncertain-unreviewed |
| schedule:run | ScheduleCommandContractTest.php | File never existed | uncertain-unreviewed |
| schedule:show | ScheduleShowJsonRendererTest.php | not-found + every error.code unproven | uncertain-unreviewed |
| schedule:show | ScheduleCommandContractTest.php | File never existed | uncertain-unreviewed |
| schedule:list | ScheduleListJsonRendererTest.php | every error.code claim unproven | uncertain-unreviewed |
| schedule:list | ScheduleCommandContractTest.php | File never existed | uncertain-unreviewed |
| firewall:allow | FirewallCommandContractTest.php | File never existed | uncertain-unreviewed |
| firewall:deny | FirewallCommandContractTest.php | File never existed | uncertain-unreviewed |
| firewall:remove | FirewallCommandContractTest.php | File never existed | uncertain-unreviewed |
| firewall:list | FirewallCommandContractTest.php | File never existed | uncertain-unreviewed |
| tool:show | ToolShowJsonRendererTest.php | Remote inspection failure shapes unclear in CLI tests | uncertain-unreviewed |
| cf-ssl:disable | CfSslDisableCommandTest.php | Provider failure / zone-resolution gaps | uncertain-unreviewed |
| cf-ssl:disable | CfSslDisableInputModeTest.php | Non-interactive force ok; interactive confirm missing | uncertain-unreviewed |
| cf-ssl:enable | CfSslEnableCommandTest.php | flexible refusal / provider failures unproven | uncertain-unreviewed |
| cf-ssl:enable | CfSslEnableRendererTest.php | full-mode human + invalid-mode errors unproven | uncertain-unreviewed |

---

## First-patch recommendation (high-confidence only)

Land one docs/catalog batch for **50 replace-high-confidence refs**:

1. **Firewall (12 refs)** → `FirewallWriteCommandTest.php`, `FirewallListCommandTest.php`, `FirewallInteractiveInputModeTest.php`
2. **VPN + vpn-web-ui (18 refs)** → `VpnCommandsTest.php` + `VpnControllerActivityTest.php` + `VpnClientManagerTest.php`; trim SSH wording
3. **Tool (11 refs)** → `apps/cli/tests/Feature/Commands/Tool/*` + gateway `Tool*ControllerTest.php`
4. **Schedule core (10 refs)** → `ScheduleWriteCommandTest.php`, `ScheduleListCommandTest.php`, `ScheduleShowCommandTest.php`, `ScheduleLogsCommandTest.php`
5. **cf-ssl:disable renderer (1 ref)** → `CloudflareRenderCommandsTest.php` + `CloudflareWriteCommandsTest.php`

**Defer (25 uncertain-unreviewed):** contract-test removals, schedule:add interactive, JSON exhaustive error.code rows, cf-ssl command/input-mode/enable family.