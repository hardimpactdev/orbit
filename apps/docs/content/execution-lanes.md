# Runtime Execution Lanes

This page defines how the gateway may execute work on managed nodes. Orbit's
managed execution target has two normal paths: `gateway-only` for gateway-owned
reads/writes and `agent-push` for node-local execution through typed command
envelopes. In V1, agent-push envelopes are structured `binary + argv` requests
created by the gateway and executed by the node Agent through a node-local
binary allowlist.

Transport selection chooses the delivery path for an envelope:
`gateway-only`, `agent-push`, `auto`, or explicit
`transitional-ssh-fallback` during v1 migration/recovery. `auto` selects
agent-push for capable envelopes and fails clearly when agent-push is
unavailable; it does not silently choose SSH. Every gateway-to-node workload
that requires node execution belongs to an execution lane; gateway-only work
bypasses node execution. See [Tech Stack](tech-stack.md#gateway-to-node).
Break-glass SSH is operator-owned super-admin recovery outside normal Orbit
command execution.

Gateway callers select the explicit transitional SSH path by sending the
`transitional-ssh-fallback` node transport preference. Requests that do not
carry that preference stay on `auto`.

## Scope

An Orbit-managed node has the substrate needed by the artifacts its roles own:
`orbit-gateway` and `orbit-scheduler` Swarm services on gateway nodes,
`orbit-caddy` for proxying when needed, Docker-backed app/workspace web
containers and role/tool services where those artifacts are declared, and
systemd units for configured Linux host command processes. Source-dev Docker
and Incus topologies are development and E2E lanes. Artifact-prod installs use
the native CLI binary artifact and production images.

Before that baseline exists, bootstrap may use host shell commands to install
Docker, prepare the `orbit` user, clone Orbit source, and create the first
runtime containers. After the baseline exists, gateway Laravel/artisan/PDO work
must not rely on host PHP, host Composer, host Python, host SQLite, or host
database client binaries. The CLI/local-executor artifact runs in the binary's
embedded PHP in production installs. Source-mounted Docker/Incus development
and E2E nodes invoke `<source>/apps/cli/orbit`. Host PHP/PHP-FPM is not the
app/workspace *web* runtime — FrankenPHP containers serve apps. App-source CLI
(`php`, `composer`, `artisan`, the Laravel installer) does run on the app
node's host PHP toolchain; see `RemoteHostExecutor`.

## Lanes

```text
RemoteHostExecutor:
  SSH host substrate, bootstrap, Docker/container control, host git,
  system packages, WireGuard host mutation, Caddy artifacts.

RemoteGatewayRuntimeExecutor:
  SSH then execute inside the orbit-gateway container boundary for gateway
  Laravel/artisan/PDO work.

RemoteLocalExecutor:
  Agent-push to the node Agent, then invoke the node-local Orbit CLI entry
  point's internal executor command.
  It is for packaged node-local helper logic that needs host file access
  and PHP/PDO without relying on ad hoc python3/sqlite3 snippets.
  Production installs still use the native CLI binary artifact; source-dev
  Docker/Incus topologies point /usr/local/bin/orbit directly at
  <source>/apps/cli/orbit.
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
- App/workspace web-container lifecycle and control through Docker (create,
  start, stop, recreate the FrankenPHP serving container).
- App-source CLI on the node's host PHP toolchain — `php`, `composer`,
  `artisan` (deploy steps, `composer install`, the Laravel installer,
  app-scoped `schedule:run`), version-matched to the app. This is the app's
  own toolchain, not Orbit's framework runtime.
- Systemd unit lifecycle, logs, and repair for configured Linux host command
  process units.

Forbidden work:

- Running **Orbit's own** `php`, `composer`, `artisan`, PDO, Eloquent, Laravel
  boot, or database query logic (the gateway application runtime) on the host
  after the Docker baseline exists. App-source CLI is separate and is allowed
  above.
- Using host Python, host `sqlite3`, `psql`, `mysql`, or similar client
  binaries as steady-state helpers for Orbit-owned state.
- Treating the host `orbit` launcher as the PHP execution path from the gateway
  to a node.

If work in the host lane currently needs PHP/Python/SQLite only to inspect host
files, it must be rewritten in POSIX shell or another host-substrate primitive.
If it needs gateway Laravel/artisan/PDO work, it belongs in
`RemoteGatewayRuntimeExecutor`. If it needs packaged node-local helper logic with
host file access and PHP/PDO, it belongs in `RemoteLocalExecutor`.

### RemoteGatewayRuntimeExecutor

`RemoteGatewayRuntimeExecutor` SSHs to the gateway node and then enters the
`orbit-gateway` container boundary, or runs the equivalent one-shot gateway
image command when the operation is replacing the gateway service itself. It is
the lane for gateway Laravel/artisan/PDO work that belongs to the gateway
runtime container on managed gateway nodes.

Required work:

- Orbit `php artisan ...` commands executed on a node.
- Gateway Laravel boot, Eloquent/PDO access, and database query helpers for
  gateway-owned runtime state.
- Composer operations for source-dev Orbit checkouts when explicitly in that
  lane.
- VPN command forwarding when the forwarded command is an Orbit Artisan command
  that belongs to the gateway container.

Forbidden work:

- Host bootstrap, Docker installation, WireGuard host mutation, Caddy host
  artifact writes, UFW/sysctl/SSH hardening, and file ownership repair.
- App/workspace PHP execution. App and workspace web requests run in their own
  FrankenPHP containers; app-source CLI (`php`/`composer`/`artisan`) runs on
  the node's host PHP toolchain via `RemoteHostExecutor`. Neither is
  gateway-service work.
- Packaged node-local helper logic that needs host file access and PHP/PDO.
  That belongs in `RemoteLocalExecutor`.

### RemoteLocalExecutor

`RemoteLocalExecutor` invokes the node-local Orbit CLI entry point's internal
executor command through the selected typed node transport. The normal managed
transport is `agent-push`; explicit `transitional-ssh-fallback` is reserved for
migration/recovery. It is for packaged node-local helper logic that needs host
file access and PHP/PDO without relying on ad hoc
`python3` or `sqlite3` snippets. In source-mounted nodes, `/usr/local/bin/orbit`
points directly at `<source>/apps/cli/orbit`, and mutable node-local Orbit
state lives under `~/.config/orbit`.

The gateway primitive composes `/usr/local/bin/orbit internal:* ...` commands
with `LocalExecutorCommandBuilder`, mints a short-lived gateway operation token,
and dispatches the command as an allowlisted `binary + argv` Agent envelope.
It never wraps local executor work in gateway container execution.
`RemoteLocalExecutor` cannot invoke public commands; internal executor commands
verify operation tokens through the gateway API before any side effects, and
nodes do not store executor token signing material.

Operation tokens are signed with the gateway's dedicated operation-token key,
carry a key id for rotation, bind the operation id, target node, command, and
canonical hash of the dispatched `argv`, `cwd`, environment, and input, and are
checked for not-before/expiry before use. Verification consumes the
corresponding operation run; a second verification for the same operation id
returns a distinct already-dispatched denial instead of authorizing another
node-local execution.

Gateway API requests normally authenticate by WireGuard peer identity. The
`/api/internal-executor/token/verify` endpoint has one scoped exception for
gateway self-execution: when service-network NAT hides the gateway host
Agent's WireGuard or loopback peer identity, a valid, argument-bound token
targeting an active gateway-role node may establish that gateway node identity
for this verify endpoint only. Identity resolution never consumes the token;
the controller consumes it only after authorization. Non-gateway targets and
all other gateway API routes continue to require WireGuard peer identity.

During `update:all`, fleet CLI/Agent self-update is the bootstrap exception to
normal agent-push execution: the gateway service may already be running a
verifier that requires bound command context while the installed Agent still
sends the older verify payload, or the installed CLI may not contain the new
internal fleet-update command. The install step therefore uses explicit
`transitional-ssh-fallback`, downloads and verifies the candidate CLI artifact,
and executes that candidate binary for `internal:fleet-update:install-cli`
instead of invoking the installed launcher or relaxing token verification.

For this SSH bootstrap, the JSON install payload is written to a temporary
payload file and the file path plus SHA-256 are bound in command argv through
`--payload-file` and `--payload-sha256`. The verifier continues to bind argv,
cwd, and the non-secret executor environment; stdin itself is not part of this
bootstrap token context because the CLI guard verifies before the install
command reads the payload. The verify endpoint has no legacy unbound payload
form. Bootstrap staging diagnostics must not prefix the wrapped command stdout;
the transport result stdout remains the candidate internal command's stdout
verbatim.

#### Result-boundary redaction patterns

Activity rows, operation_runs rows, internal-command JSON results, and
exception messages must never contain raw secret material. Every redaction
layer (the internal command's own pre-serialization scan, the gateway-side
`OperationResultHandler`, and `RemoteLocalExecutor`'s exception sanitizer)
scrubs values matching this pattern set:

- `--operation-token=...` arguments (with or without whitespace around `=`)
  and the exact minted token value
- keys named `operation_token`, `executor_secret`, `password`, `bearer`,
  `secret`, `_token`, `api_key`
- substrings matching PEM blocks
  (`-----BEGIN [A-Z ]+-----` through `-----END [A-Z ]+-----`)

Redaction is applied at both the internal-command result boundary (before
JSON serialization) and the gateway `OperationResultHandler` (before
persistence). Tests assert both layers for every pattern.

Required work:

- Workspace adapter SQLite lookups for Polyscope and OpenCode when the adapter
  database lives in a node-local host path.
- SQLite database query helpers for app, workspace, or database-role files
  resolved by the gateway but executed on the owning node's host path.
- Wg-easy SQLite state updates and ownership checks that must preserve
  node-local file access and ownership semantics.
- Prepared-topology fixture helpers that must run inside a topology node and
  need PHP/PDO without adding host `sqlite3`.

Forbidden work:

- Public command execution or direct user-invoked state mutation.
- Gateway Laravel/artisan/PDO work that belongs in `orbit-gateway`.
- Host substrate mutation such as Docker installation, WireGuard host mutation,
  Caddy artifact writes, package installation, or SSH hardening.
- App/workspace runtime PHP execution, which belongs in app/workspace
  containers.

Every `RemoteLocalExecutor` invocation must carry a gateway-issued operation
token. The local executor validates the token before side effects, and
node-local CLI execution is never an authority bypass. The token id corresponds
to the gateway operation id supplied in `ORBIT_OPERATION_ID`, or to a generated
operation id when the caller did not provide one. The command process spawned
after Agent-side verification carries a gateway authorization marker so the
node-local internal command can confirm the operation id, command, and token
without spending the single-use verify token twice.

Every completion-based `RemoteLocalExecutor::runInternal()` dispatch writes two
gateway-owned activity records on the `local_executor` channel:

- `local_executor.dispatching` before transport dispatch, after command
  validation and token minting. It records `lane=local-executor`, operation id,
  target node id and name, internal command name, scalar arguments/options, and
  the `LocalExecutorCommandBuilder::buildAuditLine()` command shape.
- `local_executor.completed` after the transport returns or throws. It records
  the same operation id, target node, command name, success/failure status, exit
  code when available, duration, and stdout/stderr summaries capped at 4 KiB
  with a `[truncated]` suffix.

`RemoteLocalExecutor::streamInternal()` uses the same dispatch/completion
activity shape for approved raw streams, but it does not buffer streamed payload
content into the gateway activity or operation result.

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
name and option keys, enforces a closed command-and-target-role allow-list,
escapes every positional argument and option value, always appends
`--operation-token` and `--json`, and exposes a token-redacted audit line. Do
not hand-build local-executor shell strings at call sites.

Current allowed hidden CLI commands:

| Command | Allowed target roles |
| --- | --- |
| `internal:executor:verify` | any active workload role |
| `internal:wg-easy:state` | `vpn` |
| `internal:database-query-local` | `app-dev`, `app-prod`, `database` |
| `internal:process-logs` | `app-dev`, `app-prod`, `database`, `agent` |
| `internal:workspace-adapter:lookup` | `app-dev` |
| `internal:workspace-adapter:update` | `app-dev` |

Callers that need arguments or command options use:

```php
RemoteLocalExecutor::runInternal(Node $node, string $commandName, array $arguments = [], array $commandOptions = [], array $transportOptions = [])
```

Long-running `start()` and `startInternal()` dispatch is unsupported for
`RemoteLocalExecutor` until async audit semantics are designed. Local-executor
work must use `runInternal()` for completion-based dispatch and result
recording, or `streamInternal()` for an approved raw stream such as
`process:logs --follow`. Other asynchronous workflows should route through a
lane with its own audit contract.

The inherited `RemoteShell::run()` method is reserved for command-name-only
internal invocations such as `internal:executor:verify`; callers must not encode
structured local-executor input as JSON or as a free-form shell script.

## Hard Rules

Use these rules for every new or migrated gateway-to-node execution path.

- On managed gateway nodes, gateway Laravel/artisan/PDO work MUST
  go through `RemoteGatewayRuntimeExecutor` or the equivalent durable one-shot
  runner when the gateway service is being replaced.
- Packaged node-local helper logic that needs host file access and PHP/PDO MUST
  go through `RemoteLocalExecutor`.
- Host-shell PHP is forbidden as a steady-state implementation detail; the
  CLI/local-executor artifact uses the native CLI binary's embedded PHP in
  production installs, while source-dev Docker/Incus development and E2E
  nodes invoke `<source>/apps/cli/orbit`; host PHP remains forbidden.
- Agent push is the managed node-local execution mechanism beneath typed
  command envelopes. V1 Agent envelopes carry `operation_id`, `binary`, `argv`,
  `operation_token`, `timeout_seconds`, and `stream`; the gateway builds the
  argv and owns caller authorization, while the Agent enforces the node-local
  binary allowlist and uses no-shell process execution. Completion endpoints
  return collected stdout/stderr/status frames; stream endpoints forward raw
  stdout/stderr chunks for scoped long-running commands. Transport selection
  (`gateway-only`, `agent-push`, `auto`, or explicit
  `transitional-ssh-fallback`) decides the delivery path for a given envelope.
  Gateway-only envelopes stay on the gateway; agent execution is explicit per
  envelope. `auto` does not silently fall back to SSH when agent-push is
  unavailable. `RemoteShell` remains explicit transitional migration/recovery
  infrastructure, not a permanent first-class managed execution transport.
- A host-lane command may control containers, including `docker exec`, but it
  must not execute Orbit's own framework PHP on the host.
- A runtime-lane command may read/write Orbit state through Laravel/PDO inside
  `orbit-gateway`, but it must not mutate host substrate directly.
- A local-executor command may read/write node-local helper state with PHP/PDO,
  but it must validate the gateway-issued operation token before side effects
  and must not become a public authority path.
- Running **Orbit's own framework** PHP/Composer/Artisan on the host is not
  valid on managed nodes. App-source CLI on app-role nodes runs on the host
  PHP toolchain — see `RemoteHostExecutor`.

## Orbit Caddy Isolation

`orbit-caddy` stays a separate fleet proxy container based on `caddy:2-alpine`.
It must not be folded into `orbit-gateway`. Caddy route files, include
boundaries, certificates, reloads, and container repair remain
`RemoteHostExecutor` work because they control the host proxy substrate.

The `orbit-caddy` isolation boundary is independent from the app/workspace
FrankenPHP runtime containers.

## Deferred

The FrankenPHP base-image switch is deferred and out of scope for this
contract. This page does not change app/workspace FrankenPHP image selection,
worker mode, or app runtime rendering.

## Current Consumer Classification

Inventory basis: `rg 'SshRemoteShell|RemoteShell|ExplicitRemoteShellFallback|RemoteShellStream' apps/gateway/app apps/gateway/tests`,
plus the consumers of `App\Contracts\RemoteShell`,
`App\Contracts\RemoteShellStream`, and
`App\Contracts\StartsRemoteShellProcesses`, refreshed on July 6, 2026.

The production table lists runtime-affecting call sites. Container bindings,
transport implementations, and direct `SshRemoteShell` transport tests are
listed after it. Contract definitions are inventory anchors only; because they
do not execute workloads, they are not classified as consumer rows. Other test
hits from the grep are fakes, fixtures, or
`RemoteShellResult` assertions; they are not separate execution consumers and
inherit the lane of the production code they exercise.

| Consumer | Lane | Classification |
|---|---|---|
| `apps/gateway/app/Console/Commands/AppRegisterCommand.php:90` | `RemoteHostExecutor` | Probes a host app path before gateway registration. |
| `apps/gateway/app/Console/Commands/NodeNewCommand.php:2123` | `RemoteHostExecutor` | Passes host shell to node security baseline installers during provisioning. |
| `apps/gateway/app/Console/Commands/VpnCommandSupport.php:87` | `RemoteGatewayRuntimeExecutor` | Forwards `php artisan vpn-*` work to the active VPN role node; forwarded gateway Laravel/artisan work must run inside the gateway container boundary. |
| `apps/gateway/app/Http/Controllers/Api/UpdateAllController.php:272,405` (`pulling_source`) | `RemoteHostExecutor` | Resolves `RemoteShell` and starts the local entry-point update stage on the remote host: production/artifact targets may download and relink the binary, while source-mounted targets keep `/usr/local/bin/orbit` pointed at `<source>/apps/cli/orbit` and update by changing the mounted source. |
| `apps/gateway/app/Http/Controllers/Api/UpdateAllController.php:272,405` (`installing_dependencies`) | `RemoteGatewayRuntimeExecutor` | Legacy source-dev gateway dependency stage; production artifact updates replace this with immutable image acquisition and the one-shot runner. |
| `apps/gateway/app/Http/Controllers/Api/UpdateAllController.php:272,405` (`running_migrations`) | `RemoteGatewayRuntimeExecutor` | Legacy source-dev gateway migration stage; production artifact updates run migrations through the target gateway image. |
| `apps/gateway/app/Actions/Apps/CreateAppSourceOnNode.php:30` | `RemoteHostExecutor` | Creates/checks source directories and git material on the host. |
| `apps/gateway/app/Actions/Apps/EnsureAppProcessRuntimeUnits.php:105,130` | `RemoteHostExecutor` | Repairs process runtime artifacts. |
| `apps/gateway/app/Actions/Apps/EnsureAppProxyRoute.php:70,105,134,244,256` | `RemoteHostExecutor` | Writes and reads Caddy route artifacts on serving, router, and backend hosts. |
| `apps/gateway/app/Actions/Apps/RemoveApp.php:124` | `RemoteHostExecutor` | Removes app host/runtime artifacts. |
| `apps/gateway/app/Actions/Processes/AddProcess.php:115` | `RemoteHostExecutor` | Starts process runtime units when requested. |
| `apps/gateway/app/Actions/Processes/EditProcess.php:125` | `RemoteHostExecutor` | Restarts process runtime units when requested. |
| `apps/gateway/app/Actions/Processes/RemoveProcess.php:98` | `RemoteHostExecutor` | Removes process runtime units. |
| `apps/gateway/app/Actions/Processes/RestartProcesses.php:105` | `RemoteHostExecutor` | Restarts process runtime units. |
| `apps/gateway/app/Actions/Processes/ShowProcessLogs.php` | `RemoteLocalExecutor` | Reads bounded process logs and follows process log streams through the typed `internal:process-logs` command over agent-push. Explicit transitional fallback may still use `RemoteShellStream` for recovery. |
| `apps/gateway/app/Actions/Processes/StartProcesses.php:106` | `RemoteHostExecutor` | Starts process runtime units. |
| `apps/gateway/app/Actions/Processes/StopProcesses.php:106` | `RemoteHostExecutor` | Stops process runtime units. |
| `apps/gateway/app/Actions/Workspaces/CreateWorkspace.php:113` | `RemoteHostExecutor` | Preflights target node reachability before workspace creation. |
| `apps/gateway/app/Actions/Workspaces/RemoveWorkspace.php:73,87,106` | `RemoteHostExecutor` | Removes process units, runs teardown commands, and removes host worktree paths. |
| `apps/gateway/app/Actions/Workspaces/SetupWorkspace.php:330,342` | `RemoteHostExecutor` | Installs host process artifacts and starts process runtime units. |
| `apps/gateway/app/Services/AgentIde/CoreAgentIdeWorkspacePathResolver.php:36,70` | `RemoteLocalExecutor` | Current OpenCode/Polyscope lookup scripts use host Python/SQLite; adapter state lookup must move into token-gated local executor logic. |
| `apps/gateway/app/Services/Apps/AppRuntimeContainerManager.php:386` | `RemoteHostExecutor` | Creates, inspects, removes, and starts app runtime containers through Docker. |
| `apps/gateway/app/Services/Apps/AppsFixer.php:170` | `RemoteHostExecutor` | Repairs app host/runtime artifacts from gateway intent. |
| `apps/gateway/app/Services/Apps/AppsProbe.php:81,316,456` | `RemoteHostExecutor` | Uses POSIX/Docker host probes for app paths, runtime configs, and runtime containers. |
| `apps/gateway/app/Services/Apps/AppWorkerReadiness.php:63` | `RemoteHostExecutor` | Checks app worker/readiness artifacts on the host/runtime boundary. |
| `apps/gateway/app/Services/Ca/OrbitSiteCertificateInstaller.php:30` | `RemoteHostExecutor` | Writes leaf cert/key files into host-managed certificate paths. |
| `apps/gateway/app/Services/WebSockets/WebSocketCertificateInstaller.php:30` | `RemoteHostExecutor` | Writes WebSocket backend cert/key files into host-managed certificate paths. |
| `apps/gateway/app/Services/WebSockets/WebSocketRuntimeSourceInstaller.php` | `RemoteHostExecutor` | Fallback path for non-self-contained local Reverb images: syncs `apps/reverb/` to `/opt/orbit/websocket/current` and installs dependencies with host Composer. Production Reverb images install Composer dependencies at image build time and skip this source path. |
| `apps/gateway/app/Services/DatabaseConnections/DatabaseConnectionAdopter.php:108` | `RemoteHostExecutor` | Reads app/workspace `.env` files from host paths for adoption. |
| `apps/gateway/app/Services/DatabaseConnections/DatabaseConnectionExecutor.php:84` | `RemoteGatewayRuntimeExecutor` | Runs local SQLite query work through Orbit code/PDO on the owning node. |
| `apps/gateway/app/Services/DatabaseConnections/DatabaseConnectionProbe.php:106,213` | `RemoteHostExecutor` | Reads app/workspace `.env` files from host paths for drift probes. |
| `apps/gateway/app/Services/DatabaseConnections/DatabaseConnectionRestorer.php:45,79` | `RemoteHostExecutor` | Writes and reads app/workspace `.env` files on host paths. |
| `apps/gateway/app/Services/Deploy/DeployManager.php:159,339,434,495` | `RemoteHostExecutor` | Dispatches deploy steps and app-container warmups; app PHP/Composer/Artisan deploy commands must run in app runtime context, not ad hoc host PHP. |
| `apps/gateway/app/Services/Firewall/FirewallRuleFixer.php:32,36,37,57,58` | `RemoteHostExecutor` | Applies UFW host firewall rules and reloads UFW. |
| `apps/gateway/app/Services/Firewall/FirewallRuleProbe.php:52` | `RemoteHostExecutor` | Reads UFW host firewall state. |
| `apps/gateway/app/Services/Nodes/NodeIdentityArtifactProbe.php:21,58` | `RemoteHostExecutor` | Reads the WireGuard interface public key with host `wg`; that host interface probe is substrate work. |
| `apps/gateway/app/Services/Nodes/NodeIdentityArtifactProbe.php:21,60` | `RemoteGatewayRuntimeExecutor` | Boots Laravel and queries Orbit state to map WireGuard identity; that PHP/PDO portion must run inside the gateway container boundary. |
| `apps/gateway/app/Services/Nodes/NodesProbe.php:467,862` | `RemoteHostExecutor` | Checks host user and SSH reachability. |
| `apps/gateway/app/Services/Nodes/NodeSecurityPostureProbe.php:110,206` | `RemoteHostExecutor` | Checks host security posture; current host PHP helper must be rewritten as host-substrate shell. |
| `apps/gateway/app/Services/Nodes/Roles/RoleBaselines/AgentRoleBaseline.php:71,73,74` | `RemoteHostExecutor` | Creates and locks the host `agent` user. |
| `apps/gateway/app/Services/OrbitUpdater.php:70,72,111` (`pullRemoteSource`) | `RemoteHostExecutor` | Runs `git pull --ff-only` in the host source checkout. |
| `apps/gateway/app/Services/OrbitUpdater.php:75,77,111` (`installRemoteDependencies`) | `RemoteGatewayRuntimeExecutor` | Legacy source-dev gateway dependency path; production artifact updates use immutable image acquisition and the durable runner. |
| `apps/gateway/app/Services/OrbitUpdater.php:80,82,111` (`runRemoteMigrations`) | `RemoteGatewayRuntimeExecutor` | Legacy source-dev gateway migration path; production artifact updates run migrations through the target gateway image. |
| `apps/gateway/app/Services/Processes/ProcessDockerRuntimeManager.php:174` | `RemoteHostExecutor` | Legacy Docker process runtime manager to retire during the process substrate migration. |
| `apps/gateway/app/Services/Processes/ProcessesProbe.php:66,127,230` | `RemoteHostExecutor` | Probes process runtime artifacts; current host PHP helper at `:230` must be rewritten as host-substrate shell. |
| `apps/gateway/app/Services/Proxy/ProxyRouteFixer.php:79,113,151,178,349,386,456` | `RemoteHostExecutor` | Writes, removes, reloads, and repairs `orbit-caddy` route artifacts. |
| `apps/gateway/app/Services/Proxy/ProxyRouteProbe.php:120,166,236,336` | `RemoteHostExecutor` | Probes `orbit-caddy` route files, container state, and proxy reachability. |
| `apps/gateway/app/Services/RemoteShell/RemoteSecretFile.php:25,49` | `RemoteHostExecutor` | Stages and removes temporary secret files on the host. |
| `apps/gateway/app/Services/RemoteShell/RemoteShellPool.php:59,91` | `RemoteHostExecutor` | Executes queued SSH jobs; current producer is schedule dispatch and inherits host-lane dispatch rules. |
| `apps/gateway/app/Services/RuntimeBackend/GatewayRuntimeBackendProbe.php:35` | `RemoteHostExecutor` | Probes the host Docker/Swarm state for `orbit-gateway` and `orbit-scheduler`. |
| `apps/gateway/app/Services/RuntimeBackend/RuntimeBackendProbe.php:19` | `RemoteHostExecutor` | Probes process runtime availability. |
| `apps/gateway/app/Services/WebSockets/WebSocketRuntimeContainerManager.php:148` | `RemoteHostExecutor` | Creates, inspects, removes, and starts WebSocket Reverb runtime containers through Docker. |
| `apps/gateway/app/Services/Schedules/ScheduleDispatcher.php:76,90` | `RemoteHostExecutor` | Dispatches generic schedule jobs through the host SSH pool; schedule definitions that execute Orbit PHP must render runtime-lane commands before enqueue. |
| `apps/gateway/app/Services/Schedules/SchedulesFixer.php:56` | `RemoteHostExecutor` | Repairs scheduler host/runtime artifacts on the gateway node. |
| `apps/gateway/app/Services/Schedules/SchedulesProbe.php:43,90` | `RemoteHostExecutor` | Probes gateway container/scheduler and target host reachability. |
| `apps/gateway/app/Services/Security/HomeDirectoryLockdownInstaller.php:14` | `RemoteHostExecutor` | Mutates host home directory permissions and ownership. |
| `apps/gateway/app/Services/Security/PublicSshDenyInstaller.php:17` | `RemoteHostExecutor` | Installs UFW host rules for SSH exposure. |
| `apps/gateway/app/Services/Security/SshdHardenedInstaller.php:14` | `RemoteHostExecutor` | Writes SSH daemon host config and reloads SSH. |
| `apps/gateway/app/Services/Security/SysctlBaselineInstaller.php:14` | `RemoteHostExecutor` | Writes host sysctl baseline and applies kernel settings. |
| `apps/gateway/app/Services/Security/UnattendedUpgradesInstaller.php:15` | `RemoteHostExecutor` | Installs and configures host unattended-upgrades packages. |
| `apps/gateway/app/Services/Tools/ToolInstaller.php:80,97` | `RemoteHostExecutor` | Runs catalog install and credential scripts on the host/tool substrate. |
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
| `apps/gateway/app/Services/Workspaces/WorkspaceSetupStepRunner.php:53` | `RemoteHostExecutor` / `RemoteLocalExecutor` | Dispatches setup steps through the selected app user's host tool PATH against the workspace source path; PHP apps include the versioned host PHP toolchain and managed user tools such as `vp`. |
| `apps/gateway/app/Services/Workspaces/WorkspacesProbe.php:102` | `RemoteHostExecutor` | Probes workspace host path, user, and filesystem state; current host PHP helper must be rewritten as host-substrate shell. |
| `apps/gateway/app/Services/Workspaces/WorktreeWorkspaceDriver.php:23` | `RemoteHostExecutor` | Creates host git worktrees. |

### Bindings, implementations, and direct transport tests

These entries are not product workloads, but they are direct `SshRemoteShell`
or `RemoteShell` infrastructure hits from the inventory.

| Consumer | Lane | Classification |
|---|---|---|
| `apps/gateway/app/Providers/AppServiceProvider.php:83` | `RemoteHostExecutor` | Current container binding from `RemoteShell` to `SshRemoteShell`; runtime-lane Orbit PHP must not keep using this binding as host PHP. |
| `apps/gateway/app/Providers/AppServiceProvider.php:84` | `RemoteHostExecutor` | Current container binding from `RemoteShellStream` to `SshRemoteShellStream` for explicit transitional fallback streams and host/tool streams. |
| `apps/gateway/app/Services/RemoteShell/SshRemoteShell.php:31,82` | `RemoteHostExecutor` | SSH transport implementation for host-lane `run`/`start`; runtime executor may reuse SSH but must add gateway container execution. |
| `apps/gateway/app/Services/RemoteShell/SshRemoteShellStream.php:30` | `RemoteHostExecutor` | SSH streaming transport for host/tool log streams. |
| `apps/gateway/tests/Feature/Services/SshRemoteShellTest.php:32,64,85,105,137,168,204,224,246,263` | `RemoteHostExecutor` | Direct transport tests for `SshRemoteShell`; no product PHP workload. |
| `apps/gateway/tests/Unit/Services/RemoteShell/WithMetadataTransportTest.php:34,45,65` | `RemoteHostExecutor` | Direct transport metadata tests for `SshRemoteShell`; no product PHP workload. |
