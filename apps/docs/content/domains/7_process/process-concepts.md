# Process Concepts

This document defines process-family vocabulary and invariants. It supports the process command contracts and the [process doctor](process-doctor.md). It does not override the [Architecture](../../architecture.md).

## Identity

These terms define how process definitions are identified, scoped, and ordered.

- **Process definition:** Gateway-owned configuration for one Orbit-managed
  long-running unit. A process may be scoped to a node, concrete instance,
  or workspace. An instance-scoped definition is persisted against an `AppInstance`,
  never only against a project. Instance and workspace processes run
  on that instance's serving node; node-level processes run directly against
  the owning node.
- **Process identity slug (`key`):** Stable lowercase identity slug stored
  internally as `Process.name` and exposed publicly as `key` (with deprecated
  compatibility alias `name` equal to `key`). Maximum 64 characters. Used for
  runtime unit identity, lifecycle targeting, and uniqueness within the owner
  scope. Renaming the identity does not rewrite the display label.
- **Process display label (`label`):** Durable non-null human-facing label
  persisted on the process row. Defaults to the identity key when omitted at
  create time. Updated only via explicit `label` input; max 255 characters after
  trim; must be non-empty.
- **Process scope:** Optional target that binds a process to a node, concrete
  instance, or workspace. The scope selects the serving node, working
  context, default environment, and lifecycle authorization boundary.
- **Instance selector:** Dotted `<project.instance>` identity used by public
  process commands. A bare project slug is shorthand only when the project has
  exactly one instance. If it has more than one, resolution fails with
  `validation_failed`, `field=instance`, and `reason=instance_required`.
- **App hostname selector:** Hostname target accepted as the `app` query or body
  key (CLI `--app`) for process list and lifecycle actions. The value is an
  app-instance hostname or workspace hostname resolved against the gateway
  proxy registry with exact registered proxy-route domain precedence so custom domains
  work. An app-owned route resolves the concrete `AppInstance`; a
  workspace-owned route resolves that workspace and its `AppInstance`. The
  selector key is `app` only; `url` is never accepted. `app` is mutually
  exclusive with `node`, `instance`, and `workspace` target modes (the existing
  `instance`+`workspace` pairing remains valid only for those two keys).
- **Browser process CORS admission:** For browser process list/lifecycle/stream
  calls that send `Origin`, the gateway admits only a registered app/workspace
  proxy domain Origin that matches the requested `app` hostname and uses a
  default scheme/port tuple (`http` with no port or `:80`, `https` with no port
  or `:443`). Non-default ports are distinct browser origins and are rejected.
  CORS never authenticates the caller. CLI, TypeScript SDK, and browser clients
  share one auth model: actual WireGuard peer source IP, then grant, permission,
  and target-node authorization. Clients send no bearer token and no peer-IP
  identity header. Optional `X-Orbit-Client` is a non-identity label only;
  native EventSource streams must not require it. CORS may allow `Last-Event-ID`
  for EventSource reconnect on the stream route.
- **Browser process SSE:** Toolbars subscribe to
  `GET /api/processes/stream?app=<hostname>` (app-only). No client polling and
  no Laravel Toolbar PHP/filesystem watcher. See the process stream technical
  contract under `internal/1_process-event-stream`.
- **Canonical project identity:** Instance and workspace process identities and
  JSON include both the logical `project` slug and concrete `instance` slug.
- **Process tool dependency:** Optional catalog tool slug used by the process,
  such as `php-cli`, `viteplus`, or `hermes`. The dependency
  asserts required capability; it does not transfer lifecycle ownership to the
  tool.
- **External macOS runtime provider:** macOS applications such as OrbStack may
  be managed as tool capabilities without a process row until a future external
  runtime/process model admits lifecycle ownership in Orbit. This is distinct
  from Orbit-owned `launchd` process units.
- **Managed service:** Catalog entry selected with `process:add --service` for
  a runnable service such as MySQL, PostgreSQL, Valkey, ClickHouse, Prometheus,
  Grafana, node-exporter, or Plausible CE. Service version, runtime, endpoint,
  credentials, lifecycle, and logs normally belong to the process row.
  SeaweedFS is the named exception: its credentials belong only to the
  `seaweedfs` tool row and the process renderer consumes them without storing a
  second credential source. The process name does not imply the managed service
  identifier. The service endpoint host is the owning node's WireGuard service
  address. Consumers on the same node rely on the provisioning-owned WireGuard
  self-route, not on loopback or Docker aliases.
- **PostgreSQL service process:** Managed service whose identifier is always
  `postgres`, regardless of major version or consumer. Each process records an
  explicit version family and concrete image version, initial database and
  username, and a WireGuard-bound published port. Its container target remains
  `5432`. Process-derived container name, service name, host data path, and
  volume keep multiple PostgreSQL processes independent on one node. PostgreSQL
  16 mounts its volume at `/var/lib/postgresql/data`; PostgreSQL 18 mounts at
  `/var/lib/postgresql` to retain the image's major-version data layout.
- **Process order:** Stable order of process definitions inside their owning
  scope. Read and bulk lifecycle commands use that order.

## Runtime Artifacts

These terms describe the runtime objects that Orbit derives from process definitions.

- **Runtime unit:** Concrete runnable realization of a process definition in
  its selected node, instance, or workspace context on the resolved node or
  instance serving node.
- **Process runtime:** Backend that runs a process. Supported runtime families
  are `systemd`, `launchd`, `docker`, and `docker-swarm`. Systemd is the Linux
  host-command process runtime for node-, app-, and workspace-scoped commands.
  Launchd is the macOS host-command process runtime for Orbit-owned user
  LaunchAgents. Docker is used for containerized processes such as databases,
  caches, and FrankenPHP app or workspace web runtimes. Docker Swarm is
  Linux-only and valid only for node-owned managed service processes.
- **Docker process runtime:** Runtime backend that runs a process as an
  Orbit-managed Docker container. It is used for containerized database, cache,
  agent, app, and workspace runtime units.
- **Docker Swarm process runtime:** Runtime backend that runs a node-owned
  managed service process as an Orbit-managed Swarm service. It is currently
  admitted for managed-service MySQL, PostgreSQL, Valkey, ClickHouse,
  Prometheus, Grafana, and Plausible CE processes.
  Instance/workspace Swarm runtime remains deferred and is rejected before runtime
  side effects.
- **Systemd process runtime:** Runtime backend for Linux host command units,
  including node-level host services and instance/workspace command processes.
  The process row owns start/stop/restart/log lifecycle; any related tool row
  supplies only the installed capability.
- **Launchd process runtime:** Runtime backend for macOS host command units.
  The first slice renders user LaunchAgent plists under the configured node
  user's `~/Library/LaunchAgents`, uses Orbit-owned labels under
  `dev.hardimpact.orbit.*`, and stores stdout/stderr logs under
  `~/Library/Logs/Orbit/processes`. System LaunchDaemons and third-party
  process inventory are outside this runtime slice.
- **Runtime unit expansion:** One process definition renders one or more
  runtime units as required by its scope. Node-level and workspace-scoped
  process definitions normally render one unit. Instance-scoped inherited
  process definitions may render one main-instance unit plus one unit for each
  active workspace belonging to that same instance.
- **Runtime unit filename:** Backend-safe five-part identity for a rendered
  runtime unit. The canonical form is
  `orbit_<project>_<instance>_<workspace|main>_<process>` (for example
  `orbit_docs_development_main_vite` and
  `orbit_docs_development_feature-docs_vite`). Component order is project,
  instance, workspace (or `main` for the instance checkout), then process.
  When that full identity exceeds the shared backend limit (64 characters for
  the strictest consumer, launchd), Orbit deterministically bounds it by
  retaining a readable prefix and appending a stable 12-character SHA-256
  fragment so render, probe, and restore share one valid name. Systemd and
  Docker use the same identity. Launchd labels use
  `dev.hardimpact.orbit.<runtimeUnit>` and plist files use the same label under
  the configured node user's LaunchAgents directory. The `orbit_` prefix marks
  Orbit ownership, and underscores are reserved as backend segment delimiters.
- **Runtime unit environment:** Predictable runtime environment exposed to
  derived runtime units. Every unit receives `PATH` and `HOME`. Instance and
  workspace process units also receive `APP_URL`, `VITE_APP_URL`, and TLS path
  variables that Orbit manages. Node-owned process units receive only variables
  meaningful to their selected runtime and do not synthesize app/Vite variables.
  Separate from workspace lifecycle step environment.
- **Runtime backend artifact:** Backend-specific rendering of a runtime unit.
  Systemd runtime units are host service files. Docker runtime units are
  container definitions. Launchd runtime units are user LaunchAgent plist files
  with stdout/stderr log files at
  `~/Library/Logs/Orbit/processes/<runtimeUnit>.out.log` and
  `~/Library/Logs/Orbit/processes/<runtimeUnit>.err.log`. The artifact starts
  the process command or image in the resolved node, instance, or workspace context.

## Policy

These terms define per-process behavioral rules that apply to every derived runtime unit.

- **Restart policy:** Process-definition policy used by every derived runtime
  unit. Allowed values are `never`, `on_failure`, and `always`. Manual
  `process:restart` actions do not change the policy. Restart policy governs an
  active unit; it does not opt an app-development instance or workspace out of
  hibernation.
- **Development hibernation policy:** App-instance and workspace process groups
  on `app-dev` nodes are installed without host-boot start intent. The first
  HTTP request wakes the full owning group through one serialized activation
  operation. Internal wake starts every configured lifecycle process
  concurrently, then marks the scope awake only after a bounded aggregate
  readiness check observes every expected runtime unit running. Public bulk
  `process:start` keeps process-order semantics and does not adopt a
  process-dependency model. One hour without route activity makes a group
  eligible for an automatic stop during the next ten-minute sweep. The
  hibernator runs independently from the Orbit Scheduler. Routes for one scope
  share its marker, activity state, and lock. Bulk lifecycle actions use that
  lock and align the marker with the group state; named actions do not change
  it. Node-owned and `app-prod` processes remain boot-persistent and are outside
  this policy.
- **Development cold-dependency policy:** An already-hibernated app-instance or
  workspace group becomes eligible for dependency pruning after seven days
  without HTTP, process-lifecycle, or source-tree activity. Shared source paths
  use their newest owning-scope activity. Orbit removes only contained,
  non-symlink Composer or JavaScript dependency directories backed by a
  deterministic lockfile; it retains lockfiles, build artifacts, and
  package-manager caches. Later sweeps skip a scope that is already cold. The
  next HTTP activation restores only the missing dependency families before
  the concurrent process-start phase and aggregate readiness gate. The
  activation plan enumerates the scope's effective configured processes
  dynamically rather than assuming fixed roles such as a queue worker. Failed
  or uncertain pruning leaves the source cold, and Orbit clears that state only
  after dependency restoration and process startup both succeed. Dependencies
  are single-flight across scopes sharing a node and source path, while process
  startup and warm markers remain scope-owned. Stale takeover must acquire both
  fences.
- **Crash notification policy:** Process-definition field for crash
  notification delivery. The only supported value is `none`. Orbit does not
  deliver crash notifications through an external notification adapter.
- **Process runtime selection:** Process-definition field that records which
  backend renders the runtime units. Instance and workspace host-command processes
  default to `systemd` on Linux and `launchd` on macOS. Node-owned host-command
  processes follow the same platform default. Managed services default to
  `docker` unless their catalog entry and node platform admit another service
  runtime. Existing `supervisor` rows are migrated to `systemd`; `supervisor`
  is not a supported runtime.

## Events

These terms define the durable lifecycle records that process commands produce and consume.

- **Process event:** Durable lifecycle history record. Gateway lifecycle actions
  record transitional `starting` / `stopping` / `restarting` **before** the
  runtime call, then terminal `started` / `stopped` on success or `failed` when
  the backend returns false or throws (exception is rethrown after recording).
  Ordinary creation/convergence paths that start units (for example
  `process:add` default start, workspace setup, role/doctor restore starts) use
  the same starting→started/failed pattern. `process:update --restart` records
  restarting→started/failed with the current process name snapshot (including
  renames applied in the same update). Deploy active-release FrankenPHP
  container restarts are not process lifecycle events. `crashed` events are
  recorded when the runtime hook on the node reports an exit. Gateway lifecycle
  event history is the authoritative process runtime state for list and toolbar
  consumers; list does not live-probe nodes.
- **Process status:** Normalized runtime status from the latest durable
  lifecycle event: `starting`, `running` (`started`), `stopping`, `stopped`,
  `restarting`, `crashed`, and `unknown` (no event yet, or latest event
  `failed`). Transitional values appear only when truthfully persisted.
  Compatible `last_event` fields remain present when an event exists.
- **Crash event:** A process event emitted by the runtime hooks that Orbit
  manages on nodes, for definitions whose crash-notification policy is
  enabled. Carries a stable event id, runtime unit name, exit code, exit status,
  and occurrence time.

## Boundaries

These terms define what the process family owns and what remains outside its scope.

- **Process-family boundaries:** Process commands own process definitions,
  optional node/instance/workspace/`app` hostname scope, optional tool dependency, runtime backend,
  runtime configuration, command or image configuration, environment, ports,
  volumes, restart policy, lifecycle commands, logs, crash notification policy,
  runtime unit derivation, runtime unit environment, and lifecycle event
  history. WireGuard interface setup and self-route mutation belong to node
  provisioning/topology work; process doctor may only diagnose self-route
  health when a process endpoint depends on it.
- Plausible CE is a process-owned service. Its configured image version,
  lifecycle, environment, endpoint, and logs live on the process row generated
  for the analytics role, not on a tool row.
- They do not own app or workspace registry configuration, proxy routes,
  firewall policy, schedule definitions, tool catalog membership, or tool
  installation/update/removal. Orbit does not add a separate service family for
  this model, and process `kind` or `category` is intentionally deferred until
  a concrete workflow needs it. The launchd slice does not own system
  LaunchDaemons, third-party LaunchAgent inventory or adoption, a broad macOS
  background-process dashboard, migration tooling, or crash-notification parity
  without an Orbit-owned wrapper.
