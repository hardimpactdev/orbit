# RemoteShell Lane Classification - 2026-05-28

Todo: ORBIT-CLI-10C.

Inventory basis:

- `rg -l "use App\\Contracts\\RemoteShell|use App\\Contracts\\RemoteShellStream|use App\\Contracts\\StartsRemoteShellProcesses|use App\\Services\\RemoteShell\\RemoteHostExecutor|use App\\Services\\RemoteShell\\RemoteOrbitRuntimeExecutor|use App\\Services\\RemoteShell\\RemoteLocalExecutor" apps/gateway/app -g '*.php'`
- `rg -n -- "->(run|start|stream|runInternal|startInternal)\(" <remote-shell-consumer-files>`
- `apps/docs/content/execution-lanes.md`
- `docs/superpowers/plans/2026-05-27-cli-first-command-surface.md` Phase 10 and decisions D18-D21.

## Classification Rules

`RemoteShell` is only transport. Each gateway-to-node workload must stay in one
of the documented lanes:

- Host substrate and container control stay in `RemoteHostExecutor`.
- Gateway Laravel, Artisan, Composer, PDO, and gateway SQLite work on a node
  stays in `RemoteOrbitRuntimeExecutor`.
- Packaged node-local helper logic that needs host file access plus PHP/PDO
  moves to token-gated hidden CLI `internal:*` commands via
  `RemoteLocalExecutor`.

Do not create one hidden command per visible progress label. Create one
internal command per cohesive node-local operation. Split only at real retry,
reuse, permission, isolation, or transaction boundaries.

## Migration Summary

| Disposition | Call sites |
| --- | --- |
| Migrate to hidden `internal:*` | `DatabaseConnectionExecutor` SQLite local query helper. |
| Already on hidden `internal:*` | Wg-easy state helper; workspace adapter lookup/update helpers. |
| Keep runtime/container executor | VPN gateway-Artisan forwarding; update dependency/migration stages; app/workspace/process Docker dispatch; deploy commands routed into app containers. |
| Keep host shell/bootstrap | provisioning, git/worktree/source paths, Caddy artifacts, firewall, SSH/sysctl/security, host user checks, `.env` file reads/writes, secret-file staging, scheduler dispatch, tool catalog scripts. |
| Requires docs change before internal migration | Proxy/Caddy mutation, firewall mutation, package/security mutation, tool lifecycle scripts, arbitrary schedule scripts, app/workspace/process runtime control, deploy scripts, and other host-substrate operations. |

## 10D Migration Candidate

| Consumer | Current primitive | Operation boundary | 10D disposition |
| --- | --- | --- | --- |
| `apps/gateway/app/Services/DatabaseConnections/DatabaseConnectionExecutor.php:84` | `RemoteShell::run()` dispatching `orbit database:query-local` | Execute SQLite query/schema/describe work against node-local database paths with PHP/PDO and a structured stdin payload. | Migrate to one token-gated hidden CLI command, for example `internal:database-query-local`, backed by a typed CLI action/service. The gateway call site should use `RemoteLocalExecutor::runInternal()`, not a public `orbit` command or gateway Artisan command. |

## Already Local Executor

| Consumer | Current primitive | Operation boundary | 10D disposition |
| --- | --- | --- | --- |
| `apps/gateway/app/Services/AgentIde/CoreAgentIdeWorkspacePathResolver.php:48` | `RemoteLocalExecutor::runInternal()` | Resolve OpenCode/Polyscope workspace path from node-local adapter state. | Keep on `internal:workspace-adapter:lookup`. |
| `apps/gateway/app/Services/Workspaces/PolyscopeWorkspaceDriver.php:162` | `RemoteLocalExecutor::runInternal()` | Read Polyscope adapter config from node-local state. | Keep on `internal:workspace-adapter:lookup`. |
| `apps/gateway/app/Services/Workspaces/PolyscopeWorkspaceBranchAligner.php:68` | `RemoteLocalExecutor::runInternal()` | Update Polyscope adapter workspace branch metadata. | Keep on `internal:workspace-adapter:update`. |
| `apps/gateway/app/Services/Vpn/WgEasyServiceInstaller.php:433` | `RemoteLocalExecutor::runInternal()` | Read/update wg-easy SQLite state with node-local ownership semantics. | Keep on `internal:wg-easy:state`. |
| `apps/gateway/app/Services/Vpn/WgEasyVpnBackend.php:377` | `RemoteLocalExecutor::runInternal()` | Read wg-easy SQLite state for VPN client state. | Keep on `internal:wg-easy:state`. |

## Runtime-Lane Rows

| Consumer | Current primitive | Operation boundary | 10D disposition |
| --- | --- | --- | --- |
| `apps/gateway/app/Console/Commands/VpnCommandSupport.php:93` | `RemoteOrbitRuntimeExecutor::run()` | Forward VPN gateway Artisan commands to the active VPN role node. | Keep runtime lane. This is gateway Laravel/Artisan work and must not move to hidden CLI internals. |
| `apps/gateway/app/Http/Controllers/Api/UpdateAllController.php:405` | `StartsRemoteShellProcesses::start()` with staged update scripts | Remote update stages: `git pull` is host source-checkout work; Composer install and migrations are `docker exec orbit-runtime ...` work. | Keep as a gateway-orchestrated long-running operation. If refactored, route Composer/migration stages through `RemoteOrbitRuntimeExecutor`; do not move to `internal:*`. |
| `apps/gateway/app/Services/OrbitUpdater.php:70-82,111` | `RemoteShell::run()` with staged update scripts | Same remote update stage split as `UpdateAllController`. | Keep staged operation. `git pull` remains host lane; Composer and migrations belong to runtime lane. |
| `apps/gateway/app/Services/Nodes/NodeIdentityArtifactProbe.php:21,60` | `RemoteShell::run()` mixed host `wg` plus host PHP/Laravel | Read WireGuard interface public key and map it through Orbit state. | Split when refactored: host `wg` read stays host lane; Laravel/PDO registry lookup belongs in `RemoteOrbitRuntimeExecutor`. Do not move to `internal:*` unless the product contract changes. |

## Host-Lane Rows

These rows stay in the host/bootstrap/container lane. Moving them to
`internal:*` would expand `RemoteLocalExecutor` beyond its documented scope and
requires a docs-first product change.

| Consumer | Current primitive | Operation boundary | 10D disposition |
| --- | --- | --- | --- |
| `apps/gateway/app/Console/Commands/AppExecCommand.php:110,125` | `RemoteShell::run()` | Inspect and execute inside app runtime containers through Docker. | Keep host/container executor. |
| `apps/gateway/app/Console/Commands/AppRegisterCommand.php:89` | `RemoteShell::run()` | Probe a host app path before gateway registration. | Keep host shell. |
| `apps/gateway/app/Console/Commands/NodeNewCommand.php:1084` | `RemoteShell::run()` | Run gateway-side ping reachability probe from the gateway host/runtime boundary. | Keep bootstrap/host lane. |
| `apps/gateway/app/Console/Commands/WorkspaceExecCommand.php:143,158` | `RemoteShell::run()` | Inspect and execute inside workspace runtime containers through Docker. | Keep host/container executor. |
| `apps/gateway/app/Actions/Apps/CreateAppSourceOnNode.php:30` | `RemoteShell::run()` | Create/check source directories and git material on the host. | Keep host shell. |
| `apps/gateway/app/Actions/Apps/EnsureAppProcessRuntimeUnits.php:105,130` | `RemoteShell::run()` | Clean up Supervisor residual units and repair Docker process runtime artifacts. | Keep host/container executor. |
| `apps/gateway/app/Actions/Apps/EnsureAppProxyRoute.php:71,106,135,266,278` | `RemoteShell::run()` | Write/read Caddy route artifacts and reload proxy state. | Keep host shell; internal migration requires docs change. |
| `apps/gateway/app/Actions/Apps/RemoveApp.php:124` | `RemoteShell::run()` | Remove app host/runtime artifacts. | Keep host shell. |
| `apps/gateway/app/Actions/Processes/AddProcess.php:115` | `RemoteShell::run()` | Start explicit Supervisor residual units. | Keep host shell. Docker starts continue through the Docker manager. |
| `apps/gateway/app/Actions/Processes/EditProcess.php:125` | `RemoteShell::run()` | Restart explicit Supervisor residual units. | Keep host shell. |
| `apps/gateway/app/Actions/Processes/RemoveProcess.php:98` | `RemoteShell::run()` | Remove explicit Supervisor residual units. | Keep host shell. |
| `apps/gateway/app/Actions/Processes/RestartProcesses.php:105` | `RemoteShell::run()` | Restart explicit Supervisor residual units. | Keep host shell. |
| `apps/gateway/app/Actions/Processes/ShowProcessLogs.php:34,75` | `RemoteShell::run()`, `RemoteShellStream::stream()` | Read or stream Docker/Supervisor process logs. | Keep host/container executor. |
| `apps/gateway/app/Actions/Processes/StartProcesses.php:106` | `RemoteShell::run()` | Start explicit Supervisor residual units. | Keep host shell. Docker starts continue through the Docker manager. |
| `apps/gateway/app/Actions/Processes/StopProcesses.php:106` | `RemoteShell::run()` | Stop explicit Supervisor residual units. | Keep host shell. |
| `apps/gateway/app/Actions/Workspaces/CreateWorkspace.php:114` | `RemoteShell::run()` | Preflight target node reachability before workspace creation. | Keep host shell. |
| `apps/gateway/app/Actions/Workspaces/RemoveWorkspace.php:73,87,106` | `RemoteShell::run()` | Remove process units, run teardown commands, and remove host worktree paths. | Keep host shell; teardown commands are caller-defined and not hidden internals. |
| `apps/gateway/app/Actions/Workspaces/SetupWorkspace.php:330,342` | `RemoteShell::run()` | Install host process artifacts and start explicit Supervisor residual units. | Keep host shell. |
| `apps/gateway/app/Services/Apps/AppRuntimeContainerManager.php:104,162,165,200,233,278,296,384,389` | `RemoteShell::run()` via local wrapper | Inspect, create, remove, and start app runtime Docker containers and host config paths. | Keep host/container executor. |
| `apps/gateway/app/Services/Apps/AppsFixer.php:170` | `RemoteShell::run()` | Repair app host/runtime artifacts from gateway intent. | Keep host/container executor. |
| `apps/gateway/app/Services/Apps/AppsProbe.php:81,316,456` | `RemoteShell::run()` | Probe app paths, runtime config files, and Docker containers using POSIX/Docker shell. | Keep host/container executor. |
| `apps/gateway/app/Services/Apps/AppWorkerReadiness.php:63` | `RemoteShell::run()` | Check app worker/readiness artifacts at the host/runtime boundary. | Keep host/container executor. |
| `apps/gateway/app/Services/Ca/OrbitSiteCertificateInstaller.php:30` | `RemoteShell::run()` | Write certificate/key material into host-managed certificate paths. | Keep host shell. |
| `apps/gateway/app/Services/DatabaseConnections/DatabaseConnectionAdopter.php:108` | `RemoteShell::run()` | Read app/workspace `.env` files from host paths for adoption. | Keep host shell. |
| `apps/gateway/app/Services/DatabaseConnections/DatabaseConnectionProbe.php:106,213` | `RemoteShell::run()` | Read app/workspace `.env` files from host paths for drift probes. | Keep host shell. |
| `apps/gateway/app/Services/DatabaseConnections/DatabaseConnectionRestorer.php:45,79` | `RemoteShell::run()` | Write/read app/workspace `.env` files on host paths. | Keep host shell. |
| `apps/gateway/app/Services/Deploy/DeployManager.php:159,339,434,495` | `RemoteShell::run()` | Dispatch deploy steps, Docker container warmups, and HTTP warmups. | Keep host/container executor. PHP/Composer deploy commands must route into app containers. |
| `apps/gateway/app/Services/Firewall/FirewallRuleFixer.php:32,36,37,57,58` | `RemoteShell::run()` | Apply UFW rules and reload UFW. | Keep host shell; internal migration requires docs change. |
| `apps/gateway/app/Services/Firewall/FirewallRuleProbe.php:52` | `RemoteShell::run()` | Read UFW host firewall state. | Keep host shell. |
| `apps/gateway/app/Services/Nodes/NodesProbe.php:425,818` | `RemoteShell::run()` | Check host user existence and SSH reachability. | Keep host shell. |
| `apps/gateway/app/Services/Nodes/NodeSecurityPostureProbe.php:206` | `RemoteShell::run()` | Check host security posture files and permissions. | Keep host shell, but rewrite the current `php -r` helper to POSIX shell. Do not move to local executor. |
| `apps/gateway/app/Services/Nodes/Roles/RoleBaselines/AgentRoleBaseline.php:73,74` | `RemoteShell::run()` | Create and lock the host `agent` user. | Keep host shell. |
| `apps/gateway/app/Services/Processes/ProcessDockerRuntimeManager.php:73,76,84,102,138,143,148,174,179` | `RemoteShell::run()` via local wrapper | Inspect/create/control Docker process runtime containers. | Keep host/container executor. |
| `apps/gateway/app/Services/Processes/ProcessesProbe.php:66,127,230` | `RemoteShell::run()` | Probe Docker process containers and Supervisor residual artifacts. | Keep host/container executor, but rewrite the current Supervisor `php -r` helper to POSIX shell. Do not move to local executor. |
| `apps/gateway/app/Services/Proxy/ProxyRouteFixer.php:79,113,151,178,349,386,456` | `RemoteShell::run()` | Write/remove/reload/repair `orbit-caddy` route artifacts. | Keep host shell; internal migration requires docs change. |
| `apps/gateway/app/Services/Proxy/ProxyRouteProbe.php:120,166,236,336` | `RemoteShell::run()` | Probe Caddy route files, cert paths, and proxy container state. | Keep host/container executor. |
| `apps/gateway/app/Services/RemoteShell/RemoteSecretFile.php:25,49` | `RemoteShell::run()` | Stage and remove temporary secret files on the host. | Keep host shell with redaction/audit discipline. |
| `apps/gateway/app/Services/RemoteShell/RemoteShellPool.php:59,91` | `RemoteShell::run()`, `StartsRemoteShellProcesses::start()` | Execute queued SSH jobs, currently schedule dispatch. | Infrastructure wrapper; jobs inherit the producer's lane. |
| `apps/gateway/app/Services/RuntimeBackend/GatewayRuntimeBackendProbe.php:35` | `RemoteShell::run()` | Probe host Docker state for `orbit-runtime`. | Keep host/container executor. |
| `apps/gateway/app/Services/RuntimeBackend/RuntimeBackendProbe.php:19` | `RemoteShell::run()` | Probe explicit Supervisor residual availability. | Keep host shell. |
| `apps/gateway/app/Services/Schedules/ScheduleDispatcher.php:76-90` | `RemoteShellPool` producer | Dispatch arbitrary configured schedule commands to their target node. | Keep as gateway-owned scheduling. Commands that need runtime/container context must be rendered that way before enqueue; do not convert schedules to hidden internals. |
| `apps/gateway/app/Services/Schedules/SchedulesFixer.php:56` | `RemoteShell::run()` | Repair scheduler host/runtime artifacts on the gateway node. | Keep host/container executor. |
| `apps/gateway/app/Services/Security/HomeDirectoryLockdownInstaller.php:14` | `RemoteShell::run()` | Mutate host home directory permissions and ownership. | Keep host shell. |
| `apps/gateway/app/Services/Security/PublicSshDenyInstaller.php:17` | `RemoteShell::run()` | Install UFW host rules for SSH exposure. | Keep host shell. |
| `apps/gateway/app/Services/Security/SshdHardenedInstaller.php:14` | `RemoteShell::run()` | Write SSH daemon host config and reload SSH. | Keep host shell. |
| `apps/gateway/app/Services/Security/SysctlBaselineInstaller.php:14` | `RemoteShell::run()` | Write host sysctl baseline and apply kernel settings. | Keep host shell. |
| `apps/gateway/app/Services/Security/UnattendedUpgradesInstaller.php:15` | `RemoteShell::run()` | Install/configure host unattended-upgrades packages. | Keep host shell. |
| `apps/gateway/app/Services/Tools/ToolInstaller.php:80,97` | `RemoteShell::run()` | Run catalog install and credential scripts on the host/tool substrate. | Keep host shell; internal migration requires docs change. |
| `apps/gateway/app/Services/Tools/ToolLifecycleManager.php:85,144,185` | `RemoteShell::run()` | Start, stop, and restart host/tool runtime commands. | Keep host shell; internal migration requires docs change. |
| `apps/gateway/app/Services/Tools/ToolLogFollower.php:37` | `RemoteShellStream::stream()` | Stream host/tool logs. | Keep host shell/stream. |
| `apps/gateway/app/Services/Tools/ToolLogReader.php:45` | `RemoteShell::run()` | Read host/tool logs. | Keep host shell. |
| `apps/gateway/app/Services/Tools/ToolReconfigurer.php:68` | `RemoteShell::run()` | Run catalog reconfiguration scripts on the host/tool substrate. | Keep host shell; internal migration requires docs change. |
| `apps/gateway/app/Services/Tools/ToolRemover.php:48` | `RemoteShell::run()` | Run catalog removal scripts on the host/tool substrate. | Keep host shell; internal migration requires docs change. |
| `apps/gateway/app/Services/Tools/ToolsFixer.php:58,286,309,310` | `RemoteShell::run()` | Repair tool config, credentials, containers, and host `agent` user state. | Keep host/container executor. |
| `apps/gateway/app/Services/Tools/ToolsProbe.php:129,181,850` | `RemoteShell::run()` | Probe tool binaries, images, containers, config, and host user state. | Keep host/container executor, but rewrite the generic `php -r` helper to POSIX shell. Do not move to local executor. |
| `apps/gateway/app/Services/Tools/ToolUpdater.php:63,172` | `RemoteShell::run()` | Run catalog update scripts on the host/tool substrate. | Keep host shell; internal migration requires docs change. |
| `apps/gateway/app/Services/Updates/UnattendedUpgradesDriver.php:51,105` | `RemoteShell::run()` | Probe and run host unattended-upgrades. | Keep host shell. |
| `apps/gateway/app/Services/Workspaces/EnsureWorkspaceProxyRoute.php:79,115,145,162,174` | `RemoteShell::run()` | Write/read Caddy route artifacts for workspace routes. | Keep host shell; internal migration requires docs change. |
| `apps/gateway/app/Services/Workspaces/OpenCodeWorkspaceDriver.php:109` | `RemoteShell::run()` | Align host git branch for OpenCode workspaces. | Keep host shell. |
| `apps/gateway/app/Services/Workspaces/PolyscopeWorkspaceBranchAligner.php:47` | `RemoteShell::run()` | Rename workspace Git branch in the host workspace path. | Keep host shell. The adapter metadata update is already local executor. |
| `apps/gateway/app/Services/Workspaces/WorkspaceRuntimeContainerManager.php:84,137,140,173,201,246,264,352,357` | `RemoteShell::run()` via local wrapper | Inspect/create/remove/start workspace runtime Docker containers and host config paths. | Keep host/container executor. |
| `apps/gateway/app/Services/Workspaces/WorkspaceSetupStepRunner.php:53` | `RemoteShell::run()` | Dispatch setup steps; PHP/Composer steps are wrapped into workspace containers. | Keep host/container executor. |
| `apps/gateway/app/Services/Workspaces/WorkspacesProbe.php:102` | `RemoteShell::run()` | Probe workspace host path, user, and filesystem state. | Keep host shell, but rewrite the current `php -r` helper to POSIX shell. Do not move to local executor. |
| `apps/gateway/app/Services/Workspaces/WorktreeWorkspaceDriver.php:23` | `RemoteShell::run()` | Create host git worktrees. | Keep host shell. |

## Infrastructure Rows

| Consumer | Current primitive | Disposition |
| --- | --- | --- |
| `apps/gateway/app/Providers/AppServiceProvider.php` | Container bindings for `RemoteShell`, `StartsRemoteShellProcesses`, `RemoteLocalExecutor`, and operation handlers. | Infrastructure only. It must not hide lane choice in new call sites; new work should inject the specific executor when classification matters. |
| `apps/gateway/app/Services/RemoteShell/SshRemoteShell.php` | Delegates host-lane `run()`/`start()` to `RemoteHostExecutor`. | Transport adapter only. |
| `apps/gateway/app/Services/RemoteShell/SshRemoteShellStream.php:30` | SSH stream transport. | Transport adapter only. |
| `apps/gateway/app/Services/RemoteShell/RemoteHostExecutor.php` | Host executor primitive. | Lane primitive, not a workload consumer. |
| `apps/gateway/app/Services/RemoteShell/RemoteOrbitRuntimeExecutor.php` | Runtime executor primitive. | Lane primitive, not a workload consumer. |
| `apps/gateway/app/Services/RemoteShell/RemoteLocalExecutor.php` | Local executor primitive. | Lane primitive, not a workload consumer. |

## Follow-Up Notes

- ORBIT-CLI-10D should only migrate `DatabaseConnectionExecutor` from this
  inventory unless a new docs-first change expands the local executor lane.
- Host-lane `php -r` snippets in `NodeSecurityPostureProbe`, `ProcessesProbe`,
  `ToolsProbe`, and `WorkspacesProbe` are real Docker-first hygiene issues, but
  they should become POSIX shell host probes rather than hidden CLI commands.
- `NodeIdentityArtifactProbe` is a mixed-lane bug shape: the WireGuard public-key
  read is host substrate, while the Laravel/PDO registry lookup belongs in
  `RemoteOrbitRuntimeExecutor`. Treat it as a runtime-lane refactor, not a
  local-executor migration.
- Future progress/broadcast work should stay gateway-owned: gateway jobs queue
  and persist operation state, consume framed node output when applicable,
  redact it, and later broadcast it. Hidden commands may emit framed progress
  over the gateway-owned transport; they must not call back to the gateway only
  to broadcast.
