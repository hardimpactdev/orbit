# Orbit Blueprint

This document defines Orbit's ideal target state after the current gateway,
workspace, process, certificate, and proxy-routing plans land. It is the
architecture and product contract for future work.

When this blueprint conflicts with older dated design notes, this blueprint is
the target. Dated notes explain how Orbit got here; this file defines where
Orbit is going.

## Purpose

Orbit is a sovereign Laravel environment for running apps on supported Ubuntu
nodes from an Orbit CLI inside the Orbit network.

The gateway is the control plane. App nodes run workloads. CLI callers on
control nodes, app nodes, or the gateway initiate commands. All durable Orbit
state lives on the gateway and is enacted onto app nodes through a narrow
execution primitive.

Orbit's product promise is a stable command surface for humans, LLM agents, CI,
and future UI layers. The CLI remains the product contract. The typed HTTP API is
the transport between CLI and gateway, plus the integration substrate for future
interfaces.

## Product Principles

1. **Gateway authority.** The gateway owns all durable Orbit state.
2. **No app-node authority.** App nodes may run the Orbit CLI as a stateless
   client, but they do not own fleet state, run a local Orbit control plane, or
   maintain an authoritative Orbit database.
3. **One orchestration path.** CLI callers talk to the gateway. The gateway
   talks to app nodes. Callers never orchestrate app nodes directly.
4. **State is intentional.** Standing configuration starts as gateway intent and
   is enacted onto nodes. Node reality must be probeable.
5. **Backend names are not product names.** Caddy, UFW, systemd, and package
   managers are implementation backends. Orbit concepts are apps, workspaces,
   processes, schedules, tools, proxy routes, and firewall rules.
6. **No redundant materialized state.** A table or model must carry independent
   intent, history, or policy. It must not exist only as a cached projection of
   other rows.
7. **Commands are behavior contracts.** Command behavior is designed and
   documented before implementation. Code conforms to the command contract, not
   the other way around.
8. **Platforms are role-specific.** Gateways and app nodes are Ubuntu. Control
   nodes are macOS or Ubuntu.

## Identity Names

App, workspace, process, and node identity names are slugs. They are not
presentation labels. Future labels may contain spaces, capitalization, or
punctuation, but labels must not drive paths, hostnames, systemd unit names, or
durable keys.

Identity slugs must match:

```text
^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$
```

Additional target limits:

- app slug: maximum 40 characters;
- node slug: maximum 63 characters;
- workspace slug: maximum 63 characters, independent of the parent app slug;
- process slug: maximum 64 characters.

Workspace hostnames prepend the workspace slug as its own DNS label to the
parent app's primary hostname. For a development app this yields
`{workspace}.{app}.{tld}`. Backend artifacts that combine app and workspace
slugs, such as PHP-FPM pools or systemd unit names, must validate the generated
artifact name before writing it.

Process runtime unit filenames use the app, workspace, and process slugs:

```text
orbit_<app>_<workspace|main>_<process>.service
```

Examples:

```text
orbit_docs_main_vite.service
orbit_docs_feature-docs_vite.service
```

`orbit_` is the Orbit ownership prefix. `_` is reserved as the backend segment
delimiter and is not allowed in identity slugs. Renderers must validate the final
unit filename against the backend/system filename limit before writing it.

## Node Roles

Orbit uses a hub-and-spoke node topology. The gateway is the hub and the only
writer of fleet intent. Control nodes and app nodes are spokes: they call the
gateway, but they do not coordinate Orbit work with each other directly.

Role words describe behavior and eligibility. Machine phrases describe concrete
node records or hosts. For example, `gateway` is the role value, while "gateway
node" means the machine or node record whose role is `gateway`.

### Local Node Role Setting

Every Orbit installation resolves its local caller role from the
`general.local_node_role` setting. Allowed values are `control`, `gateway`, and
`app`. When the setting is unset or `null`, the local caller role is `control`.
Gateway and app nodes must set the value explicitly; otherwise local commands
run the control-caller path.

Gateway-enacted bootstrap writes `general.local_node_role=gateway` on gateway
hosts and `general.local_node_role=app` on app hosts only after the node's
identity and minimum readiness are established. Control nodes may leave the
setting unset or store `control` explicitly. The nodes doctor probe verifies the
setting when the expected local role is known from gateway intent.

### Gateway

The gateway is supported on Ubuntu only. It runs Orbit as a Laravel application
and owns:

- the canonical SQLite database;
- the typed HTTPS API consumed by CLI callers;
- the Orbit root CA and leaf certificate issuance;
- WireGuard identity, DNS, and fleet access policy;
- SSH access to app nodes;
- queues, schedules, and long-running gateway jobs when needed.

The gateway is the only Orbit node allowed to mutate fleet intent. It renders
desired state from the database and enacts it through `RemoteShell`.

Every CLI-to-gateway command is authenticated by WireGuard node identity and
authorized through gateway-owned node access policy. Node grants are not tied to
one transport path: they determine which gateway-owned resources and serving-node
capabilities a consuming node may operate on. A non-gateway caller needs access
to the serving node that owns the requested resource; gateway-owned policy and
history operations require access to the gateway node.

### Control Node

A control node is supported on macOS or Ubuntu. It runs the Orbit CLI and holds
enough local identity to reach a gateway. It may store local preferences such as
configured gateway endpoint, trusted CA material, and a default development app
node for commands that otherwise require repeated `--node` input.

A control node is an Orbit node identity, not just a generic VPN client.
Normally, its gateway-owned registry row and WireGuard peer are minted on the
gateway through `orbit node:new <name> --role=control`. The operator then
installs that returned WireGuard configuration on the control machine, joins the
Orbit network, and runs `orbit gateway:add [gateway_ip]` locally to store
gateway trust and endpoint settings.

First-gateway bootstrap is the exception. When an unconfigured control node runs
`orbit node:new <gateway-name> --role=gateway --host=<host> --control-name=<control-name>`,
the new gateway mints the initiating control node identity, returns and installs
its WireGuard configuration locally, stores gateway trust and endpoint settings,
and verifies gateway API access. That initiating control node does not run
`gateway:add` afterward.

`gateway:add` must never create the gateway node row, a control node row, or
WireGuard peer material; it only verifies and configures an already-issued
control identity.

A control node:

- gathers prompts and local user input;
- calls the gateway typed API over WireGuard HTTPS;
- renders command output for humans or JSON for automation;
- never writes fleet state directly;
- never connects to app nodes for Orbit orchestration.

The local default development app node is a local control-node preference only.
It does not create gateway intent, does not grant access, and does not bypass
authorization. It is used as a target-resolution fallback for commands that
operate on a development app node when no explicit `--node` or app/workspace
owner already determines the target.

Nodes may define a default agent IDE adapter. The node default is gateway intent
used by apps and workspaces on that node when they do not define their own
agent IDE setting. It does not grant access, create an IDE session, or make the
node a control plane.

### App Node

An app node is supported on Ubuntu only. It is a workload host that runs host
services and tenant app:

- the Orbit CLI as a gateway client when developers or agents run commands from
  inside an app or workspace;
- PHP-FPM;
- Caddy or a future proxy backend;
- systemd units for Orbit-managed processes and schedules;
- Docker services when enabled;
- WireGuard and SSH;
- small Orbit-authored hook files such as process-event notifiers.

An app node does not run an Orbit control plane, does not own an authoritative
Orbit database, and does not contain independent Orbit business logic. When the
Orbit CLI is invoked on an app node, it resolves local app or workspace context
when available, calls the gateway typed API over WireGuard HTTPS, and lets the
gateway update intent and enact the resulting node changes through
`RemoteShell`.

App-node CLI availability is not general intent-write permission. The current
app-node gateway-intent write exception is `workspace:setup`: developers and
agents may invoke it from inside the workspace they are preparing, but the
command still calls the gateway, the gateway owns any workspace intent changes,
and the gateway enacts node artifacts through `RemoteShell`. Other app-node
intent writes are invalid unless their command contract documents a similarly
narrow local workflow exception.

Runtime-control commands are a separate category. For example, app-node callers
may run documented process lifecycle commands such as `process:start`,
`process:stop`, and `process:restart` when authorized for the resolved app or
workspace context. Those commands still call the gateway typed API and do not
grant the app-node CLI direct systemd access or durable process-intent write
permission.

App nodes carry an `environment` constraint of `development` or `production`.
Development app nodes may own a development TLD default used by future app and
workspace route creation on that node. Production app nodes do not use a
development TLD and rely on production domain workflows instead.

## Trust And Transport

Orbit has two primary network edges.

| Edge | Transport | Purpose |
| --- | --- | --- |
| CLI caller to gateway | HTTPS over WireGuard | Command execution, reads, streaming progress, typed API calls |
| Gateway to app node | SSH via `RemoteShell` | Shell execution, file upload/download, log streams, service control |

CLI callers include control nodes, the gateway-local CLI, and app-node CLI
clients. The caller location changes input resolution, such as inferring the
current app or workspace path, but it does not change state ownership: the
gateway remains the only writer of durable Orbit intent.

Gateway VPN administration is a gateway-local exception. `vpn-client:*` commands
administer VPN clients, and `vpn-web-ui:*` commands administer the VPN web UI.
Both command groups must run on the gateway host. When a control node initiates
one of these commands, Orbit uses SSH to the gateway to run the gateway-local
command. This does not create a general control-node-to-gateway SSH command path,
and it never applies to app node orchestration.

The gateway-to-app-node primitive is `RemoteShell`. All gateway-to-node
enactment goes through this contract:

- `run`: execute a short script and return structured output;
- `stream`: execute a long-running command and stream chunks;
- `upload`: write a file atomically;
- `download`: read a file.

`RemoteShell` uses the steady-state SSH user recorded in gateway node intent
(`nodes.user`). `node:new --ssh-user=<user>` is only a bootstrap credential for
the initial host reachability and provisioning steps. Successful gateway and app
node provisioning creates or verifies the Orbit-managed SSH user, normally
`orbit`, records that user on the node row, and uses it for later gateway-to-node
work.

All scripts are composed on the gateway. App-node shell execution is
non-interactive. Prompts happen on the CLI caller or at the gateway API layer,
not inside a remote shell on the app node.

`RemoteShell::upload` writes managed files atomically by uploading to a temporary
path, applying the requested mode, and moving the file into place. Writes under
managed system paths (`/etc`, `/usr`, `/opt`, `/var`, `/root`, `/boot`, `/srv`)
use the app-node SSH user's passwordless sudo contract. User-owned paths are
written as the SSH user.

App nodes do not accept Orbit control-plane API calls from other nodes. CLI
callers, including app-node CLI clients, talk to the gateway; the gateway enacts
node reality through
`RemoteShell`.

Non-CLI app-node to gateway traffic is exceptional. It is allowed only for
narrow, purpose-built event ingestion, such as process lifecycle notifications
emitted by a systemd hook. There is no generic app-node RPC daemon, no catch-all
webhook receiver, and no app-node Orbit control plane.

## State Model

The gateway database stores:

- registry state, such as nodes and apps;
- intent, such as processes, schedules, proxy routes, tools, and firewall rules;
- policy, such as deployment step definitions;
- history, such as deployment runs and activity logs.

The database is not merely a cache near the fleet. For standing configuration,
the database row describes the desired physical fact on a node. The node-side
artifact is the enacted representation of that row.

The core invariant is:

> Gateway intent must converge with node reality.

Any divergence means one of these happened:

- enactment failed or only partially completed;
- someone manually changed the node;
- a migration changed intent without reconciling artifacts;
- a restored gateway database no longer matches the fleet.

Read commands over gateway-owned state must be fast, registry-shaped database
reads unless their contract explicitly says they perform a live inspection. List
and show commands for state families report gateway intent plus durable gateway
history, such as recorded lifecycle events or run history. They must not block on
SSH, systemd, Caddy, filesystem probes, or other node-runtime inspection as part
of the default read path. Live reality belongs to `doctor`, to explicitly named
live flags such as `--live`, or to dedicated stream/log commands whose contract
declares runtime access.

## State Families

A state family is an Orbit domain whose intent can be compared with node reality.
Each family must define:

- the source of intent;
- the physical artifacts or facts it owns;
- how intent is enacted;
- how node reality is introspected;
- how `doctor` verifies drift;
- whether `doctor --fix` can safely re-apply gateway-tracked configuration on
  the node;
- whether `doctor --adopt` can safely adopt observed node reality into
  gateway-tracked configuration.

Ideal Orbit has these state families.

| Family | Owns | Current backend examples |
| --- | --- | --- |
| `node` | Fleet membership, roles, WireGuard identity, SSH reachability, platform capabilities, gateway runtime readiness | WireGuard, SSH, OS probes, gateway PHP-FPM sockets |
| `app` | App registry, runtime config, production deployment pipeline, app health | PHP-FPM, app directories, app-owned proxy routes |
| `workspace` | Worktree/workspace intent, workspace URL, FPM pool, inherited process runtime units | Git worktrees, PHP-FPM, workspace-owned proxy routes |
| `process` | App-owned process definitions rendered into app/workspace runtime units | systemd, journald |
| `proxy_route` | Canonical registry of Orbit-owned HTTP ingress | Caddy |
| `schedule` | Recurring work for apps, nodes, and Orbit-managed tasks | systemd timers/services |
| `tool` | Expected and managed node tools | package managers, binaries, systemd, Docker |
| `firewall_rule` | Expected node network policy | UFW |

The blueprint names only current ideal concepts. Legacy or backend-shaped names
belong in migration plans and contraction audits, not in the permanent product
model.

Provider and adapter integrations may be implemented in core or in installed
Orbit extensions. This includes DNS/CDN providers and agent IDE adapters,
including agent IDE adapter workspace-discovery capabilities. The permanent docs
define the Orbit behavior and command contracts; packaging can change later
without changing the core domain model.

The implementation extension shape is:

- a probe that introspects node reality and diffs it against gateway-tracked
  configuration;
- a fix path when `doctor --fix` can re-apply gateway-tracked configuration on
  the node safely;
- an adopt path when `doctor --adopt` can adopt observed node reality safely and
  idempotently.

`doctor --adopt` is the general adoption mechanism for drift, disaster
recovery, and observed reality outside an explicit command flow. The membership
command `node:new` is the node-family exception: it may adopt a compatible
already-provisioned gateway or app host as part of explicitly adding that node
to gateway intent.

Doctor family keys exposed to users must use the product family names above.
Backend-shaped keys such as `caddy_sites`, `ufw_rules`, `tool_installs`,
`app_schedulers`, or `process_exit_notifications` are implementation drift until
renamed, folded, or removed.

## Proxy Routing

A proxy route is Orbit-owned HTTP ingress intent for a hostname at the fleet
boundary. `proxy_route` is the canonical registry of every hostname Orbit
exposes, regardless of whether the route exists because of an app, a workspace,
the gateway, or a standalone user-authored rule.

A proxy route describes what should happen before a request reaches an app:

- terminate TLS;
- serve an app or workspace;
- proxy to an upstream;
- redirect to another URL;
- expose an Orbit-internal endpoint.

Caddy is the current proxy backend. It is not the domain model. A future backend
must implement the same render, enact, introspect, and doctor drift contract.

### Route Ownership

Every proxy route has an owner that controls its lifecycle:

| Owner | Meaning | Lifecycle |
| --- | --- | --- |
| `app` | App primary development or production hostname | Created, updated, and removed by app commands |
| `workspace` | Workspace hostname | Created, updated, and removed by workspace commands |
| `gateway` | Orbit gateway/API hostname | Created and updated by gateway/node lifecycle |
| `standalone` | User-authored route without an app/workspace owner | Created, updated, and removed by proxy-route commands |

Owned routes are visible in the proxy route registry but are not edited directly
through standalone proxy-route commands. For example, a workspace route is listed
with all other routes, but its lifecycle belongs to the workspace.

The gateway-owned internal route includes the Orbit gateway API ingress. Its
proxy/TLS/backend artifact belongs to `proxy_route`, while the runtime service
readiness behind that route belongs to the `node` family. Backend-specific
gateway vhost provisioning commands are not part of the product command surface.

### Route Kinds

Route kind describes behavior for ingress:

- `app`: serve an app hostname;
- `workspace`: serve a workspace hostname;
- `internal`: expose an Orbit-internal endpoint;
- `proxy`: proxy a hostname to an upstream;
- `redirect`: redirect a hostname to another URL.

Future kinds may be added when they carry independent ingress behavior, such as a
static route or alias route.

## Runtime Model

### Apps

An app belongs to one app node. Development apps and production apps share the
same gateway-controlled model, but production apps have stricter runtime policy:

- dedicated app user when required;
- dedicated PHP-FPM pool;
- production domain activation;
- deployment pipeline definition;
- deployment history;
- production health checks.

Deployment steps are arbitrary. Orbit does not assume every deployment is a
zero-downtime release flow. Retention is optional deploy-step metadata for steps
that create or prune versioned releases; there is no global app deployment
retention setting and no standalone deployment-retention state family.
Deployment health is part of production app health. Orbit does not model
deployments or releases as standalone state families.

An app PHP version is gateway-tracked intent. Changing it updates the app row,
then the gateway re-renders and applies PHP-FPM and proxy artifacts on the
owning app node through `RemoteShell`.

App registration is idempotent. `app:new` creates or clones the app source/path,
writes the initial gateway intent, and then runs the same registration behavior
exposed by `app:register`. `app:register` registers an existing app path or
re-applies Orbit management for an existing app: app runtime artifacts,
app-owned proxy routes, process artifacts, schedule/deployment defaults when
configured, and production domain activation when `--domain=<host>` is supplied.

`app:new --repo=<value>` accepts full Git repository URLs for any Git host the
owning app node can clone with its existing credentials. The shorter
`owner/repo` form is a current GitHub-only convenience shorthand and expands to
`git@github.com:owner/repo.git`. Orbit does not currently expose a generic Git
hosting provider abstraction in the command contract.

Apps may also define an agent IDE adapter default. The app default overrides
the owning node's default for app and workspace workflows. Workspaces inherit the
app default, then the owning node default, unless a future workspace-level
override is introduced.

Agent IDE adapters may report which app workspaces still exist outside Orbit.
`app:prune` uses the app's configured and inherited agent IDE adapters as the
workspace source of truth and removes stale Orbit workspaces for the app.
Switching an app's agent IDE checks the old adapter for app-owned workspaces and
cleans up stale Orbit workspace state through normal prune/remove semantics.
This cleanup is deliberately app-scoped. Changing a node-level default agent IDE
does not cascade into app or workspace cleanup; affected apps that inherit the
new node default can be pruned explicitly with `app:prune`.
Pruning is not doctor drift repair: it is source-of-truth cleanup. A pruned
workspace follows `workspace:remove` semantics for Orbit-owned workspace
artifacts and removes the workspace row.

Database cleanup during pruning is allowed only for databases explicitly tracked
by Orbit as workspace-owned. Until such ownership is tracked, pruning must not
infer database ownership from names, environment files, conventions, or setup
step side effects; it must leave possible databases untouched and report them
as skipped/manual cleanup.

### Workspaces

A workspace belongs to an app. The workspace name is the canonical workspace and
branch identity. A workspace owns:

- its path;
- its derived hostname;
- its PHP-FPM pool;
- its workspace-owned proxy route;
- inherited process runtime units from the parent app's process definitions.

Workspaces do not have a separate mirror table. Their node-side artifacts are
derived from the `workspace` row and verified by the `workspace` family.

Workspace PHP version is gateway-tracked intent. A workspace inherits the parent
app PHP version unless a workspace override is stored on the workspace row.
Orbit must not create, require, read, or trust `.php-version` files in app or
workspace project trees. During workspace adoption, `composer.json` is the only
project file Orbit may inspect for PHP-version hints, and only when the
workspace is a PHP project.

Node CLI PHP version is node-level gateway-tracked configuration. It controls
the default `php` binary used when a user, agent, shell script, or lifecycle step
does not explicitly select a version. It is separate from app and workspace
PHP-FPM version intent.

Workspace setup and teardown steps have their own lifecycle environment. That
environment is for one-off setup and teardown commands and is distinct from
process runtime-unit environment.

Workspace doctor probes render expected workspace artifacts at check time from
gateway-tracked app and workspace configuration. Orbit does not need stored
expected-hash columns for workspace artifacts.

`doctor --family=workspace --fix` re-applies gateway-tracked workspace
configuration on the node. If gateway tracks a workspace and enough source
information exists, missing workspace reality should be recreated.

`doctor --family=workspace --adopt` adopts observed node reality into
gateway-tracked workspace configuration when the workspace family supports
adoption.

### Processes

A process is an app-owned runtime definition: name, command, ordering, restart
policy, crash-notification policy, and related execution settings.

Process units are physical systemd artifacts derived from `(app, optional
workspace, process)`. The concept of a process unit remains, but it is not a
database entity. Its expected content is rendered from primary state when Orbit
enacts or probes.

For each process definition, Orbit expects one derived runtime unit for the main
app instance and one derived runtime unit for each workspace of that app.
`doctor --family=process --fix` re-renders missing or divergent runtime units
from gateway-tracked app, workspace, and process configuration.

The rendered systemd service name follows the global runtime-unit naming
contract: `orbit_<app>_<workspace|main>_<process>.service`.

Process runtime units have their own systemd environment contract. Runtime-unit
environment is distinct from workspace setup and teardown step environment.

Process lifecycle events are durable history, not runtime-unit state. Orbit may
store `started`, `stopped`, and `crashed` events so browser toolbars, CLI
streams, and automation can observe changes without materializing process units
as gateway intent. Default process read commands derive runtime status from
gateway intent and the latest relevant process events already recorded on the
gateway. They do not synchronously SSH to app nodes to inspect systemd units.
Live runtime probes are reserved for `doctor --family=process`, explicit
runtime lifecycle commands, and dedicated internal event streams.

Crash events may be emitted by narrow Orbit-managed app-node hooks that call a
gateway event intake endpoint. The hook and intake endpoint are implementation
plumbing for the process family, not a public command surface or a generic
app-node API.

Crash notification to an active agent IDE session is process policy. If enabled
for a process, a `crashed` event triggers an event action that resolves the app
or workspace's effective agent IDE setting and sends the crash message to the
active session when one is available. If no compatible agent IDE session can be
resolved, the crash event is still recorded and delivery is reported as skipped.

### Agent IDE Integrations

Agent IDE integrations are Orbit adapters for developer- and agent-facing
workflows. Core Orbit owns the durable default selection and message command;
adapter implementations may live in core or in installed Orbit extensions.

The effective agent IDE for an app or workspace is resolved in this order:

1. explicit workspace setting, when a future workspace-level override exists;
2. explicit app setting;
3. owning node default;
4. no configured agent IDE.

`inherit` is only a valid input at scopes that have a parent setting. For an
app, `inherit` clears the app override so resolution continues to the owning
node default. For a future workspace-level override, `inherit` would clear the
workspace override so resolution continues to the app. Nodes are the root of
the current inheritance chain, so `node:agent-ide` does not accept `inherit`;
`none` clears the node default and lets the chain resolve to no configured
agent IDE. At app scope, `none` is different: it stores an explicit no-adapter
override for that app and its workspaces.

Agent IDE settings are gateway intent. They choose the adapter and, when needed,
adapter-specific session resolution metadata. Agent IDE adapters may also expose
workspace discovery for an app so Orbit can prune workspaces that were archived
or deleted in that adapter. They do not own Orbit workspace state and do not
replace process, workspace, or app commands.

`agent-ide:message` sends a message to the effective active agent IDE session
for an app or workspace. It is a communication command, not a workspace mutation.

Workspace-specific process overrides are not part of the ideal model. A process
definition belongs to the app and is inherited by workspaces.

### Schedules

Schedules cover all recurring Orbit-managed work. A Laravel scheduler is a
normal app-scoped schedule that runs `php artisan schedule:run` every minute,
not its own entity.

Examples:

- run `php artisan schedule:run` for an app every minute;
- run a named app command on a systemd timer;
- run Orbit-owned recurring node maintenance.

The `schedule` family owns the timer and service artifacts and verifies them
through doctor.

### Tools

Tools are node capabilities Orbit expects or manages. A tool may be:

- installed and observed;
- installed and lifecycle-managed;
- authenticated or configured;
- required by a node role.

The product concept is `tool`. Package managers, binaries, Docker services, and
systemd units are backend details.

### Firewall Rules

Firewall rules are Orbit-owned network policy for a node. The product concept is
not UFW. UFW is the current backend.

Firewall intent should describe policy in Orbit terms: direction, action,
source, destination, protocol, port, and reason. The backend renderer/enactor
turns that policy into host-specific firewall commands.

## Command And API Model

Commands are the stable product contract. Command contracts define behavior,
inputs, outputs, JSON shape, failure semantics, and whether a command is intended
for humans, agents, or internal machinery.

The typed API is the transport between CLI and gateway. The CLI should gather
interactive input locally, call typed gateway endpoints, and render output. The
gateway should execute domain services/actions directly, not call its own API
through HTTP.

Command documentation lives outside this blueprint, grouped by numbered domain
directories under `docs/commands/`. Each domain directory has a `README.md` for
domain rules and one numbered command entry per command contract. Simple
commands may use a numbered markdown file directly. Commands with non-trivial
caller-role behavior, input modes, output renderers, doctor relationships, test
mapping, or unresolved ambiguity may use a numbered command directory with a
public command page and companion technical files.

The blueprint defines the world commands operate in. The command contracts define
the command surface.

## Drift And Doctor

`orbit doctor` is Orbit's convergence interface. It is how the operator checks
whether gateway-tracked configuration still matches the physical fleet.

Doctor applies across the whole ecosystem. Every state family must expose enough
introspection for doctor to answer these questions:

- is the expected artifact or fact present?
- does it match the configuration tracked on the gateway?
- can Orbit safely re-apply gateway-tracked configuration on the node when it
  drifted?
- can Orbit adopt observed node reality when the family deliberately supports
  that?

Running doctor should be a normal fleet maintenance habit. It should be safe to
run often against a node, a family, or the whole fleet to catch lingering drift:
missing proxy routes, stale firewall policy, unrendered schedules, missing
runtime units, broken workspace artifacts, mismatched registered tool state, or
production app health problems.

Every state family supports one or more of these doctor modes:

| Mode | Direction | Meaning |
| --- | --- | --- |
| Verify | compare only | Report where gateway-tracked configuration and node reality differ |
| Fix | gateway to node | Re-apply gateway-tracked configuration on the node |
| Adopt | node to gateway | Adopt observed node reality into gateway-tracked configuration, when the family supports it |

`--fix` must re-apply gateway-tracked configuration on the node. It must not
silently mutate gateway-tracked configuration. `--adopt` may mutate
gateway-tracked configuration from observed node reality and must be explicit.

Doctor is not a grab bag of health checks. Health checks are useful, but the
architectural role of doctor is convergence: tell whether Orbit's intended world
matches the physical fleet.

## Future Considerations

This section records plausible future directions that should not drive current
implementation. When this blueprint is revised, future considerations should be
reviewed as part of the same change.

### Control Nodes As Local Development Runtimes

Control nodes may eventually gain local app-runtime capability. In that model, a
Mac or Ubuntu control node could act as a full local development environment
while still participating in the gateway-controlled fleet.

This is not part of the current blueprint. The current target is stricter:

- control nodes are CLI callers;
- app nodes are Ubuntu workload hosts;
- the gateway owns fleet state;
- all control-to-app orchestration flows through the gateway.

Current abstractions should avoid needlessly blocking a future local-runtime role,
but no command, state family, or service should implement local app-node behavior
speculatively.

### Multiple Orbit Networks Per Client

A control node may eventually participate in multiple Orbit WireGuard networks.
The current blueprint assumes one active Orbit network, so `gateway:add` can
derive the gateway client IP from that network.

Multi-network support will need an explicit network selection model before
gateway selection, granting, or command routing can target a specific gateway without
ambiguity. Until that model exists, commands should not add speculative
multi-network flags or state.

## Feature Evolution Rules

Future work follows this order:

1. Update this blueprint if the feature changes Orbit's architecture or domain
   model.
2. Update the relevant command contract if the feature changes behavior.
3. Add or update an ADR if the feature introduces a new architectural decision,
   transport, backend, trust boundary, or state-family exception.
4. Implement code to match the blueprint and command contracts.
5. Add tests or audits that prevent the old shape from returning.

New standing node configuration must become a state family or attach to an
existing one. It must not be a write-only side effect.

New product concepts should be named after Orbit's domain, not after the current
implementation backend. Backend names belong in renderer, enactor, probe, and
adapter code.

## Explicit Non-Goals

These are not part of the ideal model:

- an Orbit daemon or app-node control plane;
- direct control-node orchestration of app nodes;
- a generic app-node daemon or RPC channel outside the CLI client and explicit
  event hooks;
- gateway self-calls through its own HTTP API;
- backend-shaped product families;
- redundant projection tables that do not carry independent intent, history, or
  policy;
- a web UI as the primary product contract;
- multi-gateway or high-availability gateway behavior;
- broker-based fan-out for logs and streams;
- gateway or app-node support outside Ubuntu;
- control-node support outside macOS and Ubuntu.
