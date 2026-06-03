# Process Concepts

This document defines process-family vocabulary and invariants. It supports the process command contracts and the [process doctor](process-doctor.md). It does not override the [Architecture](../../architecture.md).

## Identity

These terms define how process definitions are identified, scoped, and ordered.

- **Process definition:** Gateway-owned configuration for one Orbit-managed
  long-running unit. A process may be scoped to a node, app, or workspace.
  App and workspace processes run in the selected source/runtime context;
  node-level processes run directly against the owning node.
- **Process identity slug:** Lowercase identity slug used as the process name.
  Maximum 64 characters.
- **Process scope:** Optional target that binds a process to a node, app, or
  workspace. The scope selects the owning node, working context, default
  environment, and lifecycle authorization boundary.
- **Process tool dependency:** Optional catalog tool slug used by the process,
  such as `php-cli`, `viteplus`, `opencode`, or `polyscope`. The dependency
  asserts required capability; it does not transfer lifecycle ownership to the
  tool.
- **Process order:** Stable order of process definitions inside their owning
  scope. Read and bulk lifecycle commands use that order.

## Runtime Artifacts

These terms describe the runtime objects that Orbit derives from process definitions.

- **Runtime unit:** Concrete runnable realization of a process definition in
  its selected node, app, or workspace context.
- **Process runtime:** Backend that runs a process. Supported runtime families
  are `supervisor` and `docker`; `docker-swarm` is a planned runtime family.
  Supervisor is the host long-running command runner. Docker is used for
  containerized processes such as databases, caches, and FrankenPHP app or
  workspace web runtimes.
- **Supervisor process runtime:** Runtime backend that runs a process unit as a
  host Supervisor program with Supervisor logs and lifecycle controls.
- **Docker process runtime:** Runtime backend that runs a process as an
  Orbit-managed Docker container. It is used for containerized database, cache,
  agent, app, and workspace runtime units.
- **Runtime unit expansion:** One process definition renders one or more
  runtime units as required by its scope. Node-level and workspace-scoped
  process definitions normally render one unit. App-scoped inherited process
  definitions may render one main-app unit plus one unit for each workspace.
- **Runtime unit filename:** Backend-safe identity for a rendered runtime unit.
  Supervisor units use `orbit_<scope>_<process>` segment names; Docker units use
  equivalent Orbit-owned container names. The `orbit_` prefix marks Orbit
  ownership, and underscores are reserved as backend segment delimiters.
- **Runtime unit environment:** Predictable runtime environment exposed to
  derived runtime units, including `PATH`, `HOME`, `APP_URL`, `VITE_APP_URL`,
  and TLS path variables that Orbit manages. Separate from workspace lifecycle
  step environment.
- **Runtime backend artifact:** Backend-specific rendering of a runtime unit.
  Supervisor runtime units are host Supervisor programs. Docker runtime units
  are container definitions. The artifact starts the process command or image in
  the resolved node, app, or workspace context.

## Policy

These terms define per-process behavioral rules that apply to every derived runtime unit.

- **Restart policy:** Process-definition policy used by every derived runtime
  unit. Allowed values are `never`, `on_failure`, and `always`. Manual
  `process:restart` actions do not change the policy.
- **Crash notification policy:** Process-definition opt-in for crash event
  delivery. When the policy is enabled, `crashed` events resolve the effective
  agent IDE and notify the active session when one is available.
- **Process runtime selection:** Process-definition field that records which
  backend renders the runtime units. Existing process command compatibility may
  default app and workspace command processes to `supervisor` until the runtime
  migration is complete; the product model admits `supervisor`, `docker`, and
  planned `docker-swarm` process runtimes.

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
  optional node/app/workspace scope, optional tool dependency, runtime backend,
  runtime configuration, command or image configuration, environment, ports,
  volumes, restart policy, lifecycle commands, logs, crash notification policy,
  runtime unit derivation, runtime unit environment, and lifecycle event
  history.
- They do not own app or workspace registry configuration, proxy routes,
  firewall policy, schedule definitions, tool catalog membership, or tool
  installation/update/removal. Orbit does not add a separate service family for
  this model, and process `kind` or `category` is intentionally deferred until
  a concrete workflow needs it.
