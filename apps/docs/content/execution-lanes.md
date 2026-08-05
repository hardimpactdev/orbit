# Runtime Execution Lanes

This page defines Orbit's managed execution paths and the separate pre-Agent
bootstrap edge. The gateway has two normal paths: `gateway-only` for
gateway-owned reads and writes, and `agent-push` for node-local execution
through typed command envelopes. In V1, agent-push envelopes are structured
`binary + argv` requests created by the gateway and executed by the node Agent
through a node-local binary allowlist.

Gateway-owned work stays `gateway-only`; node-local work uses `agent-push`.
There is no public node-transport selector. Agent delivery fails clearly when
the resolved node is ineligible or unreachable. SSH exists only as an
initiating-client-to-target bootstrap edge before Agent readiness; the gateway
never receives target credentials or opens target SSH. See
[Tech Stack](tech-stack.md#gateway-to-node). Break-glass SSH is operator-owned
super-admin recovery outside normal Orbit command execution.

The generated [SSH inventory](generated/transitional-ssh-inventory.json) lists
the provisioning/bootstrap consumers and proves that the transitional consumer
set is empty. Docs quality checks rebuild the inventory model from production
PHP sources and reject unmarked or stale entries.

## Scope

An Orbit-managed node has the substrate needed by the artifacts its roles own:
`orbit-gateway` and `orbit-scheduler` Swarm services on gateway nodes,
`orbit-caddy` for proxying when needed, Docker-backed app/workspace web
containers and role/tool services where those artifacts are declared, and
systemd units for configured Linux host command processes. Source-dev Docker
and Incus topologies are development and E2E lanes. Artifact-prod installs use
the native CLI binary artifact and production images.

Before Agent readiness, the initiating client may use SSH to observe the target
and install only the managed user, WireGuard, CLI, and Agent substrate. Docker,
Caddy, filesystem layout, security policy, sysctl, unattended upgrades,
public-SSH denial, and role runtime containers converge afterward through Agent
push. Gateway Laravel/artisan/PDO work must not rely on host PHP, host Composer,
host Python, host SQLite, or host database client binaries. The
CLI/local-executor artifact runs in the binary's embedded PHP in production
installs. Source-mounted Docker/Incus development and E2E nodes invoke
`<source>/apps/cli/orbit`. Host PHP/PHP-FPM is not the app/workspace *web*
runtime — FrankenPHP containers serve applications. Project-source CLI (`php`, `composer`,
`artisan`, the Laravel installer) runs on the app node's host PHP toolchain
through an Agent-pushed allowlisted executor.

## Lanes

```text
Client bootstrap SSH:
  Initiating-client-to-target only: observe the platform and establish the
  managed user, WireGuard, CLI, and initial Agent substrate.

RemoteGatewayRuntimeExecutor:
  Gateway-local execution inside the orbit-gateway container boundary or a
  controlled one-shot gateway image for Laravel/artisan/PDO work.

RemoteLocalExecutor:
  Select gateway-local execution for gateway-role targets; otherwise
  Agent-push to the node Agent. Both paths invoke the Orbit CLI entry point's
  internal executor command.
  It is for packaged node-local helper logic that needs host file access
  and PHP/PDO without relying on ad hoc python3/sqlite3 snippets.
  Production installs still use the native CLI binary artifact; source-dev
  Docker/Incus topologies point /usr/local/bin/orbit directly at
  <source>/apps/cli/orbit.
```

### Client bootstrap SSH

For workload bootstrap, the initiating CLI opens SSH to the target only when
the gateway resume lookup shows that Agent substrate is still required.
First-gateway bootstrap uses its dedicated client-owned path before a gateway
API exists. In both cases, the gateway never receives SSH credentials or opens
the connection. After the Agent becomes ready, this edge leaves normal command
execution.

Allowed work:

- Observe and validate the target platform and architecture.
- Create the managed user and install WireGuard.
- Install the initial Orbit CLI and Agent artifacts and establish Agent
  readiness on the reserved WireGuard address.

Forbidden work:

- Docker or Caddy installation, filesystem layout, security policy, sysctl,
  unattended upgrades, public-SSH denial, or role runtime convergence; these
  use Agent push after readiness.
- Any steady-state app, workspace, process, tool, deploy, update, recovery, or
  migration command after the Agent substrate exists.
- Gateway Laravel/artisan/PDO work or ad hoc host Python/database helpers.
- A compatibility or break-glass transport selected by a public Orbit command.

The gateway is never admitted to this edge. Post-bootstrap work uses
`RemoteLocalExecutor`/Agent push or gateway-local execution.

### RemoteGatewayRuntimeExecutor

`RemoteGatewayRuntimeExecutor` enters the local `orbit-gateway` container
boundary, or runs the equivalent one-shot gateway image when replacing the
gateway service itself. It never SSHs to or Agent-pushes the gateway. It is
the lane for gateway Laravel/artisan/PDO work that belongs to the gateway
runtime container on managed gateway nodes.

Required work:

- Gateway `php artisan ...` commands executed inside the gateway runtime.
- Gateway Laravel boot, Eloquent/PDO access, and database query helpers for
  gateway-owned runtime state.
- Composer operations for source-dev Orbit checkouts when explicitly in that
  lane.
- VPN command forwarding when the forwarded command is an Orbit Artisan command
  that belongs to the gateway container.

Forbidden work:

- Host bootstrap, Docker installation, WireGuard host mutation, Caddy host
  artifact writes, UFW/sysctl/SSH hardening, and file ownership repair.
- Instance/workspace PHP execution. Instance and workspace web requests run in their own
  FrankenPHP containers; app-source CLI (`php`/`composer`/`artisan`) runs on
  the node's host PHP toolchain through Agent push. Neither is
  gateway-service work.
- Packaged node-local helper logic that needs host file access and PHP/PDO.
  That belongs in `RemoteLocalExecutor`.

### RemoteLocalExecutor

`RemoteLocalExecutor` invokes the Orbit CLI entry point's internal executor
command. Gateway-role targets use the gateway-local container lane; other
node-local targets use Agent push. It is for packaged helper logic that needs host
file access and PHP/PDO without relying on ad hoc
`python3` or `sqlite3` snippets. In source-mounted nodes, `/usr/local/bin/orbit`
points directly at `<source>/apps/cli/orbit`, and mutable node-local Orbit
state lives under `~/.config/orbit`.

The gateway primitive composes `/usr/local/bin/orbit internal:* ...` commands
with `LocalExecutorCommandBuilder` and mints a short-lived gateway operation
token. Non-gateway targets receive an allowlisted `binary + argv` Agent
envelope. Gateway-role targets stay in the gateway-local lane: the parent
gateway verifies the exact signed `argv`, `cwd`, environment, and input,
consumes the single-use token, and passes a trusted execution context into the
gateway container. The CLI validates that context instead of reconstructing
container-local state such as `/srv/orbit/apps/gateway` or omitting bound
stdin.
`RemoteLocalExecutor` cannot invoke public commands; internal executor commands
verify operation tokens through the gateway API before any side effects, and
nodes do not store executor token signing material.

Operation tokens are signed with the gateway's dedicated operation-token key,
carry a key id for rotation, bind the exact per-attempt operation-run id, target
node, command, and
canonical hash of the dispatched `argv`, `cwd`, environment, and input, and are
checked for not-before/expiry before use. The bound environment is the shared
allowlisted set defined by core `OperationTokenEnvironment`
(`APP_KEY`, `HOME`, `ORBIT_BIN_PATH`, `ORBIT_CONFIG_PATH`,
`ORBIT_INSTALL_METADATA_PATH`, `ORBIT_WG_EASY_DB_PATH`). Gateway minting and CLI
`OperationTokenGuard` verification both filter through that helper so the
minted hash matches the verification reconstruction. `force_remote_host` further
collapses the bound environment to `HOME` only and unsets the other allowlisted
keys on the host process. Verification consumes the corresponding operation-run
row; a second verification for the same attempt id returns a distinct
already-dispatched denial instead of authorizing another node-local execution.
The row's `operation_id` remains the logical grouping key for retries or
concurrent fan-out that share caller metadata; consuming one attempt never
consumes sibling rows in that logical group.

Gateway API requests normally authenticate by WireGuard peer identity. The
`/api/internal-executor/token/verify` endpoint has one scoped exception for
gateway self-execution: when service-network NAT hides the gateway host
Agent's WireGuard or loopback peer identity, a valid, argument-bound token
targeting an active gateway-role node may establish that gateway node identity
for this verify endpoint only. Identity resolution never consumes the token;
the controller consumes it only after authorization. Non-gateway targets and
all other gateway API routes continue to require WireGuard peer identity.

During `update:all`, fleet CLI and Agent installation uses Agent push and the
candidate `internal:fleet-update:install-cli` command without relaxing token
verification. The JSON install payload, its SHA-256, argv, cwd, environment,
and input remain bound to the scoped operation token. A node whose Agent cannot
accept that envelope fails with the standard Agent transport error; Orbit does
not retry it over SSH. On Linux, the install converges an existing managed
`orbit-agent` systemd unit to the candidate binary and service configuration
before scheduling the deferred Agent restart, so a stale unit cannot keep an
older transport implementation alive after a successful artifact install.
Every later Agent-push envelope pins `ORBIT_BIN_PATH` to the owner-user
launcher selected by the fleet updater. This prevents a stale protected
`/usr/local/bin/orbit` compatibility link from shadowing the newly installed
immutable CLI artifact.
Gateway-host CLI replacement additionally verifies both host symlinks after
install and forces a gateway service task replacement. This is required when a
new immutable CLI checksum shares the current semantic version or gateway image
identity, because an already-running container keeps the bind-mounted binary
resolved when its task was created.

Gateway-only execution resolves the gateway container through
`ORBIT_GATEWAY_CONTAINER`. The production Swarm service sets that value from
the task-name template (`{{.Task.Name}}`), while standalone and prepared
topologies may supply their own explicit container name.

#### Result-boundary redaction patterns

Activity rows, operation_runs rows, internal-command JSON results, and
exception messages must never contain raw secret material. Orbit uses two
intentional semantics — strict rejection at typed result/progress boundaries,
and best-effort scrubbing at activity/operation persistence. Defense in depth
does not license callers to emit secrets; Loggable properties and typed
results must still be secret-free by contract.

**Strict typed result and progress boundaries** (`ResultBoundaryRedactionPolicy`
via internal-command pre-serialization, `OperationResultHandler`, and framed
progress recognition) reject the payload before persistence when:

- any key name contains a forbidden fragment: `operation_token`,
  `executor_secret`, `password`, `bearer`, `secret`, `_token`, `api_key`
- any leaf string embeds a PEM block
  (`-----BEGIN [A-Z ]+-----` through `-----END [A-Z ]+-----`)
- an unknown key appears for a declared typed operation contract (fail closed)

**Persistence safety net** (`SecretSummaryRedactor` on `ActivityLogger` for
final merged properties and description, and on `OperationRunRecorder` for
result/error/summaries) redacts rather than rejects. It covers key-shaped
APP_KEY / application key, password / password-hash, secret, token, API /
access / refresh / operation tokens, executor secret, private / pre-shared
keys, bearer, and compound or hyphen variants across nested properties,
summaries, and descriptions. It also scrubs complete PEM blocks and real
authorization syntax (`Authorization: Bearer …`, `Proxy-Authorization: …`,
standalone `Bearer <credential>`). `RemoteLocalExecutor` still redacts minted
operation-token literals and explicit `redact_command_options` values in
exception text and audit lines, because those are not always recoverable from
key shape alone.

Required work:

- SQLite database query helpers for app, workspace, or database-role files
  resolved by the gateway but executed on the owning node's host path.
- Wg-easy SQLite state updates and ownership checks that must preserve
  node-local file access and ownership semantics.
- Gateway-owned schedule execution payloads dispatched through
  `internal:schedule:run` after the gateway has resolved and authorized the
  schedule target.
- Prepared-topology fixture helpers that must run inside a topology node and
  need PHP/PDO without adding host `sqlite3`.

Forbidden work:

- Public command execution or direct user-invoked state mutation, except
  gateway-owned schedule payload execution through `internal:schedule:run`.
- Gateway Laravel/artisan/PDO work that belongs in `orbit-gateway`.
- Host substrate mutation such as Docker installation, WireGuard host mutation,
  Caddy artifact writes, package installation, or SSH hardening.
- Instance/workspace runtime PHP execution, which belongs in instance/workspace
  containers.

Every `RemoteLocalExecutor` invocation must carry a gateway-issued operation
token. The local executor validates the token before side effects, and
node-local CLI execution is never an authority bypass. The token id corresponds
to the fresh `operation_runs.id` created for that dispatch attempt.
`ORBIT_OPERATION_ID`, when supplied, is retained as the row's logical
`operation_id`; otherwise Orbit generates that logical grouping value. The
command process spawned after Agent-side verification carries an Agent-push
authorization marker. Gateway-local execution carries the generalized trusted
execution context. Both let the internal command confirm the operation id,
command, and token without spending the single-use verify token twice.

Every completion-based `RemoteLocalExecutor::runInternal()` dispatch writes two
gateway-owned internal activity records on the canonical `api` channel with
`properties.lane = internal` and `properties.transport` set to the intended
audit lane (gateway role + normalized `force_remote_host`, before selector
execution). Selector failure still records a dispatching/completed pair on that
intended lane.

| Intended lane | `properties.transport` | Event types |
| --- | --- | --- |
| Agent push | `agent_push` | `agent_push.dispatching`, `agent_push.completed` |
| Gateway container-local | `gateway_local` | `gateway_local.dispatching`, `gateway_local.completed` |
| Gateway host boundary (`force_remote_host`) | `force_remote_host` | `force_remote_host.dispatching`, `force_remote_host.completed` |

- `{transport}.dispatching` after command validation and token minting, and
  before transport selection/execution. It records the operation id,
  target node id and name, internal command name, scalar arguments/options, and
  the `LocalExecutorCommandBuilder::buildAuditLine()` command shape.
- `{transport}.completed` after the transport returns or throws (including
  selector unavailability). It records the same operation id, target node,
  command name, success/failure status, exit code when available, duration, and
  stdout/stderr summaries capped at 4 KiB with a `[truncated]` suffix.

Shell audit rows from substrate executors may interleave between that pair.
They are separate executor substrate records (same channel,
`properties.lane = internal`) and are not the RemoteLocalExecutor
dispatching/completed pair:

- gateway container-local execution: `gateway_local.dispatching` →
  `gateway_local.run` / `gateway_local.start` (shell audit from
  `RemoteOrbitGatewayExecutor`) → `gateway_local.completed`
- force host boundary: `force_remote_host.dispatching` → `ssh_bootstrap.run` /
  `ssh_bootstrap.start` (shell audit from `RemoteHostExecutor`) →
  `force_remote_host.completed`

The shared `gateway_local` prefix on shell `run`/`start` rows is intentional and
unambiguous for operators: exclusion is `properties.lane = internal`, not event
name alone. No consumer filters those intermediate event names as the lane pair.

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
| `internal:schedule:run` | active managed node roles |

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

The `RemoteLocalExecutor::run()` compatibility adapter is reserved for
command-name-only internal invocations such as `internal:executor:verify`;
callers must not encode structured local-executor input as JSON or as a
free-form shell script.

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
  command envelopes. Agent envelopes carry `operation_id`, `binary`, `argv`,
  `operation_token`, `timeout_seconds`, and `stream`; the gateway builds the
  argv and owns caller authorization, while the Agent enforces the node-local
  binary allowlist and uses no-shell process execution. Completion endpoints
  return collected stdout/stderr/status frames; stream endpoints forward raw
  stdout/stderr chunks for scoped long-running commands. Gateway-only envelopes
  stay on the gateway; every other steady-state envelope uses Agent push and
  fails clearly when unavailable. Client bootstrap SSH is outside the gateway
  execution lanes and ends at Agent readiness.
- A host-lane command may control containers, including `docker exec`, but it
  must not execute Orbit's own framework PHP on the host.
- A runtime-lane command may read/write Orbit state through Laravel/PDO inside
  `orbit-gateway`, but it must not mutate host substrate directly.
- A local-executor command may read/write node-local helper state with PHP/PDO,
  but it must validate the gateway-issued operation token before side effects
  and must not become a public authority path.
- Running **Orbit's own framework** PHP/Composer/Artisan on the host is not
  valid on managed nodes. Project-source CLI on instance-role nodes runs on the host
  PHP toolchain through an Agent-pushed executor.

## Orbit Caddy Isolation

`orbit-caddy` stays a separate fleet proxy container based on `caddy:2-alpine`.
It must not be folded into `orbit-gateway`. Caddy route files, include
boundaries, certificates, reloads, and container repair are node-local Agent
work after bootstrap.

The `orbit-caddy` isolation boundary is independent from the app/workspace
FrankenPHP runtime containers.

## Deferred

The FrankenPHP base-image switch is deferred and out of scope for this
contract. This page does not change app/workspace FrankenPHP image selection,
worker mode, or app runtime rendering.

## SSH Consumer Classification

The generated [SSH inventory](generated/transitional-ssh-inventory.json) is the
current consumer list. Its source scan covers production dependencies and the
concrete SSH executor implementations.

Each inventory entry points to an exact source marker:

- `@orbit-ssh-lane provisioning-ssh` is allowed only for node provisioning or
  bootstrap.
- The `transitional_ssh` inventory list must remain empty.

The generator rejects consumers without a marker. The committed artifact is
checked for byte-for-byte freshness by `composer docs-lint`, so this section
does not duplicate a hand-maintained table.
