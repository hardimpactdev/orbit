# Runtime Execution Lanes

This page defines how the gateway may execute work on managed nodes. Orbit's
managed execution target has two normal paths: `gateway-only` for gateway-owned
reads/writes and `agent-push` for node-local execution through typed command
envelopes. In V1, agent-push envelopes are structured `binary + argv` requests
created by the gateway and executed by the node Agent through a node-local
binary allowlist.

Gateway-owned work stays `gateway-only`; node-local work uses `agent-push`.
There is no public node-transport selector. Agent delivery fails clearly when
the resolved node is ineligible or unreachable. SSH is permanent only while
provisioning or bootstrapping a node; normal command execution has no SSH
fallback. See [Tech Stack](tech-stack.md#gateway-to-node). Break-glass SSH is
operator-owned super-admin recovery outside normal Orbit command execution.

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

Before that baseline exists, bootstrap may use host shell commands to install
Docker, prepare the `orbit` user, clone Orbit source, and create the first
runtime containers. After the baseline exists, gateway Laravel/artisan/PDO work
must not rely on host PHP, host Composer, host Python, host SQLite, or host
database client binaries. The CLI/local-executor artifact runs in the binary's
embedded PHP in production installs. Source-mounted Docker/Incus development
and E2E nodes invoke `<source>/apps/cli/orbit`. Host PHP/PHP-FPM is not the
app/workspace *web* runtime — FrankenPHP containers serve apps. App-source CLI
(`php`, `composer`, `artisan`, the Laravel installer) does run on the app
node's host PHP toolchain through an Agent-pushed allowlisted executor.

## Lanes

```text
RemoteHostExecutor:
  Provisioning/bootstrap SSH only: establish the managed user, WireGuard,
  required host substrate, and the initial Agent/runtime baseline.

RemoteGatewayRuntimeExecutor:
  Gateway-local execution inside the orbit-gateway container boundary or a
  controlled one-shot gateway image for Laravel/artisan/PDO work.

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

`RemoteHostExecutor` runs over SSH on the node host only during provisioning or
bootstrap. It establishes the steady-state lane and then leaves normal command
execution.

Allowed work:

- Verify the host and create the managed user.
- Install and configure WireGuard, Docker/container substrate, firewall,
  sysctl, SSH hardening, host keys, and required directories.
- Install the initial Orbit CLI and Agent artifacts and start the first
  role-owned runtime baseline.

Forbidden work:

- Any steady-state app, workspace, process, tool, deploy, update, recovery, or
  migration command after the Agent/runtime baseline exists.
- Gateway Laravel/artisan/PDO work or ad hoc host Python/database helpers.
- A compatibility or break-glass transport selected by a public Orbit command.

Non-provisioning callers are not admitted as `RemoteHostExecutor` work. They use
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
- App/workspace PHP execution. App and workspace web requests run in their own
  FrankenPHP containers; app-source CLI (`php`/`composer`/`artisan`) runs on
  the node's host PHP toolchain through Agent push. Neither is
  gateway-service work.
- Packaged node-local helper logic that needs host file access and PHP/PDO.
  That belongs in `RemoteLocalExecutor`.

### RemoteLocalExecutor

`RemoteLocalExecutor` invokes the node-local Orbit CLI entry point's internal
executor command through Agent push. It is for packaged node-local helper logic that needs host
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

During `update:all`, fleet CLI and Agent installation uses Agent push and the
candidate `internal:fleet-update:install-cli` command without relaxing token
verification. The JSON install payload, its SHA-256, argv, cwd, environment,
and input remain bound to the scoped operation token. A node whose Agent cannot
accept that envelope fails with the standard Agent transport error; Orbit does
not retry it over SSH.

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
| `internal:schedule:run` | active managed node roles |
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
  command envelopes. Agent envelopes carry `operation_id`, `binary`, `argv`,
  `operation_token`, `timeout_seconds`, and `stream`; the gateway builds the
  argv and owns caller authorization, while the Agent enforces the node-local
  binary allowlist and uses no-shell process execution. Completion endpoints
  return collected stdout/stderr/status frames; stream endpoints forward raw
  stdout/stderr chunks for scoped long-running commands. Gateway-only envelopes
  stay on the gateway; every other steady-state envelope uses Agent push and
  fails clearly when unavailable. `RemoteShell` remains only for provisioning
  and bootstrap.
- A host-lane command may control containers, including `docker exec`, but it
  must not execute Orbit's own framework PHP on the host.
- A runtime-lane command may read/write Orbit state through Laravel/PDO inside
  `orbit-gateway`, but it must not mutate host substrate directly.
- A local-executor command may read/write node-local helper state with PHP/PDO,
  but it must validate the gateway-issued operation token before side effects
  and must not become a public authority path.
- Running **Orbit's own framework** PHP/Composer/Artisan on the host is not
  valid on managed nodes. App-source CLI on app-role nodes runs on the host
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
