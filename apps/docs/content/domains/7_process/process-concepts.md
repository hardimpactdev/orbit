# Process Concepts

This document defines process-family vocabulary and invariants. It supports the process command contracts and the [process doctor](process-doctor.md). It does not override the [Architecture](../../architecture.md).

## Identity

These terms define how process definitions are identified, scoped, and ordered.

- **Process definition:** Gateway-owned configuration for one Orbit-managed
  long-running unit. A process may be scoped to a node, concrete app instance,
  or workspace. An app-scoped definition is persisted against an `AppInstance`,
  never only against a logical app. App-instance and workspace processes run
  on that instance's serving node; node-level processes run directly against
  the owning node.
- **Process identity slug:** Lowercase identity slug used as the process name.
  Maximum 64 characters.
- **Process scope:** Optional target that binds a process to a node, concrete
  app instance, or workspace. The scope selects the serving node, working
  context, default environment, and lifecycle authorization boundary.
- **App-instance selector:** Dotted `<app.instance>` identity used by public
  process commands. A bare logical-app slug is shorthand only when the app has
  exactly one instance. If it has more than one, resolution fails with
  `validation_failed`, `field=app`, and `reason=app_instance_required`.
- **Canonical app identity:** App-instance and workspace process identities and
  JSON include both the logical `app` slug and concrete `app_instance` slug.
- **Process tool dependency:** Optional catalog tool slug used by the process,
  such as `php-cli`, `viteplus`, `opencode-cli`, or `polyscope`. The dependency
  asserts required capability; it does not transfer lifecycle ownership to the
  tool.
- **External macOS runtime provider:** macOS applications such as OrbStack may
  be managed as tool capabilities without a process row until a future external
  runtime/process model admits lifecycle ownership in Orbit. This is distinct
  from Orbit-owned `launchd` process units.
- **Managed service:** Catalog entry selected with `process:add --service` for
  a runnable service such as MySQL, PostgreSQL, Valkey, ClickHouse, Prometheus,
  Grafana, node-exporter, or Plausible CE. Service version, runtime, endpoint,
  credentials, lifecycle, and logs belong to the process row. The process name
  does not imply the managed service identifier. The service endpoint host is
  the owning node's WireGuard service address. Consumers on the same node rely
  on the provisioning-owned WireGuard self-route, not on loopback or Docker
  aliases.
- **Process order:** Stable order of process definitions inside their owning
  scope. Read and bulk lifecycle commands use that order.

## Runtime Artifacts

These terms describe the runtime objects that Orbit derives from process definitions.

- **Runtime unit:** Concrete runnable realization of a process definition in
  its selected node, app-instance, or workspace context on the resolved node or
  app instance serving node.
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
  App/workspace Swarm runtime remains deferred and is rejected before runtime
  side effects.
- **Systemd process runtime:** Runtime backend for Linux host command units,
  including node-level services such as OpenCode Server or PolyScope Server and
  app/workspace command processes. The process row owns start/stop/restart/log
  lifecycle; any related tool row supplies only the installed capability.
- **Launchd process runtime:** Runtime backend for macOS host command units.
  The first slice renders user LaunchAgent plists under the configured node
  user's `~/Library/LaunchAgents`, uses Orbit-owned labels under
  `dev.hardimpact.orbit.*`, and stores stdout/stderr logs under
  `~/Library/Logs/Orbit/processes`. System LaunchDaemons and third-party
  process inventory are outside this runtime slice.
- **Runtime unit expansion:** One process definition renders one or more
  runtime units as required by its scope. Node-level and workspace-scoped
  process definitions normally render one unit. App-instance-scoped inherited
  process definitions may render one main-instance unit plus one unit for each
  active workspace belonging to that same instance.
- **Runtime unit filename:** Backend-safe identity for a rendered runtime unit.
  Systemd units use `orbit_<scope>_<process>` segment names for app/workspace
  command processes, with logical app and app-instance slugs as separate scope
  segments; Docker units use equivalent Orbit-owned container names.
  Launchd labels use `dev.hardimpact.orbit.<runtimeUnit>` and plist files use
  the same label under the configured node user's LaunchAgents directory.
  The `orbit_` prefix marks Orbit ownership, and underscores are reserved as
  backend segment delimiters.
- **Runtime unit environment:** Predictable runtime environment exposed to
  derived runtime units, including `PATH`, `HOME`, `APP_URL`, `VITE_APP_URL`,
  and TLS path variables that Orbit manages. Separate from workspace lifecycle
  step environment.
- **Runtime backend artifact:** Backend-specific rendering of a runtime unit.
  Systemd runtime units are host service files. Docker runtime units are
  container definitions. Launchd runtime units are user LaunchAgent plist files
  with stdout/stderr log files at
  `~/Library/Logs/Orbit/processes/<runtimeUnit>.out.log` and
  `~/Library/Logs/Orbit/processes/<runtimeUnit>.err.log`. The artifact starts
  the process command or image in the resolved node, app-instance, or workspace context.

## Policy

These terms define per-process behavioral rules that apply to every derived runtime unit.

- **Restart policy:** Process-definition policy used by every derived runtime
  unit. Allowed values are `never`, `on_failure`, and `always`. Manual
  `process:restart` actions do not change the policy.
- **Crash notification policy:** Process-definition opt-in for crash event
  delivery. When the policy is enabled, `crashed` events resolve the effective
  agent IDE and notify the active session when one is available. Units that use
  launchd reject `agent_ide` crash notification in this slice with
  `launchd_crash_notification_deferred` until Orbit owns a macOS crash wrapper
  that can emit gateway-authenticated `crashed` events.
- **Process runtime selection:** Process-definition field that records which
  backend renders the runtime units. App and workspace host-command processes
  default to `systemd` on Linux and `launchd` on macOS. Node-owned host-command
  processes follow the same platform default. Managed services default to
  `docker` unless their catalog entry and node platform admit another service
  runtime. Existing `supervisor` rows are migrated to `systemd`; `supervisor`
  is not a supported runtime.

## Events

These terms define the durable lifecycle records that process commands produce and consume.

- **Process event:** Durable lifecycle history record. `started` and `stopped`
  events are recorded by successful gateway service lifecycle actions.
  `crashed` events are recorded when the runtime hook on the node reports an
  exit.
- **Crash event:** A process event emitted by the runtime hooks that Orbit
  manages on nodes, for definitions whose crash-notification policy is
  enabled. Carries a stable event id, runtime unit name, exit code, exit status,
  and occurrence time.

## Boundaries

These terms define what the process family owns and what remains outside its scope.

- **Process-family boundaries:** Process commands own process definitions,
  optional node/app-instance/workspace scope, optional tool dependency, runtime backend,
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
