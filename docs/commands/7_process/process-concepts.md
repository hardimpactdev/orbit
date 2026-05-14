# Process Concepts

This document defines process-family vocabulary and invariants. It supports the process command contracts and the [process doctor](process-doctor.md). It does not override the [Architecture](../../ARCHITECTURE.md).

## Identity

These terms define how process definitions are identified and ordered.

- **Process definition:** A gateway-owned configuration record, scoped to one app, that describes one long-running command for that app and its workspaces.
- **Process identity slug:** Lowercase identity slug used as the process name. Maximum 64 characters.
- **Process order:** Stable app-local order of process definitions. `process:add` appends new definitions after existing ones; read and bulk lifecycle commands use that order.

## Runtime Artifacts

These terms describe the runtime objects that Orbit derives from process definitions.

- **Runtime unit:** Abstract product noun for an Orbit-managed long-running
  process derived from app, optional workspace, and process configuration.
- **Runtime unit expansion:** One runtime unit is rendered for the main app
  instance and one for each workspace of that app per process definition. Each
  runtime unit is applied by the process manager as a Supervisor program.
- **Runtime unit filename:** `orbit_<app>_<workspace|main>_<process>`. The `orbit_` prefix marks Orbit ownership; underscores are reserved as backend segment delimiters. The rendered Supervisor program uses the same name.
- **Runtime unit environment:** Predictable runtime environment exposed to derived runtime units, including `PATH`, `HOME`, `APP_URL`, `VITE_APP_URL`, and TLS path variables that Orbit manages. Separate from workspace lifecycle step environment.
- **Supervisor program:** Backend-specific rendering of a runtime unit. The program is supervised by the node's process manager and starts the process command in the resolved app or workspace context.

## Policy

These terms define per-process behavioral rules that apply to every derived runtime unit.

- **Restart policy:** Process-definition policy used by every derived runtime unit. Allowed values are `never`, `on_failure`, and `always`. Manual `process:restart` actions do not change the policy.
- **Crash notification policy:** Process-definition opt-in for crash event delivery. When enabled, `crashed` events resolve the effective agent IDE and notify the active session when one is available.

## Events

These terms define the durable lifecycle records that process commands produce and consume.

- **Process event:** Durable lifecycle history record. `started` and `stopped` events are recorded by successful gateway runtime lifecycle actions.
- `crashed` events are recorded when the runtime hook on the app node reports an exit.
- **Crash event:** A process event emitted by the runtime hooks that Orbit manages on app nodes, for definitions whose crash-notification policy is enabled. Carries a stable event id, runtime unit name, exit code, exit status, and occurrence time.

## Boundaries

These terms define what the process family owns and what remains outside its scope.

- **Process-family boundaries:** Process commands own process definitions, runtime unit derivation, runtime unit environment, restart policy, crash notification policy, and lifecycle event history.
- They do not own app or workspace configuration, proxy routes, firewall policy, schedule definitions, or tool registration.
