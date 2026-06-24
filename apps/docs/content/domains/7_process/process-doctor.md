# Process Doctor

[Back to Process commands.](README.md)

The process family doctor implements the
[Family Doctor Implementation Contract](../11_operation/3_doctor/technical/1_doctor.md#family-doctor-implementation-contract).
`key()` returns `process`.

`doctor --family=process` verifies whether gateway process definitions still
match the runtime-unit artifacts and the service endpoint assumptions
that make those definitions executable on their owning nodes.

The process family owns these facts:

- gateway-owned process definitions: node/app/workspace owner, name, command,
  restart policy, crash-notification policy, optional tool dependency, runtime,
  runtime configuration, and service endpoint metadata;
- derived runtime-unit identity for the main app instance and every workspace: `orbit_<app>_<workspace|main>_<process>`;
- systemd process runtime units rendered from process, app, workspace, and node
  configuration, including command, working directory, restart policy, and
  runtime environment;
- Docker containers and Docker Swarm services rendered from node-owned managed
  services, including service identifier, version family, concrete
  version, runtime unit name, spec hash, endpoint metadata, and credential
  field names;
- metrics-role Prometheus, Grafana, and node-exporter runtime artifacts created
  from process definitions; the metrics command domain does not add a separate
  doctor family;
- lifecycle event notifier material that Orbit manages, required to record runtime `crashed` events from app-host units whose process definitions require crash event reporting;
- stale process runtime artifacts owned by Orbit whose identity maps to
  nothing active — no matching app, workspace, or process definition.
- read-only self-route diagnostics for node-owned service endpoints that point
  back at the owning node's own WireGuard service address.

Node reachability and WireGuard route mutation belong to `node` provisioning
and topology work. App source, PHP runtime, and app-owned runtime configuration
belong to `app`. Workspace source directories and setup state belong to
`workspace`. Proxy routes, schedules, tools, and firewall rules remain outside
the process family.

## Probe Layers

The processes probe reads gateway process definitions and checks the layers below in order.

### Registry configuration

Every selected process definition has a valid app reference, process name,
command, restart policy, and crash-notification policy. Node-owned service
process definitions have a valid active node owner instead of an app owner.

### Owning app and workspace expansion

The owning app resolves to an active app record and the expected runtime contexts are the main app instance plus every active workspace for that app.

### Process manager availability

The owning node has the selected runtime backend available and responsive.
Systemd-backed process units require `systemctl` and journald access.
Docker-backed service process units require Docker container inspection.
Docker Swarm-backed service process units require Docker service inspection.
When this layer fails, the probe stops and reports
`process.runtime_backend_unavailable` instead of cascading to downstream
checks.

When a gateway process row cannot render its expected runtime unit from stored
intent, the probe reports `process.runtime_unit_unrenderable` and continues
with the remaining selected process rows.

For systemd runtime units, the probe reads rendered Orbit-owned service files
and compares their content hash, restart policy, and environment lines against
the rendered gateway spec. For Docker and Docker Swarm runtime units, the probe
compares the Orbit-managed process spec hash on the concrete container or
service labels.

### Runtime-unit identity

Each expected runtime context maps to exactly one runtime unit name that Orbit owns, using `orbit_<app>_<workspace|main>_<process>`.

### WireGuard self-route diagnostics

When a node-owned service process endpoint host equals the owning node's
WireGuard service address, the process probe runs `ip route get <wireguard-ip>`
on Linux and expects a local route such as `local <ip> dev lo` or an equivalent
local route. macOS reports the exact unsupported message
`WireGuard self-route diagnostics are only supported on Linux.` and does not
mutate routes. Missing, unsupported, or unverifiable self-route diagnostics are
reported as process-family `unverifiable` drift because route mutation belongs
to node provisioning/topology work.

### Runtime artifact presence

Each expected runtime unit exists as the selected backend artifact: a systemd
service, Docker container, or Docker Swarm service. Checked only when the
selected runtime backend is reachable.

### Runtime artifact shape

The rendered command, working directory, restart policy, user, and runtime environment match gateway configuration.

### Lifecycle notifier material

The crash event hooks that Orbit manages, gateway endpoint material, and gateway CA material required to write durable `crashed` events exist and match the selected process definitions.

### Stale runtime units

Runtime artifacts that Orbit owns are reported as process-family drift when their encoded app, workspace, or process identity has no match in active gateway configuration.

### Lifecycle events as history

Latest lifecycle events are history, not desired state. The processes probe may read them to explain observed runtime state, but it does not repair or adopt event history.

## Process Issue Codes

Each code below identifies a specific process-family drift condition that the probe can detect.

| Code | Detected when |
| --- | --- |
| `process.record_incomplete` | A selected process definition lacks app, name, command, restart policy, or crash-notification policy. |
| `process.owner_app_invalid` | The process definition points at a missing app, unauthorized app, or app whose owning node is not an active node. |
| `process.owner_node_invalid` | The process definition points at a node owner that is not active. |
| `process.runtime_context_unresolved` | The expected main app or workspace runtime context cannot be derived from gateway configuration. |
| `process.wireguard_self_route_unavailable` | A node-owned service endpoint points at the owning node's own WireGuard service address, but Linux self-route diagnostics are missing/unhealthy or the platform does not support this diagnostic. |
| `process.runtime_backend_unavailable` | The selected process runtime backend is unavailable. Downstream runtime-unit checks are skipped while this code is active. |
| `process.runtime_unit_unrenderable` | Gateway process intent is incomplete or invalid, so the expected runtime unit cannot be rendered. |
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
| `process.runtime_backend_unavailable` | No `doctor --restore` action. Process manager installation and recovery belong to node operations. Process doctor reports the dependency and does not attempt to install Docker or systemd. |
| `process.wireguard_self_route_unavailable` | No `doctor --restore` action. WireGuard self-route mutation belongs to node provisioning/topology repair, not the process family. |
| `process.runtime_unit_unrenderable` | No `doctor --restore` action. Fix the process definition or run the role baseline that owns the incomplete service process intent. |
| `process.runtime_unit_missing` | Re-render and reload the missing backend artifact from gateway app, workspace, and process configuration. |
| `process.runtime_unit_extra` | Stop and remove the stale Orbit-owned backend artifact whose identity has no match in active gateway app, workspace, and process configuration. |
| `process.runtime_unit_mismatch` | Rewrite the backend artifact from gateway app, workspace, and process configuration. |
| `process.restart_policy_mismatch` | Rewrite the backend restart policy from the process definition. |
| `process.runtime_environment_mismatch` | Rewrite the runtime environment from the runtime unit environment contract. |
| `process.event_notifier_missing` | Reinstall Orbit-managed lifecycle event notifier material for the selected runtime unit. |
| `process.event_notifier_mismatch` | Rewrite lifecycle event notifier material to match gateway configuration and the current gateway event intake identity. |

`doctor --restore` does not handle `process.record_incomplete`,
`process.owner_app_invalid`, `process.owner_node_invalid`,
`process.runtime_context_unresolved`,
`process.wireguard_self_route_unavailable`, or
`process.runtime_backend_unavailable`, or
`process.runtime_unit_unrenderable`.

Missing or invalid process definitions and app ownership problems remain explicit process, app, or workspace command work. Process doctor never creates process definitions, changes process names, edits app or workspace records, or adopts arbitrary runtime-unit files as gateway configuration.

## Process Adopt Map

Use `doctor --adopt` to apply the adoption action listed for each code.

| Code | `doctor --adopt` behavior |
| --- | --- |
| `process.runtime_backend_unavailable` | No adoption action. |
| `process.wireguard_self_route_unavailable` | No adoption action. |
| `process.runtime_unit_unrenderable` | No adoption action. Invalid gateway intent must be corrected instead of adopted from runtime state. |
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
| `apps/gateway/tests/Feature/Doctor/ProcessesFamilyDoctorContractTest.php` | Processes-family contract for the global doctor command (see breakdown below). |
| `apps/gateway/tests/Unit/Services/Processes/ProcessesProbeTest.php` | In-memory probe diff behavior for the processes family (see breakdown below). |
| `apps/gateway/tests/E2E/Read/ProcessesDoctorTest.php` | Real read-only `doctor --family=process --json` on a topology with host systemd process runtime units. |
| `apps/gateway/tests/E2E/Ephemeral/ProcessesDoctorFixTest.php` | Real `doctor --family=process --restore` repair of missing or divergent process runtime artifacts and lifecycle event notifier material. |

`ProcessesFamilyDoctorContractTest` covers processes-family dispatch,
probe-layer selection, process issue codes, the process fix map, denied
process adopt cases, and scope filtering for process probes. It also asserts
that `process.runtime_backend_unavailable` short-circuits downstream layers.

`ProcessesProbeTest` covers registry configuration, node/app/workspace owner
validation, app and workspace expansion, and process manager availability.
It also covers runtime-unit identity and Docker/Docker Swarm managed service metadata.
WireGuard self-route diagnostics, missing/extra/drifted runtime artifacts, restart
policy drift, runtime environment drift, event notifier drift, and exclusion of
non-process drift from issue codes are also covered.
