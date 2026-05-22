# Process Doctor

[Back to Process commands.](README.md)

`doctor --family=process` verifies whether gateway process definitions still match the runtime-unit artifacts that make those definitions executable on their owning nodes.

The process family owns these facts:

- gateway-owned process definitions: app, name, command, restart policy, and crash-notification policy;
- derived runtime-unit identity for the main app instance and every workspace: `orbit_<app>_<workspace|main>_<process>`;
- Docker process runtime units rendered from process, app, workspace, and node
  configuration, including command, working directory, restart policy, runtime
  environment, and selected app/workspace runtime image;
- lifecycle event notifier material that Orbit manages, required to record runtime `crashed` events from app-host units whose process definitions require crash event reporting;
- stale process runtime artifacts owned by Orbit whose identity no longer maps
  to an active app, workspace, or process definition.

Node reachability belongs to `node`. App source, PHP runtime, and app-owned runtime configuration belong to `app`. Workspace source directories and setup state belong to `workspace`. Proxy routes, schedules, tools, and firewall rules remain outside the process family.

## Probe Layers

The processes probe reads gateway process definitions and checks the layers below in order.

### Registry configuration

Every selected process definition has a valid app reference, process name, command, restart policy, and crash-notification policy.

### Owning app and workspace expansion

The owning app resolves to an active app record and the expected runtime contexts are the main app instance plus every active workspace for that app.

### Process manager availability

The node has Docker process runtime support available and responsive. Explicit
`process.runtime=supervisor` units also require Supervisor to be installed, with
`supervisord` reachable and its control socket responsive. When this layer
fails, the probe stops and reports `process.runtime_backend_unavailable`
instead of cascading to downstream checks.

### Runtime-unit identity

Each expected runtime context maps to exactly one runtime unit name that Orbit owns, using `orbit_<app>_<workspace|main>_<process>`.

### Runtime artifact presence

Each expected runtime unit exists as the selected backend artifact: Docker
container for Docker process runtime units, or Supervisor program for explicit
`supervisor` runtime units. Checked only when the process manager is reachable.

### Runtime artifact shape

The rendered command, working directory, restart policy, user, and runtime environment match gateway configuration.

### Lifecycle notifier material

The crash event hooks that Orbit manages, gateway endpoint material, and gateway CA material required to write durable `crashed` events exist and match the selected process definitions.

### Stale runtime units

Runtime artifacts that Orbit owns are reported as process-family drift when their encoded app, workspace, or process identity no longer maps to active gateway configuration.

### Lifecycle events as history

Latest lifecycle events are history, not desired state. The processes probe may read them to explain observed runtime state, but it does not repair or adopt event history.

## Process Issue Codes

Each code below identifies a specific process-family drift condition that the probe can detect.

| Code | Detected when |
| --- | --- |
| `process.record_incomplete` | A selected process definition lacks app, name, command, restart policy, or crash-notification policy. |
| `process.owner_app_invalid` | The process definition points at a missing app, unauthorized app, or app whose owning node is not an active node. |
| `process.runtime_context_unresolved` | The expected main app or workspace runtime context cannot be derived from gateway configuration. |
| `process.runtime_backend_unavailable` | The selected process runtime backend is unavailable: Docker for default units, or Supervisor for explicit `supervisor` units. Downstream runtime-unit checks are skipped while this code is active. |
| `process.runtime_unit_missing` | An expected Orbit-owned runtime unit has no corresponding backend artifact. |
| `process.runtime_unit_extra` | An Orbit-owned backend artifact exists without matching active app, workspace, and process configuration. |
| `process.runtime_unit_mismatch` | The runtime artifact command, working directory, user, or unit name differs from gateway process configuration. |
| `process.restart_policy_mismatch` | The rendered backend restart policy differs from the process definition. |
| `process.runtime_environment_mismatch` | The rendered runtime environment differs from the runtime unit environment contract. |
| `process.event_notifier_missing` | Runtime lifecycle event notifier material is absent for a runtime unit that should emit crash events. |
| `process.event_notifier_mismatch` | Runtime lifecycle event notifier material exists but points at the wrong gateway endpoint, app, workspace, process, or event intake identity. |

## Process Fix Map

Use `doctor --restore` to trigger the repair action listed for each code.

| Code | `doctor --restore` behavior |
| --- | --- |
| `process.runtime_backend_unavailable` | No `doctor --restore` action. Process manager installation and recovery belong to `tool` family doctor and node operations. Process doctor reports the dependency and does not attempt to install Docker or Supervisor. |
| `process.runtime_unit_missing` | Re-render and reload the missing backend artifact from gateway app, workspace, and process configuration. |
| `process.runtime_unit_extra` | Stop and remove the stale Orbit-owned backend artifact whose identity no longer maps to active gateway app, workspace, and process configuration. |
| `process.runtime_unit_mismatch` | Rewrite the backend artifact from gateway app, workspace, and process configuration. |
| `process.restart_policy_mismatch` | Rewrite the backend restart policy from the process definition. |
| `process.runtime_environment_mismatch` | Rewrite the runtime environment from the runtime unit environment contract. |
| `process.event_notifier_missing` | Reinstall Orbit-managed lifecycle event notifier material for the selected runtime unit. |
| `process.event_notifier_mismatch` | Rewrite lifecycle event notifier material to match gateway configuration and the current gateway event intake identity. |

`doctor --restore` does not handle `process.record_incomplete`, `process.owner_app_invalid`, `process.runtime_context_unresolved`, or `process.runtime_backend_unavailable`.

Missing or invalid process definitions and app ownership problems remain explicit process, app, or workspace command work. Process doctor never creates process definitions, changes process names, edits app or workspace records, or adopts arbitrary runtime-unit files as gateway configuration.

## Process Adopt Map

Use `doctor --adopt` to apply the adoption action listed for each code.

| Code | `doctor --adopt` behavior |
| --- | --- |
| `process.runtime_backend_unavailable` | No adoption action. |
| `process.runtime_unit_extra` | No adoption action. Runtime artifacts are derived and must not create process configuration. |
| `process.runtime_unit_mismatch` | No adoption action. Update process configuration with `process:edit` when the observed runtime command should become configuration. |
| `process.restart_policy_mismatch` | No adoption action. Update restart policy with `process:edit` when the observed policy should become configuration. |
| `process.runtime_environment_mismatch` | No adoption action. Runtime environment is derived from app, workspace, and node configuration. |
| `process.event_notifier_mismatch` | No adoption action. Event notifier material is derived from gateway-owned process configuration and gateway event intake identity. |

Process doctor does not adopt runtime backend artifacts, logs, event history, app source, workspace source, scheduler artifacts, proxy backend artifacts, tool installs, or firewall rules as process configuration.

## Test Mapping

Required test files:

| Path | Coverage |
| --- | --- |
| `tests/Feature/Doctor/ProcessesFamilyDoctorContractTest.php` | Processes-family contract for the global doctor command (see breakdown below). |
| `tests/Unit/Services/Processes/ProcessesProbeTest.php` | In-memory probe diff behavior for the processes family (see breakdown below). |
| `tests/E2E/Read/ProcessesDoctorTest.php` | Real read-only `doctor --family=process --json` on a topology with Docker-rendered process runtime units. |
| `tests/E2E/Ephemeral/ProcessesDoctorFixTest.php` | Real `doctor --fix --family=process --restore` repair of missing or divergent process runtime artifacts and lifecycle event notifier material. |

`ProcessesFamilyDoctorContractTest` covers processes-family dispatch,
probe-layer selection, process issue codes, the process fix map, denied
process adopt cases, and scope filtering for process probes. It also asserts
that `process.runtime_backend_unavailable` short-circuits downstream layers.

`ProcessesProbeTest` covers registry configuration, app and workspace
expansion, process manager availability, runtime-unit identity,
missing/extra/drifted runtime artifacts, restart policy drift, runtime environment
drift, event notifier drift, and exclusion of non-process drift from issue
codes.
