# Architecture

This document describes Orbit's architecture at a high level.

## Components

Orbit uses a hub-and-spoke architecture. The gateway is the hub: it is the singleton authority role, owns fleet configuration, serves a typed API, and applies changes on other nodes. Clients and nodes carrying workload roles are spokes. They join the gateway-managed private network for secure communication.

```text
Control plane:

Client
  -> host orbit launcher
  -> CLI/local-executor artifact
  -> HTTPS over WireGuard
  -> gateway orbit-caddy
  -> gateway orbit-runtime
  -> RemoteShell over WireGuard
  -> node execution lane

Public production HTTP:

Internet
  -> public 80/443
  -> ingress edge orbit-caddy
  -> private WireGuard route to router
  -> router private HTTP/WebSocket/S3 routing, .orbit DNS, and backend pools
  -> private app-production backend orbit-caddy
  -> FrankenPHP app container

Public WebSocket:

Internet
  -> public 443
  -> ingress edge orbit-caddy
  -> private WireGuard route to router
  -> router private WebSocket route and backend pool
  -> websocket node Reverb runtime container

Public S3:

Internet
  -> public 443
  -> ingress edge orbit-caddy
  -> private WireGuard route to router
  -> router private S3 route and backend pool
  -> s3 node RustFS runtime container
```

One hub, one path: there is exactly one place to answer "what should exist?", and exactly one place changes are written. Spokes initiate commands and serve workloads, but durable configuration always lives on the gateway.

### Client

A client is where you drive Orbit from, usually your Mac or Ubuntu workstation.
It runs the host `orbit` launcher, presents a WireGuard identity, and
communicates with the gateway to handle operations. The launcher executes the
CLI/local-executor artifact from the source checkout and passes local context
such as `ORBIT_HOST_CWD`. Clients do not write fleet state directly; they call
the gateway and let the gateway do the work.

### Gateway node

The `gateway` role is Orbit's singleton authority. It owns durable Orbit
state, the typed API, root CA material, access policy, and convergence
decisions.

The gateway is the central store of everything Orbit knows: apps, nodes, workspaces, processes, schedules, tools, and firewall rules. It is the source of truth for all of them.

The gateway exposes the typed API that the CLI talks to. It holds SSH access to other nodes and applies changes on them over that SSH connection. Because the gateway owns the fleet configuration, a drifted node can be restored from it, and a new node can be provisioned from the same configuration that built the previous one.

The gateway API runs in the gateway's `orbit-runtime` container and is exposed
inside the Orbit network by the gateway's `orbit-caddy` container. Moving the
API into Docker does not make the launcher or a local runtime container a
state writer: durable writes still happen only on the gateway.

### Node roles

A node carries one or more **roles** assigned by the gateway. Roles are fixed code-defined bundles: `gateway`, `vpn`, `router`, `app-development`, `app-production`, `database`, `agent`, `ingress`, `websocket`, and `s3`. The `gateway` role is the singleton authority role described above.

The `vpn` role is a gateway-coupled infrastructure role in this version. It
owns the WireGuard server runtime, public WireGuard endpoint settings, VPN
peer defaults, and the VPN-facing DNS runtime. First gateway bootstrap assigns
`gateway`, `vpn`, and `router` to the same node, and normal role commands
cannot manage those roles independently.

The other seven are workload roles applied to nodes in the fleet.

`app-development` uses a local TLD for URLs (`myapp.test`, for example); `app-production` serves real domains. Staging is a usage pattern of `app-production`, not a separate role.

Application roles use the Docker-first runtime baseline. PHP apps and PHP
workspaces run in dedicated FrankenPHP containers. Orbit-defined PHP processes
run as Docker process runtime units by default. Host PHP and PHP-FPM are not
app or workspace runtime fallbacks. Gateway Laravel/artisan/PDO work on
Docker-first-managed nodes must enter `orbit-runtime` through the runtime
execution lane; packaged node-local helpers that need host file access and
PHP/PDO use the token-gated local executor lane. See
[Runtime Execution Lanes](execution-lanes.md).

The `websocket` role is a private workload role for Orbit-managed realtime
infrastructure. A websocket node runs Laravel Reverb in a Docker runtime
container managed by Orbit, binds only to its WireGuard address, and receives traffic
through router-owned private service routes. Public WebSocket traffic enters
through `ingress`, then flows to `router`, then to the websocket backend pool.
Apps use the stable `websocket.orbit` endpoint and never target a concrete
websocket node. The role depends on a Redis service selected from a
`database` role node and does not install or own Redis itself.

The `s3` role is a private workload role for Orbit-managed S3-compatible object
storage. An S3 node runs one RustFS instance in a Docker runtime container
rendered by Orbit, binds its S3 API only to the node's WireGuard address, and
receives traffic through router-owned private service routes. Public S3 traffic
enters through `ingress`, then flows to `router`, then to the S3 backend pool.
In v1 the backend pool contains one RustFS node. Apps and VPN clients use the
stable `s3.orbit` endpoint and never target a concrete S3 node.

The `agent` role runs first-party autonomous agent tools — OpenClaw and Hermes — that operate Orbit through the gateway API on the fleet's behalf. The `agent` role is exclusive: it cannot combine with `gateway`, `vpn`, `router`, `app-development`, `app-production`, `database`, `ingress`, `websocket`, or `s3`, and it can only be selected during `node:new`. `node role:add` rejects `agent` because adding it to an existing node bypasses the isolation model the role enforces. A node carrying the `agent` role combines that workload role with explicit scoped grants so the agent can call the gateway like any other caller. Agent tool web UIs are exposed only as internal HTTPS routes under the agent role TLD (for example `https://openclaw.agent` and `https://hermes.agent`); they have no ingress baseline. Activity emitted while autonomous agent tools work is attributed to the node identity — Orbit does not claim per-tool sub-identities.

Roles compose only where the role matrix allows it. In v1, `gateway`, `vpn`,
and `router` are coupled and combine only with each other. `app-development`
may combine with `database`, `websocket`, and `s3`. `app-production` may
combine with `ingress`, but conflicts with `database`, `websocket`, and `s3`.
`websocket` and `s3` may combine with each other on dev services nodes, and
both conflict with public edge, production app, agent, and gateway-coupled
infrastructure roles. The `agent` role remains exclusive. The full
compatibility matrix lives in [Node Concepts](domains/1_node/node-concepts.md#role-compatibility).

Each role has a **driver** — the code that knows how to install, configure, and verify that role on a node. A role can only be assigned to a node whose host operating system is supported by that role's driver. New OS support for an existing role is a driver change, not an architecture change. Current driver OS support is enumerated in [Node Concepts: Role Platform Support](domains/1_node/node-concepts.md#role-platform-support).

Nodes other than the gateway do not own durable Orbit state and do not run a local control plane. The Orbit CLI can run on any node, but only as a client that calls the gateway like any other caller.

### VPN

The VPN is the secure network every Orbit node joins. Steady-state traffic
flows over it: CLI calls to the gateway, changes the gateway pushes to other
nodes, and events those nodes send back. The `vpn` role owns the WireGuard
server runtime, the public endpoint settings peers use to reach it, peer
defaults, and the VPN-facing DNS runtime. In v1 that role is gateway-coupled,
so the active `vpn` role runs on the same node as the active `gateway` role.
Nodes with only `app-development`, `database`, `websocket`, `s3`, or private
`app-production` roles do not need a public face. Only nodes with an active
`ingress` role expose public production HTTP/HTTPS. SSH and the Orbit API stay
reachable only over the VPN. The current VPN implementation is WireGuard; see
[tech-stack.md](tech-stack.md).

### DNS responsibilities

Orbit splits DNS responsibility across distinct owner concerns. These do not
overlap.

| Concern | Owner | Verified by |
|---|---|---|
| Gateway-owned development/agent DNS mappings (which TLD points at which WireGuard IP) | node family | `doctor --family=node` |
| Router-owned private `.orbit` service names and private route selection | gateway-coupled `router` role | `doctor --family=proxy` for HTTP routes; router service checks for TCP service contracts are future work |
| VPN-facing DNS runtime (the dnsmasq + wg-easy substrate that serves those mappings) | `vpn` role baseline | `doctor --family=tool` for the `dns` tool row; `doctor --family=node --restore` re-applies the baseline wholesale |
| Caller-local resolver overrides on an operator's own machine | `dns:*` command family | — |
| Public DNS / CDN for production domains | Cloudflare integration | `cf-*` command family |

The `dns:*` command family does not edit gateway-owned development DNS or
router-owned private `.orbit` service names; the tool family does not own DNS
records.

### CLI

The CLI is the product surface for humans, AI agents, and CI. The host
`orbit` executable is a launcher for the role-appropriate Orbit artifact. On
clients and workload nodes it runs the CLI/local-executor artifact from the
source checkout; on the gateway, the gateway API and scheduler still run in
`orbit-runtime`. The CLI runs on clients, on the gateway itself, and on any
node carrying workload roles as a gateway client. Public commands gather local
input, call the gateway typed API over the VPN, and render output. Commands
that return structured data expose `--json`.

## Relationships

### Trust and transport

Orbit has two network edges, and only two.

| Edge | Transport | Purpose |
|---|---|---|
| CLI caller → gateway | HTTPS over the VPN | Commands, reads, streaming progress |
| Gateway → node | SSH | Running scripts, uploading config, streaming logs, controlling services |

The HTTPS choice for the caller→gateway edge is intentional. A CLI caller talks to the gateway over a typed API; it does not need shell access to any node. That limits what every caller can do to what Orbit explicitly exposes: no arbitrary shell commands, no SSH key sprawl, no hand-tuning a production host.

The blast radius of any single caller, including an AI agent driving Orbit, is bounded by the API surface. If a caller needs to be cut off — a runaway agent, a compromised laptop, a former contributor — revoking its VPN access shuts down everything it could do, immediately.

CLI callers can run on any node — a client, the gateway, or a node carrying workload roles. The caller location changes how local context (current app, current workspace) is resolved. The launcher passes `ORBIT_HOST_CWD` so the dispatched artifact can preserve current-directory ergonomics without broad host access. Caller location never changes who writes state — that is always the gateway.

Nodes other than the gateway do not accept Orbit API calls from other nodes. They run workloads, not orchestration. When something needs to happen on such a node, the gateway opens the SSH connection and runs the work there. They do send a small amount of outbound traffic back to the gateway — process crash notifications and scheduler run history — but they never accept inbound RPC.

The SSH primitive the gateway uses to act on other nodes is called
`RemoteShell`. `RemoteShell` is transport; workload lane selection is defined
by [Runtime Execution Lanes](execution-lanes.md). How scripts
are composed, files uploaded, and sudo scoped lives in
[tech-stack.md](tech-stack.md#gateway-to-node).

`RemoteLocalExecutor` is the gateway-dispatched lane for packaged node-local
helper logic that needs host file access plus PHP/PDO without relying on ad hoc
`python3` or `sqlite3` snippets. The gateway still owns authority. The
authority path is:

`CLI caller -> gateway API -> gateway authorization -> operation record -> RemoteShell to node -> token-gated local executor -> result recorded`

Node-local CLI execution is never an authority bypass. Internal local executor
commands are hidden from normal CLI help, require a gateway-issued operation
token, and must fail before side effects when invoked directly without a valid
token.

Gateway operation tokens are minted by the gateway-side operation token
factory, using `ORBIT_OPERATION_TOKEN_SECRET` and the configured
`ORBIT_OPERATION_TOKEN_TTL_SECONDS` value. The default TTL is 120 seconds. Each
token carries the operation id, target node, internal command name, issued
timestamp, expiry timestamp, and signature. The local executor verifies the
signature, target node, command, and expiry before side effects. Missing signing
secret configuration prevents minting; token minting is stateless and uses the
existing operation id rather than creating operation persistence itself.

### Authentication and authorization

Every Orbit command needs two things: an identity and permission.

**Identity** comes from the VPN. Every node joins the VPN with its own credentials. The gateway knows which node is on the other end of every API call.

**Permission** is controlled by the gateway. Operation is WireGuard identity plus gateway grants, not a built-in role. For each node, the gateway stores which other nodes are allowed to manage it. A client can only act on the nodes it has been granted access to. The same applies to gateway-owned data: only nodes granted access to the gateway can read gateway policy or activity history.

Authorization is two gates: a grant edge between a consuming node and a serving node decides whether the consuming node can reach the serving node at all, and the scoped permission set stored on that grant decides what the consuming node may do once it does. A grant with no permissions denies every action. Permissions are normalized permission strings such as `node:read`, `tool:restart`, `firewall_rule:read`, or `doctor:verify`; wildcards `node:*` and `*` are dynamic and include future permissions added in that namespace. Self-grants are explicit and required — a node does not implicitly have access to itself. A grant from a node to the gateway with `*` (the `gateway-admin` preset) is the fleet-wide super-admin grant: it covers every current node and every node added in the future. `node:new` itself requires a gateway-admin grant on the calling node, or an explicit `node:new` permission on its grant to the gateway.

Any node with a gateway-known identity and the required grants can act through that identity-and-grants path. There is no separate "operator" or "control" role — capability comes from the grants attached to the node, not from a built-in label.

#### Gateway implicit authority

A node carrying the `gateway` role has implicit authority for every permission
against every other node. This is the one named exception to the grants-only
model: the gateway is the singleton policy owner and must be able to converge
the fleet even when no explicit self- or cross-node grant exists for a managed
node. It is implemented by `NodeAccessAuthorizer::allows()` when the caller has
an active `gateway` role assignment; it is not a runtime feature flag and does
not create stored grant rows.

This grant model lets you scope access naturally:

- A developer's client might have a `developer` preset to nodes with the `app-development` role and no grant at all to nodes with the `app-production` role.
- A CI runner's client might have an `operator` preset only to the apps it deploys.
- A node's self-grant gives its own local CLI the actions it needs on itself — for example, a node with the `agent` role has a self-grant that includes `tool:restart` and `tool:update:agent-tools` but excludes `tool:credentials`, `tool:install`, firewall writes, and node role mutation.

Permissions are revocable from the gateway. Removing a grant immediately revokes access — no key rotation, no node-side config edit, no SSH key removal needed. `node:grant` creates the initial grant edge and its initial permissions; long-term editing of a grant's permission set is owned by `node:permissions`, which is itself a gateway-admin-only surface.

#### Self-grants and self-serving

A self-grant is a grant where the consuming node and the serving node are the same node. It is the only way a node has any access to itself; access is never implicit. Self-grants are created during `node:new` — each role's baseline self-grant is materialized from the role's self preset.

Self-targeting commands flow through the gateway like any other command. When a CLI on node `N` calls a command targeting `N`, the path is:

`N → gateway (HTTPS over WireGuard) → gateway authorizes the self-grant → gateway SSHs back to N via RemoteShell and applies`

Node-side state is never written by the public local CLI. The gateway is the
only authority, even when the gateway dispatches token-gated local executor
work back to the same node.

This is why commands like `workspace:setup` work when run from inside a workspace path on an `app-development` or `app-production` node: the node's self-grant includes the necessary workspace permissions. It is not an exception — it is the self-grant model.

The one shape that cannot self-serve is a bare client (no role assignments). The gateway authorizes the call but has nowhere to dispatch node-side work, because the gateway does not open SSH connections to client-only machines.

### Command and API model

Orbit commands are the stable contract. Each one has documented inputs, outputs, JSON shape, and failure modes — the same surface humans, AI agents, and CI all depend on.

The CLI is what you call through the host launcher. The typed HTTPS API is the transport from CLI runtime to gateway: the CLI gathers input, calls the gateway, and renders the result. The gateway does the real work directly.

Command contracts live under [docs/domains/](domains/), one folder per family.

## State

### State model

The gateway database is Orbit's source of truth. It stores four kinds of records:

- **Registry** — what exists (nodes, apps).
- **Configuration** — how things should be set up (processes, schedules, proxy routes, tools, firewall rules).
- **Policy** — repeatable workflows (deployment step definitions).
- **History** — what happened (deployment runs, activity logs).

For standing configuration, a database row is not a cache. It describes a desired physical fact on a node — a FrankenPHP app container that should exist, a proxy route that should resolve, a Docker process runtime unit that should be running. The node-side artifact is the *applied* representation of that row.

The core invariant:

> Gateway configuration must converge with node reality.

When the two diverge, one of these happened: an apply step failed or only partially completed, someone manually changed the node, a migration changed configuration without reconciling artifacts, or a restored gateway database no longer matches the fleet.

### State families

A **state family** is one type of thing Orbit tracks — like apps, processes, or schedules. For each one, the gateway stores how it should be set up, and applies that to the right node.

Orbit has nine state families:

| Family | Owns | Concept doc |
|---|---|---|
| `node` | Which nodes exist, their role assignments, VPN identity, SSH access | [Node Concepts](domains/1_node/node-concepts.md) |
| `app` | App config, process config, deploy steps, app health | [App Concepts](domains/5_app/app-concepts.md) |
| `workspace` | Workspace config, URL, runtime container, inherited process config | [Workspace Concepts](domains/6_workspace/workspace-concepts.md) |
| `process` | Long-running processes for apps and workspaces | [Process Concepts](domains/7_process/process-concepts.md) |
| `proxy` | Every HTTP/HTTPS route Orbit serves | [Proxy Concepts](domains/8_proxy/proxy-concepts.md) |
| `schedule` | Recurring tasks for apps, nodes, and Orbit | [Schedule Concepts](domains/9_schedule/schedule-concepts.md) |
| `tool` | Tools installed on each node | [Tool Concepts](domains/3_tool/tool-concepts.md) |
| `firewall_rule` | What network traffic each node allows | [Firewall Concepts](domains/4_firewall/firewall-concepts.md) |
| `database_connection` | Reusable database connection intent mapped into app and workspace `.env` files | [Database Concepts](domains/18_database/database-concepts.md) |

Security is not a tenth state family. Security findings are sections inside the
family that owns the protected state: host security under `node.security.*`,
production runtime hardening under `app.security.*`, development workspace
isolation under `workspace.security.*`, and firewall-owned representation drift
under `firewall_rule.security.*` when needed. `doctor --family=security` is not
accepted.

These names are how Orbit thinks about each thing. The tools behind them — `orbit-caddy` for proxy routes, UFW for firewall rules, Docker for PHP process runtime units, and Supervisor only for explicitly configured residual process runtime — are implementation choices. The family names stay stable even when the backend changes. See [tech-stack.md](tech-stack.md) for the backends in use today.

### Keeping nodes in sync

Reality drifts. The gateway tracks configuration; a node is meant to match it; over time those can fall apart. **Drift** can be a config mismatch (a proxy route is missing on the node, a process definition has changed), a pending update (security patches the node hasn't installed), or a runtime problem (an app that should be responding isn't).

`orbit doctor` is how you catch and resolve all of those. It runs across a single family, a single node, or the whole fleet, and reports everything that isn't in the expected state.

Doctor has four modes. Without any flag it only reports. The other three modes are selected by mutually-exclusive flags:

| Mode | Flag | Meaning |
|---|---|---|
| Verify | *(none)* | Default. Compare gateway configuration and node reality; report only. |
| Interactive | `--fix` | Prompt per drifted item: restore, adopt, skip, or view details. |
| Restore | `--restore` | Force-restore non-interactively. The gateway is right; re-apply gateway configuration on every drifted item. |
| Adopt | `--adopt` | Force-adopt non-interactively. The node is right; record observed node reality into gateway configuration for every drifted item. |

The mode names are also used by the doctor permission registry: `doctor:verify`, `doctor:restore`, and `doctor:adopt` are the permission strings that gate access to each mode.

Restore is the common case: you fix a node by pushing the gateway's version of the world back onto it. Adopt is the recovery case — a manual host setup, a migration, a disaster recovery — where the node holds the right answer and the gateway needs to learn it.

Doctor is safe to run often, and safe to scope. Running it after every deploy and on a daily schedule is the simplest way to catch problems early.

## Boundaries

Orbit's extension points and identity rules keep product concepts stable while implementations can change underneath them.

### Agent IDE integration

AI agents that work on apps typically run inside an agent IDE — PolyScope, OpenCode, or similar — on a developer's machine. Orbit can integrate with those IDEs so that the agent has a smooth experience: opening a workspace by name, getting notified when a process crashes, receiving messages from the gateway when something needs the agent's attention.

The agent IDE adapter is configured per node, with optional override per app. When something happens that the active agent should know about — a crash, a deploy failure, a doctor finding — Orbit resolves the effective adapter for the app or workspace and sends the message through. If no session is active, the event is still recorded; nothing is lost.

Agent IDE adapters are extension points. New IDEs can be supported by writing an adapter without touching the rest of Orbit.

This integration is for human-driven coding sessions. Autonomous agents that operate the fleet on their own — OpenClaw, Hermes — run as ordinary managed tools under the `agent` role instead. There is no default agent tool: a node with the `agent` role may be created with zero, one, or several agent tools selected. Running multiple agent tools on the same node is allowed, but Orbit warns about weaker node-level traceability whenever a second running agent tool is started or installed — interactive callers see a confirmation prompt, machine-readable callers receive a structured warning under `success.meta.warnings[]` and the command proceeds when input is otherwise valid.

### Identity names

Apps, workspaces, processes, and nodes are identified by **slugs** — short, lowercase, URL-safe names that drive paths, hostnames, file names, and database keys. A future presentation label may add spaces or capitalization, but the slug stays canonical.

A slug must match:

```text
^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$
```

Length limits:

- app slug: up to 40 characters
- node slug: up to 63 characters
- workspace slug: up to 63 characters (independent of the parent app slug)
- process slug: up to 64 characters

**Workspace hostnames** prepend the workspace slug to the parent app's hostname. For a development app, that's `{workspace}.{app}.{tld}`.

**Process names** combine the app, workspace, and process slugs into a single identifier:

```text
orbit_<app>_<workspace|main>_<process>
```

Examples:

```text
orbit_docs_main_vite
orbit_docs_feature-docs_vite
```

`orbit_` marks the name as Orbit-owned. `_` separates segments and is not allowed inside a slug.

### Next

For backend implementations — WireGuard, `orbit-caddy`, Docker runtime containers, the SQLite schema, and the gateway-to-node `RemoteShell` primitive — see [tech-stack.md](tech-stack.md). Command contracts live under [docs/domains/](domains/).
