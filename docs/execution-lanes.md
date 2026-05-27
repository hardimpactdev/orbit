# Runtime Execution Lanes

This page defines how the gateway may execute work on Docker-first-managed
nodes. The SSH transport stays `RemoteShell`, but every gateway-to-node
workload must belong to one of three execution lanes.

## Scope

A Docker-first-managed node is a node that has the Orbit Docker baseline:
`orbit-runtime` for Orbit PHP, `orbit-caddy` for proxying when needed, and
workload containers for apps, workspaces, processes, and backing services.

Before that baseline exists, bootstrap may use host shell commands to install
Docker, prepare the `orbit` user, clone Orbit source, and create the first
runtime containers. After the baseline exists, gateway Laravel/artisan/PDO work
must not rely on host PHP, host Composer, host Python, host SQLite, or host
database client binaries. Host PHP is allowed only for the source-checkout
CLI/local-executor artifact; it is not an app/workspace runtime fallback.

## Lanes

```text
RemoteHostExecutor:
  SSH host substrate, bootstrap, Docker/container control, host git,
  system packages, WireGuard host mutation, Caddy artifacts.

RemoteOrbitRuntimeExecutor:
  SSH then docker exec into orbit-runtime for gateway Laravel/artisan/PDO
  work that belongs to the gateway runtime container.

RemoteLocalExecutor:
  SSH then invoke the host-installed apps/cli internal executor command.
  It is for packaged node-local helper logic that needs host file access
  and PHP/PDO without relying on ad hoc python3/sqlite3 snippets.
```

### RemoteHostExecutor

`RemoteHostExecutor` runs over SSH on the node host. It is for host substrate,
bootstrap, and container control only.

Allowed work:

- Docker installation, Docker daemon checks, Docker network/container/image
  lifecycle, and container repair.
- WireGuard host mutation such as `wg set`, interface checks, and VPN-facing
  network setup.
- Kernel, package, firewall, sysctl, SSH, host-key, system user, file
  ownership, chmod/chown, and host directory bootstrap.
- Git checkout, worktree, and branch rename operations on host-mounted source
  paths.
- Managed config/certificate writes under host paths such as `/etc`, `/srv`,
  `/var`, and the node user's home.
- `orbit-caddy` container lifecycle, Caddy include files, route artifacts, and
  Caddy reload/repair.
- App/workspace/process container dispatch through Docker, including
  `docker exec` into app, workspace, or process containers.
- Explicit residual host runtimes such as non-PHP Supervisor process units.

Forbidden work:

- Running Orbit `php`, `composer`, `artisan`, PDO, Eloquent, Laravel boot, or
  database query logic on the host after the Docker baseline exists.
- Using host Python, host `sqlite3`, `psql`, `mysql`, or similar client
  binaries as steady-state helpers for Orbit-owned state.
- Treating the host `orbit` launcher as the PHP execution path from the gateway
  to a node.

If work in the host lane currently needs PHP/Python/SQLite only to inspect host
files, it must be rewritten in POSIX shell or another host-substrate primitive.
If it needs gateway Laravel/artisan/PDO work, it belongs in
`RemoteOrbitRuntimeExecutor`. If it needs packaged node-local helper logic with
host file access and PHP/PDO, it belongs in `RemoteLocalExecutor`.

### RemoteOrbitRuntimeExecutor

`RemoteOrbitRuntimeExecutor` SSHs to the node and then enters the node's
`orbit-runtime` container, normally with `docker exec` or `docker exec -i`.
It is the lane for gateway Laravel/artisan/PDO work that belongs to the gateway
runtime container on Docker-first-managed nodes.

Required work:

- Orbit `php artisan ...` commands executed on a node.
- Gateway Laravel boot, Eloquent/PDO access, database query helpers, and local
  SQLite work that belongs to the gateway runtime container.
- Composer operations for Orbit itself.
- VPN command forwarding when the forwarded command is an Orbit Artisan command
  that belongs to the gateway runtime container.

Forbidden work:

- Host bootstrap, Docker installation, WireGuard host mutation, Caddy host
  artifact writes, UFW/sysctl/SSH hardening, and file ownership repair.
- App/workspace PHP execution. App and workspace PHP commands run inside their
  own app/workspace containers; they are not Orbit runtime work unless they
  boot Orbit itself.
- Packaged node-local helper logic that needs host file access and PHP/PDO.
  That belongs in `RemoteLocalExecutor`.

### RemoteLocalExecutor

`RemoteLocalExecutor` SSHs to the node and invokes the host-installed
`apps/cli` internal executor command. It is for packaged node-local helper
logic that needs host file access and PHP/PDO without relying on ad hoc
`python3` or `sqlite3` snippets.

The gateway primitive composes `/usr/local/bin/orbit internal:* ...` commands
with `LocalExecutorCommandBuilder`, mints a short-lived gateway operation token,
and dispatches that host command through the SSH transport. It never wraps local
executor work in `docker exec orbit-runtime`; the role-aware host `orbit`
launcher selects `apps/cli` on non-gateway nodes.

Required work:

- Workspace adapter SQLite lookups for Polyscope and OpenCode when the adapter
  database lives in a node-local host path.
- Wg-easy SQLite state updates and ownership checks that must preserve
  node-local file access and ownership semantics.
- Prepared-topology fixture helpers that must run inside a topology node and
  need PHP/PDO without adding host `sqlite3`.

Forbidden work:

- Public command execution or direct user-invoked state mutation.
- Gateway Laravel/artisan/PDO work that belongs in `orbit-runtime`.
- Host substrate mutation such as Docker installation, WireGuard host mutation,
  Caddy artifact writes, package installation, or SSH hardening.
- App/workspace runtime PHP execution, which belongs in app/workspace
  containers.

Every `RemoteLocalExecutor` invocation must carry a gateway-issued operation
token. The local executor validates the token before side effects, and
node-local CLI execution is never an authority bypass. The token id corresponds
to the gateway operation id supplied in `ORBIT_OPERATION_ID`, or to a generated
operation id when the caller did not provide one.

Every completion-based `RemoteLocalExecutor::runInternal()` dispatch writes two
gateway-owned activity records on the `local_executor` channel:

- `local_executor.dispatching` before SSH dispatch, after command validation and
  token minting. It records `lane=local-executor`, operation id, target node id
  and name, internal command name, scalar arguments/options, and the
  `LocalExecutorCommandBuilder::buildAuditLine()` command shape.
- `local_executor.completed` after the transport returns or throws. It records
  the same operation id, target node, command name, success/failure status, exit
  code when available, duration, and stdout/stderr summaries capped at 4 KiB
  with a `[truncated]` suffix.

Operation tokens are secret material. Activity descriptions, subjects,
properties, stdout/stderr summaries, and sanitized local-executor shell-failure
exceptions must never contain the raw token. The dispatch record uses the
builder's redacted audit line, and completion summaries defensively scrub both
`--operation-token=...` arguments, including whitespace around `=`, and the
exact minted token value before truncation. Generic transport exceptions are
rewrapped without a previous-exception chain after logging the sanitized
exception class and message, because PHP exception traces may retain
token-bearing method arguments from the failed transport call.

`LocalExecutorCommandBuilder` is the only sanctioned way to compose internal
CLI invocations sent through this lane. It validates the `internal:*` command
name and option keys, escapes every positional argument and option value,
always appends `--operation-token` and `--json`, and exposes a token-redacted
audit line. Do not hand-build local-executor shell strings at call sites.

Callers that need arguments or command options use:

```php
RemoteLocalExecutor::runInternal(Node $node, string $commandName, array $arguments = [], array $commandOptions = [], array $transportOptions = [])
```

Long-running `start()` and `startInternal()` dispatch is unsupported for
`RemoteLocalExecutor` until async audit semantics are designed. Local-executor
work must use `runInternal()` for completion-based dispatch and result
recording; asynchronous workflows should route through `runInternal()` plus
polling, or through a different execution lane with its own audit contract.

The inherited `RemoteShell::run()` method is reserved for command-name-only
internal invocations such as `internal:executor:verify`; callers must not encode
structured local-executor input as JSON or as a free-form shell script.

## Hard Rules

Use these rules for every new or migrated gateway-to-node execution path.

- On Docker-first-managed nodes, gateway Laravel/artisan/PDO work MUST go
  through `RemoteOrbitRuntimeExecutor`.
- Packaged node-local helper logic that needs host file access and PHP/PDO MUST
  go through `RemoteLocalExecutor`.
- Host-shell PHP is forbidden as a steady-state implementation detail outside
  the host-installed CLI/local-executor artifact.
- `RemoteShell` is transport, not a workload classification. New call sites
  must choose `RemoteHostExecutor`, `RemoteOrbitRuntimeExecutor`, or
  `RemoteLocalExecutor` explicitly.
- A host-lane command may control containers, including `docker exec`, but it
  must not execute Orbit PHP on the host.
- A runtime-lane command may read/write Orbit state through Laravel/PDO inside
  `orbit-runtime`, but it must not mutate host substrate directly.
- A local-executor command may read/write node-local helper state with PHP/PDO,
  but it must validate the gateway-issued operation token before side effects
  and must not become a public authority path.
- Legacy fallback paths that run PHP/Composer/Artisan on the host are not valid
  on Docker-first-managed nodes.

## Orbit Caddy Isolation

`orbit-caddy` stays a separate fleet proxy container based on `caddy:2-alpine`.
It must not be folded into `orbit-runtime`. Caddy route files, include
boundaries, certificates, reloads, and container repair remain
`RemoteHostExecutor` work because they control the host proxy substrate.

The `orbit-caddy` isolation boundary is independent from the app/workspace
FrankenPHP runtime containers.

## Deferred

The FrankenPHP base-image switch is deferred and out of scope for this
contract. This page does not change app/workspace FrankenPHP image selection,
worker mode, or app runtime rendering.

## Current Consumer Classification

Inventory basis: `grep -rn 'SshRemoteShell\|RemoteShell' app/ bin/ tests/`,
plus the consumers of `App\Contracts\RemoteShell`,
`App\Contracts\RemoteShellStream`, and
`App\Contracts\StartsRemoteShellProcesses`, on May 24, 2026.

The production table lists runtime-affecting call sites. Container bindings,
transport implementations, and direct `SshRemoteShell` transport tests are
listed after it. Contract definitions are inventory anchors only; because they
do not execute workloads, they are not classified as consumer rows. Other test
hits from the grep are fakes, fixtures, or
`RemoteShellResult` assertions; they are not separate execution consumers and
inherit the lane of the production code they exercise.

| Consumer | Lane | Classification |
|---|---|---|
| `apps/gateway/app/Console/Commands/AppExecCommand.php:110,125` | `RemoteHostExecutor` | Inspects and executes inside an app runtime container through Docker. |
| `apps/gateway/app/Console/Commands/AppRegisterCommand.php:90` | `RemoteHostExecutor` | Probes a host app path before gateway registration. |
| `apps/gateway/app/Console/Commands/NodeNewCommand.php:2123` | `RemoteHostExecutor` | Passes host shell to node security baseline installers during provisioning. |
| `apps/gateway/app/Console/Commands/VpnCommandSupport.php:87` | `RemoteOrbitRuntimeExecutor` | Forwards `php artisan vpn-*` work to the active VPN role node; forwarded gateway Laravel/artisan work must run inside `orbit-runtime`. |
| `apps/gateway/app/Console/Commands/WorkspaceExecCommand.php:143,158` | `RemoteHostExecutor` | Inspects and executes inside a workspace runtime container through Docker. |
| `apps/gateway/app/Http/Controllers/Api/UpdateAllController.php:272,405` (`pulling_source`) | `RemoteHostExecutor` | Resolves `RemoteShell` and starts the `git pull --ff-only` stage for host source checkout. |
| `apps/gateway/app/Http/Controllers/Api/UpdateAllController.php:272,405` (`installing_dependencies`) | `RemoteOrbitRuntimeExecutor` | Resolves `RemoteShell` and starts the `docker exec orbit-runtime composer install --no-interaction` stage for Orbit dependencies. |
| `apps/gateway/app/Http/Controllers/Api/UpdateAllController.php:272,405` (`running_migrations`) | `RemoteOrbitRuntimeExecutor` | Resolves `RemoteShell` and starts the `docker exec orbit-runtime php artisan migrate --force` stage for Orbit migrations. |
| `apps/gateway/app/Actions/Apps/CreateAppSourceOnNode.php:30` | `RemoteHostExecutor` | Creates/checks source directories and git material on the host. |
| `apps/gateway/app/Actions/Apps/EnsureAppProcessRuntimeUnits.php:105,130` | `RemoteHostExecutor` | Repairs Supervisor residual units and Docker process runtime artifacts. |
| `apps/gateway/app/Actions/Apps/EnsureAppProxyRoute.php:70,105,134,244,256` | `RemoteHostExecutor` | Writes and reads Caddy route artifacts on serving, router, and backend hosts. |
| `apps/gateway/app/Actions/Apps/RemoveApp.php:124` | `RemoteHostExecutor` | Removes app host/runtime artifacts. |
| `apps/gateway/app/Actions/Processes/AddProcess.php:115` | `RemoteHostExecutor` | Starts explicit Supervisor residual process units; Docker units are delegated to Docker managers. |
| `apps/gateway/app/Actions/Processes/EditProcess.php:125` | `RemoteHostExecutor` | Restarts explicit Supervisor residual units. |
| `apps/gateway/app/Actions/Processes/RemoveProcess.php:98` | `RemoteHostExecutor` | Removes explicit Supervisor residual units. |
| `apps/gateway/app/Actions/Processes/RestartProcesses.php:105` | `RemoteHostExecutor` | Restarts explicit Supervisor residual units. |
| `apps/gateway/app/Actions/Processes/ShowProcessLogs.php:59` | `RemoteHostExecutor` | Reads process logs from Docker or explicit Supervisor host artifacts. |
| `apps/gateway/app/Actions/Processes/StartProcesses.php:106` | `RemoteHostExecutor` | Starts explicit Supervisor residual units. |
| `apps/gateway/app/Actions/Processes/StopProcesses.php:106` | `RemoteHostExecutor` | Stops explicit Supervisor residual units. |
| `apps/gateway/app/Actions/Workspaces/CreateWorkspace.php:113` | `RemoteHostExecutor` | Preflights target node reachability before workspace creation. |
| `apps/gateway/app/Actions/Workspaces/RemoveWorkspace.php:73,87,106` | `RemoteHostExecutor` | Removes process units, runs teardown commands, and removes host worktree paths. |
| `apps/gateway/app/Actions/Workspaces/SetupWorkspace.php:330,342` | `RemoteHostExecutor` | Installs host process artifacts and starts explicit Supervisor residual units. |
| `apps/gateway/app/Services/AgentIde/CoreAgentIdeWorkspacePathResolver.php:36,70` | `RemoteLocalExecutor` | Current OpenCode/Polyscope lookup scripts use host Python/SQLite; adapter state lookup must move into token-gated local executor logic. |
| `apps/gateway/app/Services/Apps/AppRuntimeContainerManager.php:386` | `RemoteHostExecutor` | Creates, inspects, removes, and starts app runtime containers through Docker. |
| `apps/gateway/app/Services/Apps/AppsFixer.php:170` | `RemoteHostExecutor` | Repairs app host/runtime artifacts from gateway intent. |
| `apps/gateway/app/Services/Apps/AppsProbe.php:81,316,456` | `RemoteHostExecutor` | Uses POSIX/Docker host probes for app paths, runtime configs, and runtime containers. |
| `apps/gateway/app/Services/Apps/AppWorkerReadiness.php:63` | `RemoteHostExecutor` | Checks app worker/readiness artifacts on the host/runtime boundary. |
| `apps/gateway/app/Services/Ca/OrbitSiteCertificateInstaller.php:30` | `RemoteHostExecutor` | Writes leaf cert/key files into host-managed certificate paths. |
| `apps/gateway/app/Services/DatabaseConnections/DatabaseConnectionAdopter.php:108` | `RemoteHostExecutor` | Reads app/workspace `.env` files from host paths for adoption. |
| `apps/gateway/app/Services/DatabaseConnections/DatabaseConnectionExecutor.php:84` | `RemoteOrbitRuntimeExecutor` | Runs local SQLite query work through Orbit code/PDO on the owning node. |
| `apps/gateway/app/Services/DatabaseConnections/DatabaseConnectionProbe.php:106,213` | `RemoteHostExecutor` | Reads app/workspace `.env` files from host paths for drift probes. |
| `apps/gateway/app/Services/DatabaseConnections/DatabaseConnectionRestorer.php:45,79` | `RemoteHostExecutor` | Writes and reads app/workspace `.env` files on host paths. |
| `apps/gateway/app/Services/Deploy/DeployManager.php:159,339,434,495` | `RemoteHostExecutor` | Dispatches deploy steps and app-container warmups; Docker-first PHP/Composer/Artisan deploy commands must run in app containers, not host PHP. |
| `apps/gateway/app/Services/Firewall/FirewallRuleFixer.php:32,36,37,57,58` | `RemoteHostExecutor` | Applies UFW host firewall rules and reloads UFW. |
| `apps/gateway/app/Services/Firewall/FirewallRuleProbe.php:52` | `RemoteHostExecutor` | Reads UFW host firewall state. |
| `apps/gateway/app/Services/Nodes/NodeIdentityArtifactProbe.php:21,58` | `RemoteHostExecutor` | Reads the WireGuard interface public key with host `wg`; that host interface probe is substrate work. |
| `apps/gateway/app/Services/Nodes/NodeIdentityArtifactProbe.php:21,60` | `RemoteOrbitRuntimeExecutor` | Boots Laravel and queries Orbit state to map WireGuard identity; that PHP/PDO portion must run inside `orbit-runtime`. |
| `apps/gateway/app/Services/Nodes/NodesProbe.php:467,862` | `RemoteHostExecutor` | Checks host user and SSH reachability. |
| `apps/gateway/app/Services/Nodes/NodeSecurityPostureProbe.php:110,206` | `RemoteHostExecutor` | Checks host security posture; current host PHP helper must be rewritten as host-substrate shell. |
| `apps/gateway/app/Services/Nodes/Roles/RoleBaselines/AgentRoleBaseline.php:71,73,74` | `RemoteHostExecutor` | Creates and locks the host `agent` user. |
| `apps/gateway/app/Services/OrbitUpdater.php:70,72,111` (`pullRemoteSource`) | `RemoteHostExecutor` | Runs `git pull --ff-only` in the host source checkout. |
| `apps/gateway/app/Services/OrbitUpdater.php:75,77,111` (`installRemoteDependencies`) | `RemoteOrbitRuntimeExecutor` | Runs `docker exec orbit-runtime composer install --no-interaction` for Orbit dependencies. |
| `apps/gateway/app/Services/OrbitUpdater.php:80,82,111` (`runRemoteMigrations`) | `RemoteOrbitRuntimeExecutor` | Runs `docker exec orbit-runtime php artisan migrate --force` for Orbit migrations. |
| `apps/gateway/app/Services/Processes/ProcessDockerRuntimeManager.php:174` | `RemoteHostExecutor` | Creates and controls Docker process runtime units. |
| `apps/gateway/app/Services/Processes/ProcessesProbe.php:66,127,230` | `RemoteHostExecutor` | Probes Docker process containers and explicit Supervisor residual artifacts; current host PHP helper at `:230` must be rewritten as host-substrate shell. |
| `apps/gateway/app/Services/Proxy/ProxyRouteFixer.php:79,113,151,178,349,386,456` | `RemoteHostExecutor` | Writes, removes, reloads, and repairs `orbit-caddy` route artifacts. |
| `apps/gateway/app/Services/Proxy/ProxyRouteProbe.php:120,166,236,336` | `RemoteHostExecutor` | Probes `orbit-caddy` route files, container state, and proxy reachability. |
| `apps/gateway/app/Services/RemoteShell/RemoteSecretFile.php:25,49` | `RemoteHostExecutor` | Stages and removes temporary secret files on the host. |
| `apps/gateway/app/Services/RemoteShell/RemoteShellPool.php:59,91` | `RemoteHostExecutor` | Executes queued SSH jobs; current producer is schedule dispatch and inherits host-lane dispatch rules. |
| `apps/gateway/app/Services/RuntimeBackend/GatewayRuntimeBackendProbe.php:35` | `RemoteHostExecutor` | Probes the host Docker state for `orbit-runtime`. |
| `apps/gateway/app/Services/RuntimeBackend/RuntimeBackendProbe.php:19` | `RemoteHostExecutor` | Probes explicit Supervisor residual availability. |
| `apps/gateway/app/Services/Schedules/ScheduleDispatcher.php:76,90` | `RemoteHostExecutor` | Dispatches generic schedule jobs through the host SSH pool; schedule definitions that execute Orbit PHP must render runtime-lane commands before enqueue. |
| `apps/gateway/app/Services/Schedules/SchedulesFixer.php:56` | `RemoteHostExecutor` | Repairs scheduler host/runtime artifacts on the gateway node. |
| `apps/gateway/app/Services/Schedules/SchedulesProbe.php:43,90` | `RemoteHostExecutor` | Probes gateway runtime container/scheduler and target host reachability. |
| `apps/gateway/app/Services/Security/HomeDirectoryLockdownInstaller.php:14` | `RemoteHostExecutor` | Mutates host home directory permissions and ownership. |
| `apps/gateway/app/Services/Security/PublicSshDenyInstaller.php:17` | `RemoteHostExecutor` | Installs UFW host rules for SSH exposure. |
| `apps/gateway/app/Services/Security/SshdHardenedInstaller.php:14` | `RemoteHostExecutor` | Writes SSH daemon host config and reloads SSH. |
| `apps/gateway/app/Services/Security/SysctlBaselineInstaller.php:14` | `RemoteHostExecutor` | Writes host sysctl baseline and applies kernel settings. |
| `apps/gateway/app/Services/Security/UnattendedUpgradesInstaller.php:15` | `RemoteHostExecutor` | Installs and configures host unattended-upgrades packages. |
| `apps/gateway/app/Services/Tools/ToolInstaller.php:80,97` | `RemoteHostExecutor` | Runs catalog install and credential scripts on the host/tool substrate. |
| `apps/gateway/app/Services/Tools/ToolLifecycleManager.php:85,144,185` | `RemoteHostExecutor` | Starts, stops, and restarts host/tool runtime commands. |
| `apps/gateway/app/Services/Tools/ToolLogFollower.php:37` | `RemoteHostExecutor` | Streams host/tool logs through `RemoteShellStream`. |
| `apps/gateway/app/Services/Tools/ToolLogReader.php:45` | `RemoteHostExecutor` | Reads host/tool logs. |
| `apps/gateway/app/Services/Tools/ToolReconfigurer.php:68` | `RemoteHostExecutor` | Runs catalog reconfiguration scripts on the host/tool substrate. |
| `apps/gateway/app/Services/Tools/ToolRemover.php:48` | `RemoteHostExecutor` | Runs catalog removal scripts on the host/tool substrate. |
| `apps/gateway/app/Services/Tools/ToolsFixer.php:58,286,309,310` | `RemoteHostExecutor` | Repairs tool config, credentials, containers, and host agent user state. |
| `apps/gateway/app/Services/Tools/ToolsProbe.php:129,181,850` | `RemoteHostExecutor` | Probes tool binaries, Docker images, containers, and agent user state; current host PHP helper at `:129` must be rewritten as host-substrate shell. |
| `apps/gateway/app/Services/Tools/ToolUpdater.php:63,172` | `RemoteHostExecutor` | Runs catalog update scripts on the host/tool substrate. |
| `apps/gateway/app/Services/Updates/UnattendedUpgradesDriver.php:51,94,105` | `RemoteHostExecutor` | Probes, installs, and runs host unattended-upgrades. |
| `apps/gateway/app/Services/Workspaces/EnsureWorkspaceProxyRoute.php:79,115,145,162,174` | `RemoteHostExecutor` | Writes and reads Caddy route artifacts for workspace routes. |
| `apps/gateway/app/Services/Workspaces/OpenCodeWorkspaceDriver.php:109` | `RemoteHostExecutor` | Aligns host git branches for OpenCode workspaces. |
| `apps/gateway/app/Services/Workspaces/PolyscopeWorkspaceBranchAligner.php:19,74,91` | `RemoteHostExecutor` | Checks and renames the workspace Git branch in the host workspace path. |
| `apps/gateway/app/Services/Workspaces/PolyscopeWorkspaceBranchAligner.php:19,96,99` | `RemoteLocalExecutor` | Mutates Polyscope SQLite adapter state; the current Python/SQLite helper must move to token-gated local executor logic. |
| `apps/gateway/app/Services/Workspaces/PolyscopeWorkspaceDriver.php:143` | `RemoteLocalExecutor` | Current Polyscope config lookup uses host Python/SQLite; adapter state lookup must move into token-gated local executor logic. |
| `apps/gateway/app/Services/Workspaces/WorkspaceRuntimeContainerManager.php:354` | `RemoteHostExecutor` | Creates, inspects, removes, and starts workspace runtime containers through Docker. |
| `apps/gateway/app/Services/Workspaces/WorkspaceSetupStepRunner.php:53` | `RemoteHostExecutor` | Dispatches setup steps; PHP/Composer steps are wrapped into the workspace container. |
| `apps/gateway/app/Services/Workspaces/WorkspacesProbe.php:102` | `RemoteHostExecutor` | Probes workspace host path, user, and filesystem state; current host PHP helper must be rewritten as host-substrate shell. |
| `apps/gateway/app/Services/Workspaces/WorktreeWorkspaceDriver.php:23` | `RemoteHostExecutor` | Creates host git worktrees. |

### Bindings, implementations, and direct transport tests

These entries are not product workloads, but they are direct `SshRemoteShell`
or `RemoteShell` infrastructure hits from the inventory.

| Consumer | Lane | Classification |
|---|---|---|
| `apps/gateway/app/Providers/AppServiceProvider.php:83` | `RemoteHostExecutor` | Current container binding from `RemoteShell` to `SshRemoteShell`; runtime-lane Orbit PHP must not keep using this binding as host PHP. |
| `apps/gateway/app/Providers/AppServiceProvider.php:84` | `RemoteHostExecutor` | Current container binding from `RemoteShellStream` to `SshRemoteShellStream` for host/tool log streams. |
| `apps/gateway/app/Services/RemoteShell/SshRemoteShell.php:31,82` | `RemoteHostExecutor` | SSH transport implementation for host-lane `run`/`start`; future runtime executor may reuse SSH but must add `docker exec orbit-runtime`. |
| `apps/gateway/app/Services/RemoteShell/SshRemoteShellStream.php:30` | `RemoteHostExecutor` | SSH streaming transport for host/tool log streams. |
| `apps/gateway/tests/Feature/Services/SshRemoteShellTest.php:32,64,85,105,137,168,204,224,246,263` | `RemoteHostExecutor` | Direct transport tests for `SshRemoteShell`; no product PHP workload. |
| `apps/gateway/tests/Unit/Services/RemoteShell/WithMetadataTransportTest.php:34,45,65` | `RemoteHostExecutor` | Direct transport metadata tests for `SshRemoteShell`; no product PHP workload. |
