# Architecture

This document describes Orbit's architecture at a high level.

## Components

Orbit uses a hub-and-spoke architecture. The gateway is the hub: it is the
singleton authority role, owns fleet configuration, serves a typed API,
coordinates the VPN, and applies changes on hosted nodes. Joined clients and
hosted nodes are spokes. They join the gateway-managed private network for
secure communication.

```text
              ┌─────────────────────┐
              │    Joined Client    │
              │     CLI caller      │
              └──────────┬──────────┘
                         │
                  API · over VPN
                         │
                         ▼
              ┌─────────────────────┐
              │    Gateway Node     │
              │  Config · API · CA  │
              │      VPN hub        │
              └──────────┬──────────┘
                         │
                  SSH · over VPN
                         │
                         ▼
              ┌─────────────────────┐
              │    Hosted Node      │
              │  workload roles     │
              └──────────┬──────────┘
                         │
                public 80 / 443 only
                         │
                         ▼
              ┌─────────────────────┐
              │      Internet       │
              └─────────────────────┘
```

One hub, one path: there is exactly one place to answer "what should exist?", and exactly one place changes are written. Spokes initiate commands and serve workloads, but durable configuration always lives on the gateway.

### Joined client

A joined client is where you drive Orbit from, usually your Mac or Ubuntu
workstation. It runs the Orbit CLI, presents a WireGuard identity, and
communicates with the gateway to handle operations. Joined clients do not write
fleet state directly; they call the gateway and let the gateway do the work.

### Gateway node

The gateway is the central store of everything Orbit knows: apps, nodes, workspaces, processes, schedules, tools, and firewall rules. It is the source of truth for all of them.

It runs the VPN server every Orbit node joins and acts as Orbit's certificate authority. Steady-state traffic stays on a private network, and HTTPS works without an external CA.

The gateway exposes the typed API that the CLI talks to. It holds SSH access to
hosted nodes and applies changes on them over that SSH connection. Because the
gateway owns the fleet configuration, a drifted node can be restored from it,
and a new hosted node can be provisioned from the same configuration that built
the previous one.

### Hosted node

A hosted node is a workload host with one or more active hosted role
assignments. Hosted roles are fixed code-defined bundles: `app-development`,
`app-production`, `database`, and `agent`. `app-development` uses a local TLD
for URLs (`myapp.test`, for example); `app-production` serves real domains.
Staging is a usage pattern of `app-production`, not a separate hosted role.

The `agent` role (currently in design) hosts an autonomous agent — OpenClaw or
Hermes as first-party tools — that operates Orbit through the gateway API on
the fleet's behalf. An agent node combines that workload role with operator
grants, so the agent can call the gateway like any other caller.

Hosted nodes do not own durable Orbit state and do not run a local control
plane. The Orbit CLI can run on a hosted node, but only as a joined client
that calls the gateway like any other caller.

### VPN

The VPN is the secure network every Orbit node joins. Steady-state traffic
flows over it: CLI calls to the gateway, changes the gateway pushes to hosted
nodes, and events hosted nodes send back. Development nodes and database-only
nodes do not need a public face. Hosted nodes with `app-production` expose only
ports 80 and 443 to the open internet; SSH and the Orbit API stay reachable
only over the VPN. The current VPN implementation is WireGuard; see
[tech-stack.md](tech-stack.md).

### CLI

The CLI is the product surface for humans, AI agents, and CI. It runs on joined
clients, on the gateway itself, and on hosted nodes as a gateway client. Every
command takes the same path: gather local input, call the gateway typed API
over the VPN, and render output. Commands that return structured data expose
`--json`.

## Relationships

### Trust and transport

Orbit has two network edges, and only two.

| Edge | Transport | Purpose |
|---|---|---|
| CLI caller → gateway | HTTPS over the VPN | Commands, reads, streaming progress |
| Gateway → hosted node | SSH | Running scripts, uploading config, streaming logs, controlling services |

The HTTPS choice for the caller→gateway edge is intentional. A CLI caller talks to the gateway over a typed API; it does not need shell access to any node. That limits what every caller can do to what Orbit explicitly exposes: no arbitrary shell commands, no SSH key sprawl, no hand-tuning a production host.

The blast radius of any single caller, including an AI agent driving Orbit, is bounded by the API surface. If a caller needs to be cut off — a runaway agent, a compromised laptop, a former contributor — revoking its VPN access shuts down everything it could do, immediately.

CLI callers can run on a joined client, on the gateway itself, or on a hosted
node. The caller location changes how local context (current app, current
workspace) is resolved, but it never changes who writes state — that is always
the gateway.

Hosted nodes do not accept Orbit API calls from other nodes. They run workloads,
not orchestration. When something needs to happen on a hosted node, the gateway
opens the SSH connection and runs the work there. Hosted nodes do send a small
amount of outbound traffic back to the gateway — process crash notifications
and scheduler run history — but they never accept inbound RPC.

The SSH primitive the gateway uses to act on hosted nodes is called
`RemoteShell`. How scripts are composed, files uploaded, and sudo scoped lives
in [tech-stack.md](tech-stack.md#gateway-to-hosted-node).

### Authentication and authorization

Every Orbit command needs two things: an identity and permission.

**Identity** comes from the VPN. Every node joins the VPN with its own
credentials. The gateway knows which node is on the other end of every API
call.

**Permission** is controlled by the gateway. Operation is WireGuard identity
plus gateway grants, not an operator role. For each node, the gateway stores
which other nodes are allowed to manage it. A joined client can only act on the
hosted nodes it has been granted access to. The same applies to gateway-owned
data: only nodes granted access to the gateway can read gateway policy or
activity history.

An **operator node** is any joined node acting through that identity-and-grants
path. Operator is a capability term, not a hosted role. A node can therefore be
both a hosted node and an operator node at the same time when it has a
gateway-known identity and the required grants.

This grant model lets you scope access naturally:

- A developer's joined client might have access to `app-development` nodes but
  not `app-production`.
- A CI runner's joined client might have access only to the apps it deploys.
- A hosted node's local CLI can manage its own apps and workspaces but not
  other hosted nodes in the fleet.

Permissions are revocable from the gateway. Removing a grant immediately
revokes access — no key rotation, no hosted-node config edit, no SSH key
removal needed.

### Command and API model

Orbit commands are the stable contract. Each one has documented inputs, outputs, JSON shape, and failure modes — the same surface humans, AI agents, and CI all depend on.

The CLI is what you call. The typed HTTPS API is just the transport: the CLI gathers input, calls the gateway, and renders the result. The gateway does the real work directly.

Command contracts live under [docs/domains/](domains/), one folder per family.

## State

### State model

The gateway database is Orbit's source of truth. It stores four kinds of records:

- **Registry** — what exists (nodes, apps).
- **Configuration** — how things should be set up (processes, schedules, proxy routes, tools, firewall rules).
- **Policy** — repeatable workflows (deployment step definitions).
- **History** — what happened (deployment runs, activity logs).

For standing configuration, a database row is not a cache. It describes a desired physical fact on a node — a PHP-FPM pool that should exist, a proxy route that should resolve, a process that should be running. The node-side artifact is the *applied* representation of that row.

The core invariant:

> Gateway configuration must converge with node reality.

When the two diverge, one of these happened: an apply step failed or only partially completed, someone manually changed the node, a migration changed configuration without reconciling artifacts, or a restored gateway database no longer matches the fleet.

### State families

A **state family** is one type of thing Orbit tracks — like apps, processes, or schedules. For each one, the gateway stores how it should be set up, and applies that to the right node.

Orbit has eight state families:

| Family | Owns | Concept doc |
|---|---|---|
| `node` | Which nodes exist, their role assignments, VPN identity, SSH access | [Node Concepts](domains/1_node/node-concepts.md) |
| `app` | App config, process config, deploy steps, app health | [App Concepts](domains/5_app/app-concepts.md) |
| `workspace` | Workspace config, URL, PHP pool, inherited process config | [Workspace Concepts](domains/6_workspace/workspace-concepts.md) |
| `process` | Long-running processes for apps and workspaces | [Process Concepts](domains/7_process/process-concepts.md) |
| `proxy` | Every HTTP/HTTPS route Orbit serves | [Proxy Concepts](domains/8_proxy/proxy-concepts.md) |
| `schedule` | Recurring tasks for apps, nodes, and Orbit | [Schedule Concepts](domains/9_schedule/schedule-concepts.md) |
| `tool` | Tools installed on each node | [Tool Concepts](domains/3_tool/tool-concepts.md) |
| `firewall_rule` | What network traffic each node allows | [Firewall Concepts](domains/4_firewall/firewall-concepts.md) |

These names are how Orbit thinks about each thing. The tools behind them — Caddy for proxy routes, UFW for firewall rules, Supervisor for processes — are implementation choices. The family names stay stable even when the backend changes. See [tech-stack.md](tech-stack.md) for the backends in use today.

### Keeping nodes in sync

Reality drifts. The gateway tracks configuration; a node is meant to match it; over time those can fall apart. **Drift** can be a config mismatch (a proxy route is missing on the node, a process definition has changed), a pending update (security patches the node hasn't installed), or a runtime problem (an app that should be responding isn't).

`orbit doctor` is how you catch and resolve all of those. It runs across a single family, a single node, or the whole fleet, and reports everything that isn't in the expected state.

Without any flag, doctor only reports. To act on what it finds, pass one of three mutually-exclusive flags:

| Mode | Flag | Meaning |
|---|---|---|
| Fix | `--fix` | Interactive resolution. For each drifted item, doctor asks you to restore or adopt. |
| Restore | `--restore` | Force-restore non-interactively. The gateway is right; re-apply gateway configuration on every drifted item. |
| Adopt | `--adopt` | Force-adopt non-interactively. The node is right; record observed node reality into gateway configuration for every drifted item. |

Restore is the common case: you fix a node by pushing the gateway's version of the world back onto it. Adopt is the recovery case — a manual host setup, a migration, a disaster recovery — where the node holds the right answer and the gateway needs to learn it.

Doctor is safe to run often, and safe to scope. Running it after every deploy and on a daily schedule is the simplest way to catch problems early.

## Boundaries

Orbit's extension points and identity rules keep product concepts stable while implementations can change underneath them.

### Agent IDE integration

AI agents that work on apps typically run inside an agent IDE — PolyScope, OpenCode, or similar — on a developer's machine. Orbit can integrate with those IDEs so that the agent has a smooth experience: opening a workspace by name, getting notified when a process crashes, receiving messages from the gateway when something needs the agent's attention.

The agent IDE adapter is configured per node, with optional override per app. When something happens that the active agent should know about — a crash, a deploy failure, a doctor finding — Orbit resolves the effective adapter for the app or workspace and sends the message through. If no session is active, the event is still recorded; nothing is lost.

Agent IDE adapters are extension points. New IDEs can be supported by writing an adapter without touching the rest of Orbit.

This integration is for human-driven coding sessions. Autonomous agents that operate the fleet on their own — OpenClaw, Hermes — run under the `agent` hosted role instead.

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

For backend implementations — WireGuard, Caddy, Supervisor, the SQLite schema, and the gateway-to-node `RemoteShell` primitive — see [tech-stack.md](tech-stack.md). Command contracts live under [docs/domains/](domains/).
