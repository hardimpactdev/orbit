# Gateway public command inventory - 2026-05-29

Source for Solo todo #543 / ORBIT-PRE-S3-02. This note inventories the currently registered gateway Artisan command classes so follow-on todos can remove public product commands without losing API behavior or CLI coverage.

## Scope

- Inventory source: `Artisan::all()` in `apps/gateway`, filtered to `App\Console\Commands\*` command classes. Abstract base classes, traits, and support classes are not invokable and are omitted.
- Invokable command rows covered: 16 total, including 2 gateway-owned runtime rows (`orbit-scheduler` and `orbit-runtime-hibernator`), 12 internal `App\Console\Commands\Internal\*` rows kept explicit for the allowed `orbit:internal:*` category, `orbit:internal:node-register`, and the hidden update runner. E2E runner commands (e2e:*) were extracted to apps/e2e in #9H and are no longer registered in gateway Artisan.
- Gateway command visibility is final for public product commands: gateway Artisan no longer registers CLI-owned public product commands. #542 removed the app, node, DNS/local, gateway-local, PHP, update, profile, and doctor command family after CLI coverage and API extraction. #546 removed the database, workspace, process, and schedule command families after extracting `workspace:exec` behind the gateway API. #548 removed the tool, proxy, firewall, Cloudflare, VPN, deploy, activity, and agent-ide command families after #544 recorded CLI owner coverage.
- Product authority after #540: public operator commands are owned by `apps/cli/orbit`; gateway Artisan is for gateway maintenance, `orbit:internal:*`, E2E runner wrappers, gateway-owned runtime daemons, and intentional docs/librarian/dev commands.
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
| `orbit-runtime-hibernator` | `App\Console\Commands\RuntimeHibernatorCommand` | `gateway-owned` | none in required app call-site sweep | `keep` | none; gateway-owned runtime/internal command | apps/gateway/tests/Feature/Services/Processes/RuntimeHibernationTest.php |
| `orbit-scheduler` | `App\Console\Commands\OrbitSchedulerCommand` | `gateway-owned` | none in required app call-site sweep | `keep` | none; gateway-owned runtime/internal command | apps/gateway/tests/Feature/Commands/Schedule/OrbitSchedulerCommandTest.php |
| `orbit:internal:agent-push-proof` | `App\Console\Commands\Internal\AgentPushProofCommand` | `gateway-owned` | none in required app call-site sweep | `keep` | none; hidden live agent-push proof command | apps/gateway/tests/Feature/Commands/Internal/AgentPushProofCommandTest.php |
| `orbit:internal:bake-agent-node` | `App\Console\Commands\Internal\BakeAgentNodeCommand` | `gateway-owned` | none in required app call-site sweep | `keep` | none; gateway-owned runtime/internal command | apps/gateway/tests/Feature/Commands/Internal or E2E support coverage |
| `orbit:internal:bake-app-node` | `App\Console\Commands\Internal\BakeAppNodeCommand` | `gateway-owned` | none in required app call-site sweep | `keep` | none; gateway-owned runtime/internal command | apps/gateway/tests/Feature/Commands/Internal or E2E support coverage |
| `orbit:internal:bake-ingress-node` | `App\Console\Commands\Internal\BakeIngressNodeCommand` | `gateway-owned` | none in required app call-site sweep | `keep` | none; gateway-owned runtime/internal command | apps/gateway/tests/Feature/Commands/Internal or E2E support coverage |
| `orbit:internal:bake-websocket-node` | `App\Console\Commands\Internal\BakeWebSocketNodeCommand` | `gateway-owned` | none in required app call-site sweep | `keep` | none; gateway-owned runtime/internal command | apps/gateway/tests/Feature/Commands/Internal or E2E support coverage |
| `orbit:internal:bootstrap-gateway-local` | `App\Console\Commands\Internal\BootstrapGatewayLocalCommand` | `gateway-owned` | none in required app call-site sweep | `keep` | none; gateway-owned runtime/internal command | apps/gateway/tests/Feature/Commands/Internal or E2E support coverage |
| `orbit:internal:build-gateway-images` | `App\Console\Commands\Internal\BuildGatewayImagesCommand` | `gateway-owned` | none in required app call-site sweep | `keep` | none; gateway-owned runtime/internal command | apps/gateway/tests/Feature/Commands/Internal/BuildGatewayImagesCommandTest.php |
| `orbit:internal:converge-vpn-dns-runtime` | `App\Console\Commands\Internal\ConvergeVpnDnsRuntimeCommand` | `gateway-owned` | prepared Incus topology migration through the source-mounted gateway action | `keep` | none; gateway-owned runtime/internal command | apps/gateway/tests/Feature/Commands/Internal/ConvergeVpnDnsRuntimeCommandTest.php |
| `orbit:runtime-activation-runner` | `App\Console\Commands\RuntimeActivationRunnerCommand` | `gateway-owned` | `RuntimeActivationRunnerLauncher` | `keep` | none; hidden one-shot cold activation runner command | apps/gateway/tests/Feature/Services/Processes/RuntimeColdActivationTest.php |
| `orbit:update-runner` | `App\Console\Commands\UpdateRunnerCommand` | `gateway-owned` | none in required app call-site sweep | `keep` | none; hidden one-shot update runner command | apps/gateway/tests/Feature/Commands/UpdateRunnerCommandTest.php |
| `orbit:internal:detect-platform` | `App\Console\Commands\Internal\DetectPlatformCommand` | `gateway-owned` | none in required app call-site sweep | `keep` | none; gateway-owned runtime/internal command | apps/gateway/tests/Feature/Commands/Internal or E2E support coverage |
| `orbit:internal:doctor-fleet-probe-node` | `App\Console\Commands\Internal\DoctorFleetProbeNodeCommand` | `gateway-owned` | subprocess worker launched by `App\Services\Doctor\DoctorReportRunner` for `doctor --all` fleet probes | `keep` | none; gateway-owned runtime/internal command | apps/gateway/tests/Unit/Services/Doctor/FleetDoctorProbeBatchingTest.php |
| `orbit:internal:install-orbit-dns` | `App\Console\Commands\Internal\InstallOrbitDnsCommand` | `gateway-owned` | none in required app call-site sweep | `keep` | none; gateway-owned runtime/internal command | apps/gateway/tests/Feature/Commands/Internal or E2E support coverage |
| `orbit:internal:node-register` | `App\Console\Commands\NodeRegisterCommand` | `gateway-owned` | none in required app call-site sweep | `keep` | none; gateway-owned runtime/internal command | apps/gateway/tests/Feature/Commands/Internal or E2E support coverage |
| `orbit:internal:pin-node-host-keys` | `App\Console\Commands\Internal\PinNodeHostKeysCommand` | `gateway-owned` | none in required app call-site sweep | `keep` | none; gateway-owned runtime/internal command | apps/gateway/tests/Feature/Commands/Internal or E2E support coverage |

## Non-invokable support classes

No non-internal command support classes remain under `apps/gateway/app/Console/Commands`.

## Follow-on notes

- ORBIT-PRE-S3-04 (#542) removed the app/node/local command-removal family
  after #545 recorded CLI owner coverage and the live app/node API call sites
  were extracted behind gateway services/actions.
- ORBIT-PRE-S3-05 (#541) recorded CLI owner coverage for the database,
  workspace, process, and schedule command families. ORBIT-PRE-S3-06 (#546)
  extracted `workspace:exec` into `App\Actions\Workspaces\RunWorkspaceCommand`
  and removed the corresponding gateway public command classes/tests.
- ORBIT-PRE-S3-07 (#544) recorded CLI owner coverage for tool, proxy,
  firewall, Cloudflare, VPN, deploy, activity, and agent-ide command families.
  Focused CLI verification covers request payloads, validation, JSON/human
  rendering, stream contracts, local default resolution, prompts, and destructive
  consent. ORBIT-PRE-S3-08 (#548) removed the corresponding gateway public
  command classes/tests.
- #545/#541/#544 should update this note when CLI parity changes a row from `port-cli-coverage-first` to `delete`.
- #542/#546/#548/#9H removed rows only after the corresponding gateway command class was no longer registered/invokable and `CommandListVisibilityTest.php` flipped the transitional assertion to the final not-registered helper.
- The `e2e:*` commands were extracted to `apps/e2e` by #9H (this port) and are no longer registered in gateway Artisan. Their inventory rows have been removed from this file.
