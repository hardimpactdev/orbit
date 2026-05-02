# Building Blocks

Orbit is a Laravel 13 CLI application that uses a gateway control plane to run
Laravel apps on Ubuntu app nodes.

This document describes the implementation pieces and technology stack that make
the [Blueprint](BLUEPRINT.md) real. It is subordinate to the blueprint, which
defines the target product and system contract. Command behavior is defined in
[commands/](commands/README.md), and concept ownership is indexed in
[Concepts](CONCEPTS.md).

## Overview

```text
┌─────────────────────────────────────────────────────────────┐
│ CLI caller                                                   │
│ control node, app node, or gateway-local CLI                 │
│ Orbit CLI, gateway config, WireGuard identity, local CA trust│
└───────────────────────────┬─────────────────────────────────┘
                            │ HTTPS over WireGuard
                            ▼
┌─────────────────────────────────────────────────────────────┐
│ Gateway                                                     │
│ Laravel app, SQLite state, typed API, DNS, VPN, CA, doctor  │
└───────────────────────────┬─────────────────────────────────┘
                            │ SSH via RemoteShell
                            ▼
┌─────────────────────────────────────────────────────────────┐
│ Ubuntu app nodes                                            │
│ Orbit CLI client, PHP-FPM, Caddy, systemd, Docker, files     │
└─────────────────────────────────────────────────────────────┘
```

App nodes do not accept Orbit control-plane API calls from other nodes. CLI
callers, including app-node CLI clients, talk to the gateway, and the gateway
enacts node reality through `RemoteShell`.

An app-node CLI invocation is a client-side convenience: it may infer local app
or workspace context, then call the gateway typed API over WireGuard HTTPS.
Non-CLI app-node to gateway traffic exists only for narrow event ingestion, such
as process lifecycle hooks. App nodes do not own a control plane or authoritative
Orbit database.

## Runtime Roles

Runtime role detection is local and setting-based. The local
`general.local_node_role` setting accepts `control`, `gateway`, and `app`; unset
or `null` resolves to `control`. Gateway and app-node bootstrap writes this
setting only after the node identity and minimum readiness are established.
Doctor verifies it against gateway intent when the expected local role is
known. See [BLUEPRINT.md#local-node-role-setting](BLUEPRINT.md#local-node-role-setting).

### Gateway

The gateway is supported on Ubuntu. It owns:

- the canonical SQLite database;
- the typed HTTPS API consumed by CLI callers;
- the Orbit root CA and certificate issuance flow;
- WireGuard network coordination and node identity;
- DNS coordination inside the Orbit network;
- Cloudflare integration when production domains are managed;
- SSH access to app nodes through `RemoteShell`;
- `doctor` probes and drift repair/adoption orchestration.

The gateway is the only node that mutates durable fleet intent.

### Control Node

A control node is supported on macOS or Ubuntu. It runs the Orbit CLI, stores
local gateway configuration, holds WireGuard identity material, and trusts the
gateway root CA locally. Normal control-node onboarding stores that local trust
through `orbit gateway:add`; first-gateway bootstrap stores it as part of
`orbit node:new --role=gateway`.

Control nodes gather input and call the gateway API. They do not orchestrate app
nodes directly.

### App Node

An app node is supported on Ubuntu. It runs the workload stack:

- Orbit CLI client for commands initiated from app or workspace paths;
- PHP-FPM;
- Caddy;
- systemd units and timers for Orbit-managed runtime artifacts;
- Docker services for databases, caches, mail, and supporting tools;
- app and workspace files;
- WireGuard and SSH;
- small Orbit-authored hook files for event ingestion;

An app node may invoke Orbit commands as a gateway client and may emit narrow
lifecycle events to the gateway, but the gateway remains the writer and enactor.

## Technology Stack

| Layer | Current implementation |
| --- | --- |
| Application | Laravel 13 CLI application |
| Runtime language | PHP 8.5 default, PHP 8.4 supported |
| Persistent state | SQLite at `~/orbit/database/database.sqlite` |
| Gateway API | HTTPS over WireGuard |
| Gateway to app node | SSH through `RemoteShell` |
| Proxy backend | Caddy |
| PHP runtime | Native PHP-FPM pools |
| Process runtime | systemd services and journald |
| Schedule runtime | systemd timers and services |
| Service runtime | Docker Compose for databases, caches, mail, and utilities |
| Network | WireGuard |
| Public DNS/CDN | Cloudflare integration for production domains |

Provider and adapter integrations are extension points. Cloudflare is the
current first-party DNS/CDN provider integration. Agent IDE adapters and
workspace source adapters may be first-party or extension-provided, but Orbit's
gateway remains the owner of the stored configuration and command behavior.

## Transport

Orbit has two primary network edges.

| Edge | Transport | Purpose |
| --- | --- | --- |
| CLI caller to gateway | HTTPS over WireGuard | Command execution, reads, streams, typed API calls |
| Gateway to app node | SSH through `RemoteShell` | Shell execution, file upload/download, log streams, service control |

The CLI caller can be a control node, the gateway-local CLI, or an app-node CLI
client. App-node CLI calls still use the CLI-to-gateway edge; they do not bypass
the gateway and do not mutate local Orbit state.

VPN administration is the gateway-local exception. Commands that administer VPN
clients (`vpn-client:*`) or the VPN web UI (`vpn-web-ui:*`) execute on the
gateway host. When initiated from a control node, Orbit reaches the gateway over
SSH and runs the gateway-local command there. This exception is for gateway
infrastructure administration only; it is not an app-node orchestration path and
it does not replace the normal CLI-to-gateway HTTPS API.

The gateway-to-app-node primitive is the `RemoteShell` contract. All
gateway-to-node enactment goes through this contract:

- `run`: execute a short script and return structured output;
- `stream`: execute a long-running command and stream chunks;
- `upload`: write a file atomically;
- `download`: read a file.

`RemoteShell` connects as the steady-state SSH user stored on the node record
(`nodes.user`). Bootstrap input such as `node:new --ssh-user=<user>` is not the
long-term enactment identity; it is used only to reach the host before Orbit
creates or verifies the managed SSH user, normally `orbit`, and records that
user in gateway node intent.

Scripts are composed on the gateway. Remote shell work is non-interactive.
Prompts happen on the CLI caller or gateway API layer before side effects begin.

`upload` writes managed files atomically: temp file, chmod, then move into place.
Writes under managed system paths (`/etc`, `/usr`, `/opt`, `/var`, `/root`,
`/boot`, `/srv`) use the app-node SSH user's passwordless sudo contract.
User-owned paths are written as the SSH user.

## Gateway API Exposure

The gateway API is an Orbit-network service. The current Caddy-backed
implementation serves it only on the gateway WireGuard address and binds the
listener to that address. It is not a public internet vhost and it is not an
app-node control-plane endpoint.

The gateway API ingress is a gateway-owned internal `proxy_route` entry. Its
proxy/TLS artifact is repaired by `doctor --family=proxy_route --fix`, not by a
backend-named provisioning command.

The gateway API listener must not trust client-supplied forwarding identity.
Current Caddy rendering strips `X-Forwarded-For`, `X-Real-IP`, and `Forwarded`
before requests reach Laravel. Caller identity comes from the Orbit network
identity model, not from forwarded headers.

Streaming and non-streaming gateway API traffic use separate PHP-FPM sockets in
the current implementation. Stream and log endpoints route to the stream socket;
ordinary command/API execution routes to the exec socket. This prevents
long-lived streams from consuming the same execution lane as short command
requests.

The gateway runtime service readiness behind those sockets belongs to the
`node` family and is verified through node/gateway readiness checks.

## Remote Command Progress

Long-running CLI-to-gateway commands stream structured command progress over
Server-Sent Events when they need live feedback. The gateway emits Orbit progress
events, not arbitrary stdout:

- `tree`: declares the title and ordered step list before work starts;
- `step`: updates one step's status and message;
- `complete`: terminates the stream successfully and may carry command data;
- `error`: terminates the stream as a command failure.

The CLI consumes these events and renders the normal Orbit progress tree locally.
If the stream closes without a `complete` or `error` event, the command is
treated as failed.

This progress stream is distinct from log streaming and from local process line
streaming. Local Symfony process streaming is an implementation helper, not an
Orbit product primitive.

## State And Storage

The gateway database stores registry state, intent, policy, and history. Standing
configuration is modeled as state families: gateway intent plus node reality
probes and doctor drift repair/adoption.

The permanent product families are named in Orbit terms:

- `node`;
- `app`;
- `workspace`;
- `process`;
- `proxy_route`;
- `schedule`;
- `tool`;
- `firewall_rule`.

Deployment policy and history belong to apps. Process definitions are app-owned
intent; derived app/workspace systemd units are physical artifacts, not gateway
state rows. Process lifecycle events are durable history, not a separate
process-unit intent table.

Agent IDE defaults are gateway intent owned by nodes and apps, not a separate
state family. Adapter implementations are extension points. Core Orbit resolves
the effective adapter for app/workspace workflows and can send messages or crash
notifications through that adapter when an active session is available.

Each state family is implemented through a probe. Families that can repair drift
also provide a fix path for `doctor --fix`; families that can adopt node reality
also provide an adopt path for `doctor --adopt`. Public doctor family keys use
the product names above. Backend-shaped implementation keys are migration
details, not stable product vocabulary.

Backend-shaped names such as Caddy sites, UFW rules, systemd units, or package
manager installs belong in renderer, enactor, probe, migration, and contraction
code. They are not product-level Orbit concepts.

Renderers compile gateway-tracked configuration into expected artifacts. They
must receive all target-specific inputs from gateway intent, resolved target
metadata, or explicit probe results; they must not read gateway-local host state
such as POSIX users, paths, or service availability when rendering artifacts for
another node.

## Host Services

PHP-FPM and Caddy run directly on Ubuntu app nodes. Docker is used for supporting
services such as databases, caches, mail, and websocket utilities.

Typical app-node artifacts include:

- app directories and workspace directories;
- PHP-FPM pool configuration and sockets;
- Caddy site configuration rendered from `proxy_route`;
- systemd service units derived from app-owned process definitions;
- systemd timer/service pairs for schedules;
- Docker Compose files for managed tools and services;
- Orbit-authored hook files that report narrow lifecycle events to the gateway.

Exact paths are backend implementation details and should live with the relevant
enactor/probe code or command contract when exposed to users.

## Installation Shape

Orbit runs from source.

Control node setup is local:

```bash
curl -fsSL https://raw.githubusercontent.com/hardimpactdev/orbit/main/bin/install-orbit | bash -s -- --role=control
```

The installer prepares the host before Orbit can run: it installs PHP, Composer,
Git, and required PHP extensions, then installs the Orbit source checkout,
creates the local SQLite database, runs migrations, and links `orbit` into the
local executable path. Human output is a quiet step tree by default; pass
`--verbose` only when the underlying package or shell command output is needed
for debugging. The installer does not create a gateway-owned control-node
identity; that identity is minted by `node:new --role=gateway` for
first-gateway bootstrap, or by `node:new --role=control` on an existing gateway
before the control machine runs `gateway:add`.

Gateway and app nodes are created through `orbit node:new [name]`.
`node:new --role=gateway --host=<host> --control-name=<control-name>` is the
first-gateway bootstrap path when no gateway is configured. It bootstraps the
gateway runtime, creates the initiating control-node identity, installs that
identity locally, stores local gateway trust and endpoint configuration, and
verifies gateway API access.

`gateway:add [gateway_ip]` is the existing-gateway join path for a control node
that already has a gateway-issued WireGuard identity. It stores the local
gateway API endpoint, gateway WireGuard IP, and trust material, installs local
gateway CA trust when missing, then makes that gateway the default endpoint for
subsequent Orbit commands.

## Platform Abstraction

Orbit keeps platform-specific host behavior behind services, handlers, and
enactors. The role model is explicit:

- gateways and app nodes are Ubuntu;
- control nodes are macOS or Ubuntu;
- macOS is not an app-hosting runtime in the current blueprint.
