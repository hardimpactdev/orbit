# Gateway public command inventory - 2026-05-29

Source for Solo todo #543 / ORBIT-PRE-S3-02. This note inventories the currently registered gateway Artisan command classes so follow-on todos can remove public product commands without losing API behavior or CLI coverage.

## Scope

- Inventory source: `Artisan::all()` in `apps/gateway`, filtered to `App\Console\Commands\*` command classes. Abstract base classes, traits, and support classes are not invokable and are omitted.
- Invokable command rows covered: 65 total, including 56 non-internal rows and 9 internal `App\Console\Commands\Internal\*` rows kept explicit for the allowed `orbit:internal:*` category.
- Gateway command visibility is transitional: remaining public product commands are hidden from `list`, but #548 families remain directly invokable until removal. #542 removed the app, node, DNS/local, gateway-local, PHP, update, profile, and doctor command family after CLI coverage and API extraction. #546 removed the database, workspace, process, and schedule command families after extracting `workspace:exec` behind the gateway API.
- Product authority after #540: public operator commands are owned by `apps/cli/orbit`; gateway Artisan is for gateway maintenance, `orbit:internal:*`, E2E runner wrappers, `orbit-scheduler`, and intentional docs/librarian/dev commands.
- Do not move gateway business logic to `packages/core` as part of command removal.

## Gateway direct call-site sweep

Command run from the repo root:

```bash
rg -n "Artisan::call\(|\$this->call\(" apps/gateway/app/Http apps/gateway/app/Services apps/gateway/app/Actions apps/gateway/app/Jobs
```

`apps/gateway/app/Jobs` does not exist in this checkout, so `rg` exits non-zero if the directory is passed. After #542 and #546 extraction, no gateway app/API/service/action call site calls a public product command through `Artisan::call`.

## Classifications

- `delete`: CLI owner and focused CLI coverage already exist; remove the gateway command in the matching removal todo after final parity check.
- `port-cli-coverage-first`: gateway tests still hold useful command UX/input/renderer coverage; port the missing assertions to CLI before removal.
- `internalize-extract-first`: live gateway API/controller path calls the command through `Artisan::call`; extract command-owned behavior behind the API before removal.
- `keep`: allowed gateway Artisan category for now.

## Inventory

| command name | gateway command class | CLI owner | gateway internal call sites | classification | removal todo | required tests |
| --- | --- | --- | --- | --- | --- | --- |
| `activity:list` | `App\Console\Commands\ActivityListCommand` | `App\Commands\Activity\ActivityListCommand` | none in required app call-site sweep | `delete` | #544 then #548 | apps/cli/tests/Feature/Commands/Activity/* |
| `activity:show` | `App\Console\Commands\ActivityShowCommand` | `App\Commands\Activity\ActivityShowCommand` | none in required app call-site sweep | `delete` | #544 then #548 | apps/cli/tests/Feature/Commands/Activity/* |
| `agent-ide:message` | `App\Console\Commands\AgentIdeMessageCommand` | `App\Commands\AgentIde\AgentIdeMessageCommand` | none in required app call-site sweep | `port-cli-coverage-first` | #544 then #548 | apps/cli/tests/Feature/Commands/AgentIde/* |
| `cf-cache-rule:add` | `App\Console\Commands\CfCacheRuleAddCommand` | `App\Commands\Cloudflare\CfCacheRuleAddCommand` | none in required app call-site sweep | `port-cli-coverage-first` | #544 then #548 | apps/cli/tests/Feature/Commands/Cloudflare/* |
| `cf-cache-rule:remove` | `App\Console\Commands\CfCacheRuleRemoveCommand` | `App\Commands\Cloudflare\CfCacheRuleRemoveCommand` | none in required app call-site sweep | `port-cli-coverage-first` | #544 then #548 | apps/cli/tests/Feature/Commands/Cloudflare/* |
| `cf-cache:flush` | `App\Console\Commands\CfCacheFlushCommand` | `App\Commands\Cloudflare\CfCacheFlushCommand` | none in required app call-site sweep | `port-cli-coverage-first` | #544 then #548 | apps/cli/tests/Feature/Commands/Cloudflare/* |
| `cf-dns:add` | `App\Console\Commands\CfDnsAddCommand` | `App\Commands\Cloudflare\CfDnsAddCommand` | none in required app call-site sweep | `port-cli-coverage-first` | #544 then #548 | apps/cli/tests/Feature/Commands/Cloudflare/* |
| `cf-dns:list` | `App\Console\Commands\CfDnsListCommand` | `App\Commands\Cloudflare\CfDnsListCommand` | none in required app call-site sweep | `delete` | #544 then #548 | apps/cli/tests/Feature/Commands/Cloudflare/* |
| `cf-dns:remove` | `App\Console\Commands\CfDnsRemoveCommand` | `App\Commands\Cloudflare\CfDnsRemoveCommand` | none in required app call-site sweep | `port-cli-coverage-first` | #544 then #548 | apps/cli/tests/Feature/Commands/Cloudflare/* |
| `cf-ssl:disable` | `App\Console\Commands\CfSslDisableCommand` | `App\Commands\Cloudflare\CfSslDisableCommand` | none in required app call-site sweep | `port-cli-coverage-first` | #544 then #548 | apps/cli/tests/Feature/Commands/Cloudflare/* |
| `cf-ssl:enable` | `App\Console\Commands\CfSslEnableCommand` | `App\Commands\Cloudflare\CfSslEnableCommand` | none in required app call-site sweep | `port-cli-coverage-first` | #544 then #548 | apps/cli/tests/Feature/Commands/Cloudflare/* |
| `cf-zone:list` | `App\Console\Commands\CfZoneListCommand` | `App\Commands\Cloudflare\CfZoneListCommand` | none in required app call-site sweep | `delete` | #544 then #548 | apps/cli/tests/Feature/Commands/Cloudflare/* |
| `deploy:history` | `App\Console\Commands\DeployHistoryCommand` | `App\Commands\Deploy\DeployHistoryCommand` | none in required app call-site sweep | `delete` | #544 then #548 | apps/cli/tests/Feature/Commands/Deploy/* |
| `deploy:log` | `App\Console\Commands\DeployLogCommand` | `App\Commands\Deploy\DeployLogCommand` | none in required app call-site sweep | `delete` | #544 then #548 | apps/cli/tests/Feature/Commands/Deploy/* |
| `deploy:run` | `App\Console\Commands\DeployRunCommand` | `App\Commands\Deploy\DeployRunCommand` | none in required app call-site sweep | `port-cli-coverage-first` | #544 then #548 | apps/cli/tests/Feature/Commands/Deploy/* |
| `deploy:step-add` | `App\Console\Commands\DeployStepAddCommand` | `App\Commands\Deploy\DeployStepAddCommand` | none in required app call-site sweep | `port-cli-coverage-first` | #544 then #548 | apps/cli/tests/Feature/Commands/Deploy/* |
| `deploy:step-list` | `App\Console\Commands\DeployStepListCommand` | `App\Commands\Deploy\DeployStepListCommand` | none in required app call-site sweep | `port-cli-coverage-first` | #544 then #548 | apps/cli/tests/Feature/Commands/Deploy/* |
| `deploy:step-remove` | `App\Console\Commands\DeployStepRemoveCommand` | `App\Commands\Deploy\DeployStepRemoveCommand` | none in required app call-site sweep | `port-cli-coverage-first` | #544 then #548 | apps/cli/tests/Feature/Commands/Deploy/* |
| `e2e:ensure-artifacts` | `App\Console\Commands\E2EEnsureArtifactsCommand` | `gateway-owned` | none in required app call-site sweep | `keep` | #550-#558 extract E2E harness before retiring wrapper | gateway E2E support tests until apps/e2e extraction |
| `e2e:preflight` | `App\Console\Commands\E2EPreflightCommand` | `gateway-owned` | none in required app call-site sweep | `keep` | #550-#558 extract E2E harness before retiring wrapper | gateway E2E support tests until apps/e2e extraction |
| `e2e:prepare-base-image` | `App\Console\Commands\E2EPrepareBaseImageCommand` | `gateway-owned` | none in required app call-site sweep | `keep` | #550-#558 extract E2E harness before retiring wrapper | gateway E2E support tests until apps/e2e extraction |
| `e2e:prepare-docker-hosts` | `App\Console\Commands\E2EPrepareDockerHostsCommand` | `gateway-owned` | none in required app call-site sweep | `keep` | #550-#558 extract E2E harness before retiring wrapper | gateway E2E support tests until apps/e2e extraction |
| `e2e:prepare-docker-runtime` | `App\Console\Commands\E2EPrepareDockerRuntimeCommand` | `gateway-owned` | none in required app call-site sweep | `keep` | #550-#558 extract E2E harness before retiring wrapper | gateway E2E support tests until apps/e2e extraction |
| `e2e:prepare-docker-topology` | `App\Console\Commands\E2EPrepareDockerTopologyCommand` | `gateway-owned` | none in required app call-site sweep | `keep` | #550-#558 extract E2E harness before retiring wrapper | gateway E2E support tests until apps/e2e extraction |
| `e2e:prepare-topology` | `App\Console\Commands\E2EPrepareTopologyCommand` | `gateway-owned` | none in required app call-site sweep | `keep` | #550-#558 extract E2E harness before retiring wrapper | gateway E2E support tests until apps/e2e extraction |
| `e2e:prepare-warm-topology` | `App\Console\Commands\E2EPrepareWarmTopologyCommand` | `gateway-owned` | none in required app call-site sweep | `keep` | #550-#558 extract E2E harness before retiring wrapper | gateway E2E support tests until apps/e2e extraction |
| `e2e:reap-docker` | `App\Console\Commands\E2EReapDockerCommand` | `gateway-owned` | none in required app call-site sweep | `keep` | #550-#558 extract E2E harness before retiring wrapper | gateway E2E support tests until apps/e2e extraction |
| `e2e:reap-incus` | `App\Console\Commands\E2EReapIncusCommand` | `gateway-owned` | none in required app call-site sweep | `keep` | #550-#558 extract E2E harness before retiring wrapper | gateway E2E support tests until apps/e2e extraction |
| `e2e:test` | `App\Console\Commands\E2ETestCommand` | `gateway-owned` | none in required app call-site sweep | `keep` | #550-#558 extract E2E harness before retiring wrapper | gateway E2E support tests until apps/e2e extraction |
| `firewall:allow` | `App\Console\Commands\FirewallAllowCommand` | `App\Commands\Firewall\FirewallAllowCommand` | none in required app call-site sweep | `port-cli-coverage-first` | #544 then #548 | apps/cli/tests/Feature/Commands/Firewall/* |
| `firewall:deny` | `App\Console\Commands\FirewallDenyCommand` | `App\Commands\Firewall\FirewallDenyCommand` | none in required app call-site sweep | `port-cli-coverage-first` | #544 then #548 | apps/cli/tests/Feature/Commands/Firewall/* |
| `firewall:list` | `App\Console\Commands\FirewallListCommand` | `App\Commands\Firewall\FirewallListCommand` | none in required app call-site sweep | `delete` | #544 then #548 | apps/cli/tests/Feature/Commands/Firewall/* |
| `firewall:remove` | `App\Console\Commands\FirewallRemoveCommand` | `App\Commands\Firewall\FirewallRemoveCommand` | none in required app call-site sweep | `port-cli-coverage-first` | #544 then #548 | apps/cli/tests/Feature/Commands/Firewall/* |
| `orbit-scheduler` | `App\Console\Commands\OrbitSchedulerCommand` | `gateway-owned` | none in required app call-site sweep | `keep` | none; gateway-owned runtime/internal command | apps/gateway/tests/Feature/Commands/Schedule/OrbitSchedulerCommandTest.php |
| `orbit:internal:bake-agent-node` | `App\Console\Commands\Internal\BakeAgentNodeCommand` | `gateway-owned` | none in required app call-site sweep | `keep` | none; gateway-owned runtime/internal command | apps/gateway/tests/Feature/Commands/Internal or E2E support coverage |
| `orbit:internal:bake-app-node` | `App\Console\Commands\Internal\BakeAppNodeCommand` | `gateway-owned` | none in required app call-site sweep | `keep` | none; gateway-owned runtime/internal command | apps/gateway/tests/Feature/Commands/Internal or E2E support coverage |
| `orbit:internal:bake-ingress-node` | `App\Console\Commands\Internal\BakeIngressNodeCommand` | `gateway-owned` | none in required app call-site sweep | `keep` | none; gateway-owned runtime/internal command | apps/gateway/tests/Feature/Commands/Internal or E2E support coverage |
| `orbit:internal:bake-websocket-node` | `App\Console\Commands\Internal\BakeWebSocketNodeCommand` | `gateway-owned` | none in required app call-site sweep | `keep` | none; gateway-owned runtime/internal command | apps/gateway/tests/Feature/Commands/Internal or E2E support coverage |
| `orbit:internal:bootstrap-gateway-local` | `App\Console\Commands\Internal\BootstrapGatewayLocalCommand` | `gateway-owned` | none in required app call-site sweep | `keep` | none; gateway-owned runtime/internal command | apps/gateway/tests/Feature/Commands/Internal or E2E support coverage |
| `orbit:internal:build-runtime-images` | `App\Console\Commands\Internal\BuildRuntimeImagesCommand` | `gateway-owned` | none in required app call-site sweep | `keep` | none; gateway-owned runtime/internal command | apps/gateway/tests/Feature/Commands/Internal or E2E support coverage |
| `orbit:internal:detect-platform` | `App\Console\Commands\Internal\DetectPlatformCommand` | `gateway-owned` | none in required app call-site sweep | `keep` | none; gateway-owned runtime/internal command | apps/gateway/tests/Feature/Commands/Internal or E2E support coverage |
| `orbit:internal:install-orbit-dns` | `App\Console\Commands\Internal\InstallOrbitDnsCommand` | `gateway-owned` | none in required app call-site sweep | `keep` | none; gateway-owned runtime/internal command | apps/gateway/tests/Feature/Commands/Internal or E2E support coverage |
| `orbit:internal:node-register` | `App\Console\Commands\NodeRegisterCommand` | `gateway-owned` | none in required app call-site sweep | `keep` | none; gateway-owned runtime/internal command | apps/gateway/tests/Feature/Commands/Internal or E2E support coverage |
| `orbit:internal:pin-node-host-keys` | `App\Console\Commands\Internal\PinNodeHostKeysCommand` | `gateway-owned` | none in required app call-site sweep | `keep` | none; gateway-owned runtime/internal command | apps/gateway/tests/Feature/Commands/Internal or E2E support coverage |
| `proxy:add` | `App\Console\Commands\ProxyAddCommand` | `App\Commands\Proxy\ProxyAddCommand` | none in required app call-site sweep | `port-cli-coverage-first` | #544 then #548 | apps/cli/tests/Feature/Commands/Proxy/* |
| `proxy:list` | `App\Console\Commands\ProxyListCommand` | `App\Commands\Proxy\ProxyListCommand` | none in required app call-site sweep | `delete` | #544 then #548 | apps/cli/tests/Feature/Commands/Proxy/* |
| `proxy:remove` | `App\Console\Commands\ProxyRemoveCommand` | `App\Commands\Proxy\ProxyRemoveCommand` | none in required app call-site sweep | `port-cli-coverage-first` | #544 then #548 | apps/cli/tests/Feature/Commands/Proxy/* |
| `tool:credentials` | `App\Console\Commands\ToolCredentialsCommand` | `App\Commands\Tool\ToolCredentialsCommand` | none in required app call-site sweep | `delete` | #544 then #548 | apps/cli/tests/Feature/Commands/Tool/* |
| `tool:install` | `App\Console\Commands\ToolInstallCommand` | `App\Commands\Tool\ToolInstallCommand` | none in required app call-site sweep | `port-cli-coverage-first` | #544 then #548 | apps/cli/tests/Feature/Commands/Tool/* |
| `tool:list` | `App\Console\Commands\ToolListCommand` | `App\Commands\Tool\ToolListCommand` | none in required app call-site sweep | `delete` | #544 then #548 | apps/cli/tests/Feature/Commands/Tool/* |
| `tool:logs` | `App\Console\Commands\ToolLogsCommand` | `App\Commands\Tool\ToolLogsCommand` | none in required app call-site sweep | `delete` | #544 then #548 | apps/cli/tests/Feature/Commands/Tool/* |
| `tool:reconfigure` | `App\Console\Commands\ToolReconfigureCommand` | `App\Commands\Tool\ToolReconfigureCommand` | none in required app call-site sweep | `port-cli-coverage-first` | #544 then #548 | apps/cli/tests/Feature/Commands/Tool/* |
| `tool:reload` | `App\Console\Commands\ToolReloadCommand` | `App\Commands\Tool\ToolReloadCommand` | none in required app call-site sweep | `port-cli-coverage-first` | #544 then #548 | apps/cli/tests/Feature/Commands/Tool/* |
| `tool:remove` | `App\Console\Commands\ToolRemoveCommand` | `App\Commands\Tool\ToolRemoveCommand` | none in required app call-site sweep | `port-cli-coverage-first` | #544 then #548 | apps/cli/tests/Feature/Commands/Tool/* |
| `tool:restart` | `App\Console\Commands\ToolRestartCommand` | `App\Commands\Tool\ToolRestartCommand` | none in required app call-site sweep | `port-cli-coverage-first` | #544 then #548 | apps/cli/tests/Feature/Commands/Tool/* |
| `tool:show` | `App\Console\Commands\ToolShowCommand` | `App\Commands\Tool\ToolShowCommand` | none in required app call-site sweep | `delete` | #544 then #548 | apps/cli/tests/Feature/Commands/Tool/* |
| `tool:start` | `App\Console\Commands\ToolStartCommand` | `App\Commands\Tool\ToolStartCommand` | none in required app call-site sweep | `port-cli-coverage-first` | #544 then #548 | apps/cli/tests/Feature/Commands/Tool/* |
| `tool:stop` | `App\Console\Commands\ToolStopCommand` | `App\Commands\Tool\ToolStopCommand` | none in required app call-site sweep | `port-cli-coverage-first` | #544 then #548 | apps/cli/tests/Feature/Commands/Tool/* |
| `tool:update` | `App\Console\Commands\ToolUpdateCommand` | `App\Commands\Tool\ToolUpdateCommand` | none in required app call-site sweep | `port-cli-coverage-first` | #544 then #548 | apps/cli/tests/Feature/Commands/Tool/* |
| `vpn-client:disable` | `App\Console\Commands\VpnClientDisableCommand` | `App\Commands\Vpn\VpnClientDisableCommand` | none in required app call-site sweep | `port-cli-coverage-first` | #544 then #548 | apps/cli/tests/Feature/Commands/Vpn/* |
| `vpn-client:enable` | `App\Console\Commands\VpnClientEnableCommand` | `App\Commands\Vpn\VpnClientEnableCommand` | none in required app call-site sweep | `port-cli-coverage-first` | #544 then #548 | apps/cli/tests/Feature/Commands/Vpn/* |
| `vpn-client:list` | `App\Console\Commands\VpnClientListCommand` | `App\Commands\Vpn\VpnClientListCommand` | none in required app call-site sweep | `delete` | #544 then #548 | apps/cli/tests/Feature/Commands/Vpn/* |
| `vpn-client:new` | `App\Console\Commands\VpnClientNewCommand` | `App\Commands\Vpn\VpnClientNewCommand` | none in required app call-site sweep | `port-cli-coverage-first` | #544 then #548 | apps/cli/tests/Feature/Commands/Vpn/* |
| `vpn-client:remove` | `App\Console\Commands\VpnClientRemoveCommand` | `App\Commands\Vpn\VpnClientRemoveCommand` | none in required app call-site sweep | `port-cli-coverage-first` | #544 then #548 | apps/cli/tests/Feature/Commands/Vpn/* |
| `vpn-web-ui:change-password` | `App\Console\Commands\VpnWebUiChangePasswordCommand` | `App\Commands\Vpn\VpnWebUiChangePasswordCommand` | none in required app call-site sweep | `port-cli-coverage-first` | #544 then #548 | apps/cli/tests/Feature/Commands/Vpn/* |

## Non-invokable support classes

These non-internal classes live under `apps/gateway/app/Console/Commands`, but they do not register command names and therefore do not receive inventory rows above:

- `App\Console\Commands\AbstractFirewallStoreCommand`
- `App\Console\Commands\CloudflareCommand`
- `App\Console\Commands\VpnCommandSupport`

## Follow-on notes

- ORBIT-PRE-S3-04 (#542) removed the app/node/local command-removal family
  after #545 recorded CLI owner coverage and the live app/node API call sites
  were extracted behind gateway services/actions.
- ORBIT-PRE-S3-05 (#541) recorded CLI owner coverage for the database,
  workspace, process, and schedule command families. ORBIT-PRE-S3-06 (#546)
  extracted `workspace:exec` into `App\Actions\Workspaces\RunWorkspaceCommand`
  and removed the corresponding gateway public command classes/tests.
- #545/#541/#544 should update this note when CLI parity changes a row from `port-cli-coverage-first` to `delete`.
- #542/#546/#548 should remove rows only after the corresponding gateway command class is no longer registered/invokable and `CommandListVisibilityTest.php` flips the transitional assertion to the final not-registered helper.
- The `e2e:*` rows stay `keep` only until the `apps/e2e` extraction retires gateway runner wrappers.
