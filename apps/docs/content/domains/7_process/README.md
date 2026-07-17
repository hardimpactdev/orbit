# Process Commands

Process commands manage runtime units that Orbit owns and keeps running. A process
definition is stored on the gateway, may be scoped to a node, concrete app
instance, or workspace, and owns its lifecycle through a selected runtime backend.

The gateway is the source of truth for process configuration. When node-side work is required, the gateway renders and applies derived runtime units on the resolved node or the selected app instance's serving node.

## Domain Rules

These rules govern process configuration ownership, naming, and runtime unit derivation.

### Ownership and identity

These rules cover who owns process configuration and how process definitions are named.

- The gateway owns process configuration.
- Process names are identity slugs: lowercase letters, digits, and hyphens only.
  They cannot start or end with a hyphen and are limited to 64 characters.
- `process:update --name=<new-slug>` is the public rename path when the selected
  runtime/backend can safely replace derived unit identity.
- Process definitions may be scoped to a node, concrete app instance, or
  workspace. An app-scoped definition belongs to the `AppInstance`, never only
  to the logical app. A workspace-scoped definition belongs to a workspace
  that already identifies its app instance. The scope selects the serving node
  and default runtime context.
- Canonical app-instance identity stores and returns both the logical `app` slug
  and the concrete `app_instance` slug. Public commands prefer
  `--app=<app.instance>`. A bare logical-app slug is shorthand only when that
  app has exactly one instance; otherwise commands fail with
  `error.code=validation_failed`, `error.meta.field=app`, and
  `error.meta.reason=app_instance_required`.
- Process definitions have a stable order inside their owning scope.
  `process:add` appends new definitions after existing ones in that scope.
- Read and bulk lifecycle commands use that order.
- A process may reference a catalogued tool with a tool dependency. The tool
  supplies a node-level capability; the process still owns start, stop,
  restart, and logs.
- A process may also be materialized from a managed service selector via
  `process:add --service`. Managed services are node-owned runnable services
  such as MySQL or Valkey; they do not reference tools and do not infer service
  identity from the process name.

### Runtime unit derivation

These rules describe how runtime units are derived from process definitions.

- Runtime units are the process-family product noun: concrete runnable units
  derived from node/app-instance/workspace scope and process configuration. They are not
  gateway state rows.
- Node-level and workspace-scoped process definitions normally render one
  runtime unit. App-instance-scoped inherited process definitions may render
  one runtime unit for that instance's main context and one runtime unit for
  each active workspace belonging to that same instance. These units all run
  on the instance's serving node.
- Each rendered runtime unit is applied by its selected process runtime backend:
  `systemd` for Linux host commands, `launchd` for macOS host commands,
  `docker` for containerized processes, or `docker-swarm` for selected
  node-owned managed service processes.
- Public app-instance/workspace host-command process definitions use the host
  command runtime for their serving node: `systemd` on Linux and `launchd` on macOS.
  App/workspace `docker` rows remain reserved for Orbit-managed runtime
  processes such as generated FrankenPHP web-runtime units, not arbitrary
  public host commands.
- The process definition supplies shared fields such as command, restart policy,
  runtime backend, runtime configuration, and crash notification policy. The
  rendering context supplies per-instance fields such as node/app/app-instance/workspace
  identity, path, URL, environment, ports, and volumes.
- Runtime unit names use Orbit-owned backend-safe names such as
  `orbit_<scope>_<process>`. App/workspace identities include both logical app
  and app-instance slugs so two instances of one app cannot collide. When process identity is renamed, Orbit replaces
  derived runtime units and removes names from the previous identity instead of leaving
  orphaned units.
- The `orbit_` prefix marks Orbit ownership, and underscores are reserved as
  backend segment delimiters.
- Systemd unit names, launchd labels and plist names, Docker container names,
  and Swarm service names derive from the same product identity. Launchd labels
  are `dev.hardimpact.orbit.<runtimeUnit>`.

### Restart policy

Restart policy is process configuration. Each derived main-instance or workspace runtime unit uses the process definition's `never`, `on_failure`, or `always` policy. Manual `process:restart` actions do not change that policy.

### Crash notification policy

Process definitions may opt in to crash notification. When the policy is enabled, a `crashed` event resolves the effective agent IDE and notifies the active session when one is available. Crash notification delivery is best-effort and must not prevent the event from being recorded.

Launchd-backed units reject `crash_notification=agent_ide` in this slice with
the validation reason `launchd_crash_notification_deferred`. Launchd can
restart jobs, but Orbit needs an owned wrapper or equivalent hook before it can
emit stable gateway-authenticated `crashed` events for macOS host-command
units.

### Crash event intake

These rules describe the narrow internal path that delivers crash events from nodes to the gateway.

- Crash events come from a narrow internal app-host-to-gateway intake path
  emitted by Orbit-managed runtime hooks.
- Crash intake accepts only authenticated active app-host identities and only
  `crashed` events.
- The intake is idempotent by event id.
- The intake path is not a CLI command contract.

### Lifecycle events

These rules describe the durable history that records process state transitions.

- Process lifecycle events are durable history, not process-unit configuration.
  Orbit records `started`, `stopped`, and `crashed` events for SSE consumers,
  CLI streams, and automation.
- `started` and `stopped` events are recorded by successful gateway service lifecycle actions.
- `crashed` events are recorded when the runtime hook on the node reports an exit.

### Read commands

These rules describe what default process read commands cover and where live data lives.

- Default process read commands report gateway configuration and the latest durable process events.
- They do not SSH to nodes or run live process manager probes.
- Live runtime verification belongs to [`doctor --family=process`](process-doctor.md). Live event delivery belongs to the internal event stream.

### Runtime lifecycle commands

These rules describe how lifecycle commands address runtime units.

- Runtime lifecycle commands start, stop, restart, and inspect derived units.
- Omitting `[name]` for `process:start`, `process:stop`, and `process:restart` targets every process definition in process order for the resolved context.
- Logs come from the selected runtime backend for the selected runtime unit.
  Host-command units that use launchd write stdout and stderr to Orbit-owned
  log files at `~/Library/Logs/Orbit/processes/<runtimeUnit>.out.log` and
  `~/Library/Logs/Orbit/processes/<runtimeUnit>.err.log`.

### macOS launchd scope boundaries

The `launchd` runtime is the macOS host-command process backend. It renders
Orbit-owned user LaunchAgents under the configured node user's
`~/Library/LaunchAgents`, uses labels shaped
`dev.hardimpact.orbit.<runtimeUnit>`, and operates those labels through the
node-local `launchctl` command adapter. The runtime name is `launchd`;
`launchctl` is not a process runtime value.

This slice intentionally excludes system LaunchDaemons under
`/Library/LaunchDaemons`, root-owned boot-before-login services, third-party
LaunchAgent inventory or adoption, a broad macOS background-process dashboard,
launchd migration tooling, and launchd crash notification parity without an
Orbit-owned crash wrapper.

### Managed services

`process:add --service` is the supported way to create node-owned database/cache
services and selected node-owned platform services. Managed services own service
version, image, endpoint, credentials, ports, volumes, labels, lifecycle, and
logs on the process row. The process name does not imply the service identifier.
The endpoint host is always the owning node's WireGuard service address. Orbit
does not fall back to the node SSH host, node name, loopback, or Docker network
alias for managed service endpoints.

`process:list` and bounded `process:logs` expose safe connection metadata for
managed services: service identifier, version family, concrete version, service
runtime unit name, endpoint host/port, and credential field names. They do not
expose credential values.

When a service endpoint points back at the owning node's own WireGuard service
address, `doctor --family=process` diagnoses the Linux self-route with
`ip route get <wireguard-ip>`. The diagnostic is read-only. macOS reports
`WireGuard self-route diagnostics are only supported on Linux.` for this
optimization and does not attempt to add or replace routes.

Supported managed services in this vertical slice:

| Service | Versions | Default runtime | Notes |
| --- | --- | --- | --- |
| `mysql` | `8` -> `8.4`, `9` -> `9` | `docker` | Published ports are version-family specific, so MySQL 8 and 9 can coexist on one node. |
| `postgres` | `16` -> `16-alpine` | `docker` | Publishes PostgreSQL on the owning node's WireGuard service address. Analytics backing-service deployments may select `docker-swarm`. |
| `clickhouse` | `24.12` -> `24.12-alpine` | `docker` | Publishes the ClickHouse HTTP endpoint on the owning node's WireGuard service address. Analytics backing-service deployments may select `docker-swarm`. |
| `valkey` | `8` -> `8.1` | `docker` | Publishes the Valkey TCP endpoint from the owning node's WireGuard service address and is the required app-facing WebSocket broker. |
| `mailpit` | `latest` -> `latest` | `docker` | Publishes SMTP (`1025`) on the owning node. The Web UI stays private on the Docker network and should be exposed with a proxy route to `http://mailpit:8025`. |
| `prometheus` | `3` -> `v3.12.0` | `docker-swarm` | Metrics-role service process for host-resource time-series storage. Uses local TSDB retention of 15 days. |
| `grafana` | `13` -> `13.0.2` | `docker-swarm` | Metrics-role service process for dashboards, exposed through the private `metrics.orbit` route. |
| `node-exporter` | `1` -> `1.11.1` | `systemd` | Metrics-role host process that exposes host resource metrics on metrics and active workload nodes through the owning node's WireGuard service address. |
| `plausible` | `3.2.1` -> `3.2.1` | `docker` | Plausible CE application service. The analytics role converges it with `docker-swarm` and selected PostgreSQL and ClickHouse WireGuard endpoints. |

`docker-swarm` is also admitted for node-owned managed services whose catalog
entry declares Swarm support. `node-exporter` declares only `systemd` because
it observes host resources directly.
On macOS nodes, managed service processes use the `docker` runtime through the
node's reachable Docker-compatible container provider; `docker-swarm` remains
Linux-only. Managed Mac services stay on Docker unless the command is
explicitly process-owned as a host-command process, in which case macOS uses
`launchd`. The `systemd` runtime is not supported on macOS.

### Command argument conventions

Create commands use positional arguments for required fields. Update commands use named options so omitted fields preserve their current value. This is why `process:add` accepts the required `[command]` positionally, while `process:update` uses `--command=<command>` and `--name=<new-slug>` as optional update fields.

Implementation-shape details for process runtime backends and the Orbit
Scheduler live in
[tech-stack.md#process-manager](../../tech-stack.md#process-manager) and
[tech-stack.md#scheduler](../../tech-stack.md#scheduler).

## Authorization

Process commands are authorized by the gateway against the authenticated
WireGuard peer and the scoped permission set stored on the grant that
connects the caller to the resolved node or app instance serving node. The CLI does not detect or
branch on the node-role column locally.

- `process:list` requires `process:read` on a grant to the resolved node or app
  instance serving node.
- `process:logs` requires `process:logs`, which is covered by `process:read`.
- Runtime-lifecycle commands (`process:start`, `process:stop`,
  `process:restart`) require their matching `process:start`, `process:stop`,
  or `process:restart` permission on a grant to that node. Self-targeting
  calls from the app instance serving node are authorized by its
  self-grant — see [Architecture: Self-grants and
  self-serving](../../architecture.md#self-grants-and-self-serving).
- Configuration mutation commands (`process:add`, `process:update`,
  `process:remove`) require their matching mutation permission and are
  typically reserved for admin-class presets. The exception is the `app-dev`
  self-grant: an app-dev node can create, update, and remove app-instance-owned
  process definitions for instances served by that same node. `app-prod` self-grants do not
  include process mutation permissions, and app-dev self-grants do not include
  runtime lifecycle permissions such as `process:start`, `process:stop`, or
  `process:restart`.

Every process command is a request to the gateway typed API. The CLI never
writes process configuration, reads Docker, systemd, or launchd logs directly, or
operates a runtime backend directly.

## Runtime Unit Environment

Derived process runtime units expose a runtime environment that is separate
from workspace setup and teardown step environment. App and workspace process
contexts receive the URL/TLS variables below when applicable; node-level
processes receive only variables meaningful to their selected runtime. Runtime
units do not receive `ORBIT_*` lifecycle variables by contract.

| Variable | Value | Why it is exposed |
| --- | --- | --- |
| `PATH` | Predictable command lookup path including runtime image and project-local tool directories | Lets commands resolve tools such as `php`, `composer`, `vp`, `bun`, and project-local binaries in the selected process runtime. |
| `HOME` | Runtime user's home directory | Lets tools find home-relative config and caches. |
| `APP_URL` | Resolved app or workspace HTTPS URL | Gives Laravel/runtime code the canonical public URL. |
| `VITE_APP_URL` | Resolved app or workspace HTTPS URL | Keeps Vite-aware processes aligned with the runtime URL. |
| `VITE_VALET_HOST` | Resolved app or workspace host without scheme | Supports Herd/Valet-style Laravel Vite configuration that keys off a host. |
| `VITE_DEV_SERVER_KEY` | Orbit-managed TLS key path visible to the process | Lets Laravel Vite use Orbit cert material through its standard env bridge. |
| `VITE_DEV_SERVER_CERT` | Orbit-managed TLS cert path visible to the process | Lets Laravel Vite use Orbit cert material through its standard env bridge. |

Laravel Vite's `detectTls` option probes Herd/Valet certificate locations. Orbit
does not require per-project cert copies in those layouts. Instead, Orbit
exposes canonical `APP_URL`, `VITE_APP_URL`, `VITE_VALET_HOST`, and
`VITE_DEV_SERVER_KEY` / `VITE_DEV_SERVER_CERT` so standard Laravel Vite config
can use the env-provided certificate bridge while remaining compatible with
Herd/Valet-style apps.

## Development Server Runtime

Process commands store the operator-provided command and do not rewrite it for a specific frontend server. A development server that must be reachable from a client browser or support HMR across the Orbit network must bind to a node-reachable interface instead of loopback. For Vite-backed processes, the expected command shape is:

```text
npm run dev -- --host=0.0.0.0
```

Equivalent package-manager or framework adapter commands are valid when they produce the same non-loopback bind behavior. Orbit supplies `APP_URL`, `VITE_APP_URL`, `VITE_VALET_HOST`, `VITE_DEV_SERVER_KEY`, and `VITE_DEV_SERVER_CERT` so the process can serve the app/workspace URL over Orbit-managed HTTPS and keep browser HMR connected through the network path.

Firewall permissions, proxy routes, DNS names, and TLS trust remain owned by their respective families. The process family owns the stored command, runtime unit environment, and process lifecycle, not public exposure policy.

## Crash Event Delivery

The crash hooks that Orbit manages on nodes post `crashed` events back to the gateway when the process definition's crash-notification policy is enabled. No crash hook is required for `crash_notification=none`. The payload includes a stable event id, runtime unit name, exit code, exit status, and occurrence time. Duplicate event ids return the original record instead of creating duplicate history.

When the runtime unit name resolves to active process configuration, the event is linked to the process, logical app, concrete app instance, workspace, and node. Unmatched units are still recorded with their raw runtime-unit name so operators do not lose crash history while doctor or process configuration is being repaired.

Agent IDE crash notification is a consumer of the recorded crash event. For `agent_ide`, Orbit reads a short recent journal tail for the runtime unit and sends a crash report to the effective app or workspace Agent IDE session when one is available. Failure to read the log tail or deliver the notification does not fail event ingestion.

## Commands

Each command links to its public documentation and technical contract.

1. [`orbit process:add [name] [command]`](1_process-add/process-add.md)
2. [`orbit process:update [name]`](2_process-update/process-update.md)
3. [`orbit process:remove [name]`](3_process-remove/process-remove.md)
4. [`orbit process:list`](4_process-list/process-list.md)
5. [`orbit process:start [name]`](5_process-start/process-start.md)
6. [`orbit process:stop [name]`](6_process-stop/process-stop.md)
7. [`orbit process:restart [name]`](7_process-restart/process-restart.md)
8. [`orbit process:logs [name]`](8_process-logs/process-logs.md)

## Doctor

[`process-doctor.md`](process-doctor.md) defines the `process` family probe,
issue codes, fix map, adopt map, and test mapping for
`doctor --family=process`.

## Internal Commands

This command supports internal Orbit machinery and is hidden from the public
command list.

1. [`orbit process-event:stream`](internal/1_process-event-stream/process-event-stream.md)
