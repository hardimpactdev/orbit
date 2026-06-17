# Process Commands

Process commands manage runtime units that Orbit owns and keeps running. A process
definition is stored on the gateway, may be scoped to a node, app, or
workspace, and owns its lifecycle through a selected runtime backend.

The gateway is the source of truth for process configuration. When node-side work is required, the gateway renders and applies derived runtime units on the owning node.

## Domain Rules

These rules govern process configuration ownership, naming, and runtime unit derivation.

### Ownership and identity

These rules cover who owns process configuration and how process definitions are named.

- The gateway owns process configuration.
- Process names are identity slugs: lowercase letters, digits, and hyphens only; they cannot start or end with a hyphen and are limited to 64 characters.
- Process definitions may be scoped to a node, app, or workspace. The scope
  selects the owning node and default runtime context.
- Process definitions have a stable order inside their owning scope.
  `process:add` appends new definitions after existing ones in that scope.
- Read and bulk lifecycle commands use that order.
- A process may reference a catalogued tool with a tool dependency. The tool
  supplies a node-level capability; the process still owns start, stop,
  restart, and logs.
- A process may also be materialized from a service process definition. Service
  definitions are node-owned runnable services such as MySQL or Redis; they do
  not reference tools.

### Runtime unit derivation

These rules describe how runtime units are derived from process definitions.

- Runtime units are the process-family product noun: concrete runnable units
  derived from node/app/workspace scope and process configuration. They are not
  gateway state rows.
- Node-level and workspace-scoped process definitions normally render one
  runtime unit. App-scoped inherited process definitions may render one runtime
  unit for the main app instance and one runtime unit for each workspace of
  that app.
- Each rendered runtime unit is applied by its selected process runtime backend:
  `systemd` for Linux host commands, `docker` for containerized processes, or
  `docker-swarm` for node-owned managed service processes.
- Public app/workspace host-command process definitions use the `systemd`
  runtime on Linux. App/workspace `docker` rows are reserved for Orbit-managed runtime
  processes such as generated FrankenPHP web-runtime units, not arbitrary
  public host commands.
- The process definition supplies shared fields such as command, restart policy,
  runtime backend, runtime configuration, and crash notification policy. The
  rendering context supplies per-instance fields such as node/app/workspace
  identity, path, URL, environment, ports, and volumes.
- Runtime unit names use Orbit-owned backend-safe names such as
  `orbit_<scope>_<process>`.
- The `orbit_` prefix marks Orbit ownership, and underscores are reserved as
  backend segment delimiters.
- Systemd unit names, Docker container names, and Swarm service names
  derive from the same product identity.

### Restart policy

Restart policy is process configuration. Each derived main-app or workspace runtime unit uses the process definition's `never`, `on_failure`, or `always` policy. Manual `process:restart` actions do not change that policy.

### Crash notification policy

Process definitions may opt in to crash notification. When the policy is enabled, a `crashed` event resolves the effective agent IDE and notifies the active session when one is available. Crash notification delivery is best-effort and must not prevent the event from being recorded.

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

### Service process definitions

Service process definitions are the supported way to create node-owned
database/cache services and selected node-owned platform services. They own
service version, image, endpoint, credentials, ports, volumes, labels,
lifecycle, and logs on the process row.
The endpoint host is always the owning node's WireGuard service address. Orbit
does not fall back to the node SSH host, node name, loopback, or Docker network
alias for managed service endpoints.

`process:list` and bounded `process:logs` expose safe connection metadata for
service definitions: definition name, version family, concrete version, service
runtime unit name, endpoint host/port, and credential field names. They do not
expose credential values.

When a service endpoint points back at the owning node's own WireGuard service
address, `doctor --family=process` diagnoses the Linux self-route with
`ip route get <wireguard-ip>`. The diagnostic is read-only. macOS reports
`WireGuard self-route diagnostics are only supported on Linux.` for this
optimization and does not attempt to add or replace routes.

Supported definitions in this vertical slice:

| Definition | Versions | Default runtime | Notes |
| --- | --- | --- | --- |
| `mysql` | `8` -> `8.4`, `9` -> `9` | `docker` | Published ports are version-family specific, so MySQL 8 and 9 can coexist on one node. |
| `redis` | `7` -> `7.2` | `docker` | Publishes the Redis TCP endpoint from the owning node's WireGuard service address. |
| `prometheus` | `3` -> `v3.12.0` | `docker-swarm` | Metrics-role service process for host-resource time-series storage. Uses local TSDB retention of 15 days. |
| `grafana` | `13` -> `13.0.2` | `docker-swarm` | Metrics-role service process for dashboards, exposed through the private `metrics.orbit` route. |
| `node-exporter` | `1` -> `1.11.1` | `systemd` | Metrics-role host process that exposes host resource metrics on the owning node's WireGuard service address. |

`docker-swarm` is also admitted for node-owned service process definitions
whose definition declares Swarm support. `node-exporter` declares only
`systemd` because it observes host resources directly.
PostgreSQL follows the same process-owned product direction, but it is not
advertised as a supported process definition until its definition lands.

### Command argument conventions

Create commands use positional arguments for required fields. Edit commands use named options so omitted fields preserve their current value. This is why `process:add` accepts the required `[command]` positionally, while `process:edit` uses `--command=<command>` as one optional edit field among several.

Implementation-shape details for process runtime backends and the Orbit
Scheduler live in
[tech-stack.md#process-manager](../../tech-stack.md#process-manager) and
[tech-stack.md#scheduler](../../tech-stack.md#scheduler).

## Authorization

Process commands are authorized by the gateway against the authenticated
WireGuard peer and the scoped permission set stored on the grant that
connects the caller to the process owning node. The CLI does not detect or
branch on the node-role column locally.

- `process:list` requires `process:read` on a grant to the resolved process
  owning node.
- `process:logs` requires `process:logs`, which is covered by `process:read`.
- Runtime-lifecycle commands (`process:start`, `process:stop`,
  `process:restart`) require their matching `process:start`, `process:stop`,
  or `process:restart` permission on a grant to that node. Self-targeting
  calls from the owning app-host node are authorized by its
  self-grant — see [Architecture: Self-grants and
  self-serving](../../architecture.md#self-grants-and-self-serving).
- Configuration mutation commands (`process:add`, `process:edit`,
  `process:remove`) require their matching `process:add`, `process:edit`, or
  `process:remove` permission and are typically reserved for admin-class
  presets.

Every process command is a request to the gateway typed API. The CLI never
writes process configuration, reads Docker or systemd logs directly, or
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
| `VITE_VALET_HOST` | Resolved app or workspace host without scheme | Keeps Laravel Vite and Vite Plus TLS / hot-file detection compatible with Orbit-managed HTTPS names. |
| `VITE_DEV_SERVER_KEY` | Orbit-managed TLS key path for the resolved URL | Lets dev-server processes serve HTTPS with Orbit-managed cert material. |
| `VITE_DEV_SERVER_CERT` | Orbit-managed TLS cert path for the resolved URL | Lets dev-server processes serve HTTPS with Orbit-managed cert material. |

`VITE_VALET_HOST` is exposed for the same compatibility reason as workspace setup: existing Laravel Vite and Vite Plus configurations use it while deriving development-server TLS and hot-file URLs. Orbit still supplies canonical `APP_URL`, `VITE_APP_URL`, and certificate paths so newer app configs can key off Orbit-owned fields directly.

## Development Server Runtime

Process commands store the operator-provided command and do not rewrite it for a specific frontend server. A development server that must be reachable from a client browser or support HMR across the Orbit network must bind to a node-reachable interface instead of loopback. For Vite-backed processes, the expected command shape is:

```text
npm run dev -- --host=0.0.0.0
```

Equivalent package-manager or framework adapter commands are valid when they produce the same non-loopback bind behavior. Orbit supplies `APP_URL`, `VITE_APP_URL`, `VITE_VALET_HOST`, `VITE_DEV_SERVER_KEY`, and `VITE_DEV_SERVER_CERT` so the process can serve the app/workspace URL over Orbit-managed HTTPS and keep browser HMR connected through the network path.

Firewall permissions, proxy routes, DNS names, and TLS trust remain owned by their respective families. The process family owns the stored command, runtime unit environment, and process lifecycle, not public exposure policy.

## Crash Event Delivery

The crash hooks that Orbit manages on nodes post `crashed` events back to the gateway when the process definition's crash-notification policy is enabled. No crash hook is required for `crash_notification=none`. The payload includes a stable event id, runtime unit name, exit code, exit status, and occurrence time. Duplicate event ids return the original record instead of creating duplicate history.

When the runtime unit name resolves to active process configuration, the event is linked to the process, app, workspace, and node. Unmatched units are still recorded with their raw runtime-unit name so operators do not lose crash history while doctor or process configuration is being repaired.

Agent IDE crash notification is a consumer of the recorded crash event. For `agent_ide`, Orbit reads a short recent journal tail for the runtime unit and sends a crash report to the effective app or workspace Agent IDE session when one is available. Failure to read the log tail or deliver the notification does not fail event ingestion.

## Commands

Each command links to its public documentation and technical contract.

1. [`orbit process:add [name] [command]`](1_process-add/process-add.md)
2. [`orbit process:edit [name]`](2_process-edit/process-edit.md)
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
