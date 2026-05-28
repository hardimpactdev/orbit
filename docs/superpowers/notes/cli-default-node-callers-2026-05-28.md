# LocalNodeDefault / LocalGatewaySettings Caller Classification

Source artifact for the Phase 3 caller audit step (ORBIT-CLI-03E) of the CLI-first plan. Drives the per-caller resolution path applied in Phase 8.

Inventory dumps:

- `rg -n "LocalNodeDefault" apps/gateway/app`
- `rg -n "LocalGatewaySettings" apps/gateway/app`

## Classification rules (per D11 + D17)

- **Type (a) default-node consumer** — reads `LocalNodeDefault::query()->value('default_node_name')` to resolve the target node when one is not supplied. Retire the read; require client-side `--node=X` per D11. The CLI command resolves the default from `OrbitConfigStore` before calling the gateway.
- **Type (b) gateway-runtime self-trust consumer** — reads `LocalGatewaySettings::current()` (or queries the table) for the gateway runtime's own URL / CA / WireGuard self-trust. Keep per D17. The gateway-runtime container continues writing and reading this table.
- **Type (c) gateway-runtime OS-user collision** — runs as the gateway-runtime user (scheduler / probe / runtime helper) and cannot read `~/.config/orbit/config.json` (D8 owner-only). Apply the D11 type-(c) policy: require `--node=X`, or read the resolved node from `operation_runs.target_node_id` when one exists. No interactive fallback.

## Type (a) callers — `LocalNodeDefault`

| Caller | Phase 8 owner todo | Notes |
| --- | --- | --- |
| `apps/gateway/app/Console/Commands/NodeDefaultCommand.php:14` | ORBIT-CLI-08B | The command itself moves to CLI as LocalOnlyCommand reading `OrbitConfigStore` |
| `apps/gateway/app/Console/Commands/AppNewCommand.php:18,604` | ORBIT-CLI-08F | CLI command resolves via OrbitConfigStore + passes `--node=X` to the gateway API |
| `apps/gateway/app/Console/Commands/ToolInstallCommand.php:15,268` | ORBIT-CLI-08L | as above |
| `apps/gateway/app/Console/Commands/ToolStartCommand.php:18,370` | ORBIT-CLI-08L | as above |
| `apps/gateway/app/Console/Commands/ToolStopCommand.php:19,268` | ORBIT-CLI-08L | as above |
| `apps/gateway/app/Console/Commands/ToolRestartCommand.php:19,375` | ORBIT-CLI-08L | as above |
| `apps/gateway/app/Console/Commands/ToolRemoveCommand.php:16,282` | ORBIT-CLI-08L | as above |
| `apps/gateway/app/Console/Commands/ToolShowCommand.php:14,222` | ORBIT-CLI-06H | as above (read family) |
| `apps/gateway/app/Console/Commands/ToolLogsCommand.php:16,222` | ORBIT-CLI-06H / 08L | as above |
| `apps/gateway/app/Console/Commands/ProxyAddCommand.php:15,372` | ORBIT-CLI-08J | as above |
| `apps/gateway/app/Console/Commands/Concerns/AuthorizesAgentToolSelf.php:7,47` | ORBIT-CLI-08F / 08G | shared concern; ports with consumer commands |
| `apps/gateway/app/Console/Commands/AbstractFirewallStoreCommand.php:15,308` | ORBIT-CLI-08M | abstract base ports with its subclasses |
| `apps/gateway/app/Console/Commands/FirewallRemoveCommand.php:13,234` | ORBIT-CLI-08M | as above |

## Type (b) callers — `LocalGatewaySettings` (kept per D17)

| Caller | Action |
| --- | --- |
| `apps/gateway/app/Console/Commands/GatewayAddCommand.php:15,154,264,348` | Stays gateway-side (this is the bootstrap that writes its own self-trust row); CLI BootstrapGatewayCommand mirrors the JSON config writes per D16 |
| `apps/gateway/app/Console/Commands/GatewayTrustCommand.php:11,184,298,344,466,476` | Stays gateway-side; CLI LocalOnlyCommand mirrors the JSON config writes |
| `apps/gateway/app/Console/Commands/NodeNewCommand.php:19,347,2553,2851,3037` | The `--role=gateway` branch self-writes the runtime trust row; non-gateway branch is type (a) for default resolution |
| `apps/gateway/app/Console/Commands/UpdateAllCommand.php:15,1371` | Reads gateway URL for self-update orchestration; stays gateway-side |
| `apps/gateway/app/Console/Commands/NodeUpdateCommand.php:16,…` | Reads gateway WireGuard endpoint; stays gateway-side |
| `apps/gateway/app/Console/Commands/NodeAgentIdeCommand.php:15,229` | Reads gateway endpoint for adapter wiring; stays gateway-side |
| `apps/gateway/app/Console/Commands/AgentIdeMessageCommand.php:13,146` | Same as NodeAgentIdeCommand |
| `apps/gateway/app/Console/Commands/NodeDefaultCommand.php:14,317,319` | The gateway-trust read remains; the default-node read is type (a) |

## Type (c) callers — OS-user collision

| Caller | Action |
| --- | --- |
| `apps/gateway/app/Services/Php/PhpRuntimeManager.php:…` | Always require `--node=X` from the caller; never fall back to OrbitConfigStore (runs as `orbit` runtime user). Doctor/probe-style callers store the resolved node on the dispatched operation row. |
| `apps/gateway/app/Services/Nodes/NodesProbe.php:…` | Same; probe is scheduler-driven and must be invoked with an explicit node. |
| `apps/gateway/app/Services/Workflows/ProcessEventNotifierRenderer.php:…` | Renderer takes the node from the surrounding context (process/event row), not from a default. Confirm before Phase 8 port. |
| `apps/gateway/app/Console/Commands/DoctorCommand.php:15,503` | When invoked from the scheduler, requires `--node=X`. When invoked from the CLI by the operator, the CLI resolves the default client-side from OrbitConfigStore and passes `--node=X` to the gateway API. |

## Coverage gates

- Every type (a) caller is listed above; the Phase 8 owner column maps each to a Phase 8 todo.
- Every type (b) caller is listed above; no Phase 8 work needed (kept per D17).
- Every type (c) caller is listed above; the policy is enforced by Phase 8 port + a focused test that calling the command without `--node=X` returns the `node_target_required` error code per D11.

## Notes

- `apps/gateway/app/E2E/Support/**` was not enumerated here; the Phase 4 E2E retarget sweep (ORBIT-CLI-04A) handles E2E surface separately.
- Re-run the inventory commands at the start of each new Phase 8 family port to catch any new callers landed in the meantime.
