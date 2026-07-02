# Grok Lane C Linked-Test Audit (partial)

- Families: process, gateway, proxy, update
- Commands audited: 17 (per manifest)
- Missing refs audited: 66 (all verified absent on disk)
- replace-high-confidence: 48
- uncertain-unreviewed: 18 (9 prior `uncertain` + 9 prior `needs-new-test`; not safe for first patch)
- remove-no-current-test: 0
- e2e-do-not-link-routine: 0

## Pattern

Stale paths point at deleted `apps/gateway/tests/Feature/Commands/{Gateway,Processes,Proxy,Operations}/…` files. CLI command tests now live under `apps/cli/tests/Feature/Commands/`. Server grant/registry/runtime semantics remain in `apps/gateway/tests/Feature/Http/Api/` and `apps/gateway/tests/Unit/Services/`.

## replace-high-confidence (48 paths)

| command | stale path (basename) | replacement | primary source doc line(s) | evidence |
| --- | --- | --- | --- | --- |
| gateway:add | GatewayAddHumanRendererTest | `apps/cli/tests/Feature/Commands/Gateway/GatewayAddCommandTest.php` | `1_gateway-add.md:182` | Human progress tree, added/converged footers, validation/unavailable prose (~390–450) |
| gateway:add | GatewayAddInputContractTest | same | `1_gateway-add.md:178` | Validation, CA trust, `/api/me` (faked), settings persistence, converged (~180–377) |
| gateway:add | GatewayAddJsonRendererTest | same | `1_gateway-add.md:181` | JSON `action=added/converged`, error codes |
| gateway:add | GatewayAddNonInteractiveInputModeTest | same | `1_gateway-add.md:180` | Missing/invalid IP, `--json` non-interactive |
| gateway:trust | GatewayTrustCommandTest | `apps/cli/tests/Feature/Commands/Gateway/GatewayTrustCommandTest.php` | `1_gateway-trust.md:186` | Full trust contract incl. already_trusted, no `/api/me` |
| gateway:trust | GatewayTrustHumanRendererTest | same | `1_gateway-trust.md:189` | Human tree + footers (~250–302) |
| gateway:trust | GatewayTrustJsonRendererTest | same | `1_gateway-trust.md:188` | JSON success/error codes (~136–232) |
| gateway:list | GatewayListCommandTest | `apps/cli/tests/Feature/Commands/Gateway/GatewaySelectionCommandTest.php` | `1_gateway-list.md:67` (+ renderer companions) | `describe('gateway:list')` JSON/human/empty-config (~65–135) |
| gateway:use | GatewayUseCommandTest | same (`describe('gateway:use')`) | `1_gateway-use.md:65` (+ companions) | Switch active, unknown name, human/JSON (~154–206) |
| gateway:status | GatewayStatusCommandTest | `apps/cli/tests/Feature/Commands/GatewayStatusCommandTest.php` + `apps/cli/tests/Feature/PublicCommandForwardingTest.php` | `1_gateway-status.md:87` (+ companions) | Human + error JSON in former; JSON success `meta.endpoint` only in latter (~56–68) |
| process:list | ProcessListHumanRendererTest | `apps/cli/tests/Feature/Commands/Process/ProcessListCommandTest.php` | `6.1_process-list_output-render_human.md:48` | Table, status derivation, empty state (~44–121) |
| process:list | ProcessListInputContractTest | same | `1_process-list.md:86` | `--app`/`--workspace`/`--json` forwarding (~9–38) |
| process:list | ProcessListJsonRendererTest | same | `6.2_process-list_output-render_json.md:98` | JSON envelope + error pass-through (~9–41, 124–151) |
| process:remove | ProcessRemoveHumanRendererTest | `apps/cli/tests/Feature/Commands/Process/ProcessWriteCommandTest.php` | `6.1_process-remove_output-render_human.md:66` | Progress tree, cancelled confirm, not-found (~909–967) |
| process:remove | ProcessRemoveInputContractTest | same | `1_process-remove.md:93` | `--force`, `--json` consent (~450–535, 936–949) |
| process:remove | ProcessRemoveInteractiveInputModeTest | same | `5.1_process-remove_input-mode_interactive.md:40` | `expectsConfirmation` (~537–560) |
| process:remove | ProcessRemoveJsonRendererTest | same | `6.2_process-remove_output-render_json.md:96` | JSON delete (~453–535) |
| process:remove | ProcessRemoveNonInteractiveInputModeTest | same | `5.2_process-remove_input-mode_non-interactive.md:41` | Force required (~450–468) |
| process:restart | ProcessRestartHumanRendererTest | same | `6.1_process-restart_output-render_human.md:59` | Shared human runtime renderer (~969–1062) |
| process:restart | ProcessRestartInputContractTest | same | `1_process-restart.md:91` | Context/payload mapping (~562–667, 1002–1038) |
| process:restart | ProcessRestartJsonRendererTest | same | `6.2_process-restart_output-render_json.md:110` | `--json` runtime success |
| process:restart | ProcessRestartNonInteractiveInputModeTest | same | `5.2_process-restart_input-mode_non-interactive.md:39` | Pre-gateway validation (~669–691) |
| process:start | ProcessStartHumanRendererTest | same | `6.1_process-start_output-render_human.md:59` | Same shared renderer dataset |
| process:start | ProcessStartInputContractTest | same | `1_process-start.md:91` | Same payload/context tests |
| process:start | ProcessStartJsonRendererTest | same | `6.2_process-start_output-render_json.md:105` | Same JSON runtime tests |
| process:start | ProcessStartNonInteractiveInputModeTest | same | `5.2_process-start_input-mode_non-interactive.md:39` | Same validation tests |
| process:stop | ProcessStopHumanRendererTest | same | `6.1_process-stop_output-render_human.md:59` | Same shared renderer dataset |
| process:stop | ProcessStopInputContractTest | same | `1_process-stop.md:91` | Same payload/context tests |
| process:stop | ProcessStopJsonRendererTest | same | `6.2_process-stop_output-render_json.md:105` | Same JSON runtime tests |
| process:stop | ProcessStopNonInteractiveInputModeTest | same | `5.2_process-stop_input-mode_non-interactive.md:39` | Same validation tests |
| process:logs | ProcessLogsCommandTest | `apps/cli/tests/Feature/Commands/Process/ProcessLogsCommandTest.php` | `1_process-logs.md:93` | Bounded/follow logs, node context (~9–204) |
| process:logs | ProcessLogsInputContractTest | same | `1_process-logs.md:94` | Name/lines validation (~165–182) |
| process:logs | ProcessLogsJsonRendererTest | same | `6.2_process-logs_output-render_json.md:92` | JSON bounded + `--json`+`--follow` reject (~143–163) |
| process:logs | ProcessLogsNonInteractiveInputModeTest | same | `5.2_process-logs_input-mode_non-interactive.md:40` | Validation before stream (~143–182) |
| process:add | ProcessAddJsonRendererTest | `apps/cli/tests/Feature/Commands/Process/ProcessWriteCommandTest.php` | `6.2_process-add_output-render_json.md:137` | JSON add + validation (~9–268) |
| process:add | ProcessAddNonInteractiveInputModeTest | same + `ProcessAddServiceSelectorContractTest.php` | `5.2_process-add_input-mode_non-interactive.md:42` | Pre-gateway validation + managed service |
| proxy:list | ProxyListJsonRendererTest | `apps/cli/tests/Feature/Commands/Proxy/ProxyListCommandTest.php` | `6.2_proxy-list_output-render_json.md:103` | JSON envelope, filter meta (~9–48) |
| proxy:add | ProxyAddCommandTest | `apps/cli/tests/Feature/Commands/Proxy/ProxyWriteCommandTest.php` + `ProxyInteractiveInputModeTest.php` | `1_proxy-add.md:101` (+ input-mode companions) | Payload/validation; interactive upstream prompt |
| proxy:add | ProxyAddHumanRendererTest | ProxyWriteCommandTest | `6.1_proxy-add_output-render_human.md:42` | Human tree upstream/redirect (~252–344) |
| proxy:add | ProxyAddJsonRendererTest | ProxyWriteCommandTest | `6.2_proxy-add_output-render_json.md:132` | JSON add + error pass-through |
| proxy:add | ProxyCommandContractTest | `apps/gateway/tests/Unit/Services/Proxy/ProxyRouteIntentTest.php` | `1_proxy-add.md:102` | Intent DTO, ownership conflict (~28–184) |
| proxy:remove | ProxyRemoveCommandTest | ProxyWriteCommandTest | `1_proxy-remove.md:94` (+ input-mode companions) | Force, interactive prompt, DELETE (~170–251) |
| proxy:remove | ProxyRemoveJsonRendererTest | ProxyWriteCommandTest | `6.2_proxy-remove_output-render_json.md:101` | JSON delete (~190–224) |
| proxy:remove | ProxyCommandContractTest | ProxyRouteIntentTest | `1_proxy-remove.md:95` | Ownership + consent on remove |
| update:all | UpdateAllFleetVersionsTest | `apps/cli/tests/Feature/Commands/Operation/UpdateAllCommandTest.php` | `6.1_update-all_output-render_human.md:274` | Fleet check, short-circuit, sub-stages (~751–1208) |
| update:all | UpdateAllJsonRendererTest | same | `6.2_update-all_output-render_json.md:225` | JSON/stream-json frames, partial failure (~23–1831) |
| update:all | UpdateAllCommandTest (gateway path) | same | `6.2_update-all_output-render_json.md:219` | Stale location; CLI file is canonical |
| update:all | UpdateAllGatewayStreamClientTest | `apps/cli/tests/Feature/Services/GatewayOperationEventStreamClientTest.php` + UpdateAllCommandTest | `1_update-all.md:378` | SSE decode/Last-Event-ID; reconnect at command test ~1855 |

## uncertain-unreviewed (18 paths — defer)

Not safe for first-patch linking without new tests or narrowed doc text.

| command | stale path (basename) | reason deferred |
| --- | --- | --- |
| gateway:add | GatewayAddCallerRoleContractTest | No gateway-host denial test in CLI suite |
| gateway:add | GatewayAddInteractiveInputModeTest | No TTY/prompt tests |
| gateway:trust | GatewayTrustLocalConfigReadFailureTest | No `local_config_read_failed` test |
| process:list | ProcessListCommandTest | Doc mixes CLI + server grant/registry claims |
| process:list | ProcessListInteractiveInputModeTest | No interactive tests |
| process:list | ProcessListNonInteractiveInputModeTest | Missing ambiguous-context coverage |
| process:remove | ProcessRemoveCommandTest | Grant/cleanup claims need API tests |
| process:restart | ProcessRestartCommandTest | Durable events/partial bulk = API layer |
| process:restart | ProcessRestartInteractiveInputModeTest | No interactive tests |
| process:start | ProcessStartCommandTest | Same API/CLI split |
| process:start | ProcessStartInteractiveInputModeTest | No interactive tests |
| process:stop | ProcessStopCommandTest | Same API/CLI split |
| process:stop | ProcessStopInteractiveInputModeTest | No interactive tests |
| process:logs | ProcessLogsInteractiveInputModeTest | No interactive tests |
| process:add | ProcessAddInteractiveInputModeTest | No interactive tests |
| process:update | ProcessUpdateHumanRendererTest | Missing rename/warning human prose assertions |
| proxy:list | ProxyListCommandTest | Doctor handoff + authorization not in CLI list tests |
| proxy:remove | ProxyRemoveHumanRendererTest | No `owned_route_denied` human output test |

## First-patch recommendation (high-confidence only)

1. **Gateway** — `GatewaySelectionCommandTest`, `GatewayTrustCommandTest`, `GatewayAddCommandTest` (4/6 add paths), `GatewayStatusCommandTest` + `PublicCommandForwardingTest`.
2. **Proxy** — `ProxyListCommandTest` (JSON row only), `ProxyWriteCommandTest`, `ProxyInteractiveInputModeTest` (add), `ProxyRouteIntentTest` (unit contract).
3. **Update** — `UpdateAllCommandTest`, `GatewayOperationEventStreamClientTest`; fix stale row at `1_update-all.md:378`.
4. **Process logs** — all four non-interactive/renderer rows → `ProcessLogsCommandTest.php`.
5. **Process write renderers** — retarget remove + start/stop/restart human/json/input/non-interactive split files → `ProcessWriteCommandTest.php`; retarget process:add json/non-interactive rows.

**Do not patch yet:** all 18 `uncertain-unreviewed` rows.

Expected safe delta: ~40 doc line edits; shorten coverage descriptions to match cited assertions.