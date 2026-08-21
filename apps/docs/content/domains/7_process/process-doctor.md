# Process Doctor

[Back to Process commands.](README.md)

The process family doctor implements the
[Family Doctor Implementation Contract](../11_operation/3_doctor/technical/1_doctor.md#family-doctor-implementation-contract).
`key()` returns `process`.

`doctor --family=process` verifies whether gateway process definitions still
match the runtime-unit artifacts and the service endpoint assumptions
that make those definitions executable on their resolved nodes or instance serving nodes.

The process family owns these facts:

- gateway-owned process definitions: node/instance/workspace owner, name, command,
  restart policy, crash-notification compatibility field, optional tool dependency,
  runtime, runtime configuration, and service endpoint metadata;
- derived runtime-unit identity for one concrete instance and, on
  `app-dev` only, its workspaces:
  `orbit_<app>_<instance>_<workspace|main>_<process>`, deterministically
  bounded with a stable hash when the full name exceeds the shared 64-character
  backend limit so launchd labels remain valid;
- systemd process runtime units rendered from process, app, workspace, and node
  configuration, including command, working directory, restart policy, and
  runtime environment;
- launchd process runtime units rendered as user LaunchAgent plist files that
  Orbit owns under `~/Library/LaunchAgents`, with labels shaped
  `dev.hardimpact.orbit.<runtimeUnit>` and stdout/stderr logs under
  `~/Library/Logs/Orbit/processes`;
- Docker containers and Docker Swarm services rendered from node-owned managed
  services, including service identifier, version family, concrete
  version, runtime unit name, spec hash, endpoint metadata, and credential
  field names;
- canonical FrankenPHP process rows for every PHP instance/workspace runtime and the
  canonical node-owned `seaweedfs` process row for each active `s3` baseline;
  these rows own concrete container presence, unit shape, lifecycle, logs, and
  repair even though instance/workspace/tool families retain their desired policy,
  PHP/image, and credential facts;
- metrics-role Prometheus, Grafana, and node-exporter runtime artifacts created
  from process definitions; the metrics command domain does not add a separate
  doctor family;
- stale process runtime artifacts owned by Orbit whose identity maps to
  nothing active — no matching app, workspace, or process definition.
- read-only self-route diagnostics for node-owned service endpoints that point
  back at the owning node's own WireGuard service address.

Node reachability and WireGuard route mutation belong to `node` provisioning
and topology work. App source policy, PHP runtime, and instance runtime configuration
belong to `instance`. Workspace source directories and setup state belong to
`workspace`. Proxy routes, schedules, tools, and firewall rules remain outside
the process family.

## Probe Layers

The processes probe reads gateway process definitions and checks the layers below in order.

On an `app-prod` target, the probe never loads a workspace-owned process row or
expands an app process into workspace runtime contexts. Unsupported workspace
owner types, runtime units, and event identities are excluded before comparison.
Main instance and node-owned process drift remains visible.

### Registry configuration

Every selected instance/workspace process definition has valid app and
concrete instance references, plus a process name, command, restart policy, and
crash-notification compatibility field. Node-owned service process definitions
have a valid active node owner instead of an instance owner.

For a PHP app that already owns managed FrankenPHP runtime intent, every active
instance must have its canonical FrankenPHP process row. A missing
secondary-instance row is reported before artifact inspection so restore can
recreate both the derived definition and its container.

### Owning instance and workspace expansion

The owner resolves to one active `Instance`. On `app-dev`, expected runtime
contexts are that instance's main context plus every registered workspace belonging
to the same instance. On `app-prod`, only the main context is eligible. All
expected units are placed on the instance's serving node; other instances of
the same app are outside this definition's expansion.

### Process manager availability

The resolved node or instance serving node has the selected runtime backend available and responsive.
Systemd-backed process units require `systemctl` and journald access.
Launchd-backed process units require `launchctl` access in the owning user's
GUI domain and readable plist/log paths for LaunchAgents that Orbit owns.
Docker-backed service process units require Docker container inspection.
Docker Swarm-backed service process units require Docker service inspection.
The process family also runs one node-wide Agent-push inventory of
Orbit-managed app runtime containers so an orphan remains visible even after
its app and process rows have been removed.
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
service labels. Docker port host bindings are part of that rendered spec, so
selected publish binds (`wireguard` and/or `loopback`) and the SeaweedFS
WireGuard-only bind posture are verified as process runtime-unit shape rather
than as tool drift.

For launchd runtime units, the probe reads LaunchAgent plist files that Orbit
owns and compares content hashes against the gateway-rendered plist. For a
node-owned unit or a unit outside `app-dev`, Doctor also checks whether the
label is loaded in the current user GUI domain. An unloaded `app-dev` unit is
normal because development lifecycle commands load it on demand. Log paths are
reported separately from configuration drift. Doctor reports unloaded labels
but does not load or start them.

For app-development Docker runtime units, the probe also reads the exact
Caddy-side hibernation marker that owns request-driven wake-up. A stopped unit
is expected while its instance or workspace scope is explicitly marked
hibernated. If that marker cannot be read, Doctor keeps reporting the stopped
unit as drift rather than assuming it is asleep. Node-owned processes,
production runtimes, missing artifacts, and runtime-unit shape remain subject
to their ordinary checks regardless of hibernation state.

### Runtime-unit identity

Each instance/workspace runtime context maps to exactly one runtime unit name that
Orbit owns, using `orbit_<app>_<instance>_<workspace|main>_<process>`. Node-owned services
may declare a stable configured unit name, such as `orbit-seaweedfs` for the
`seaweedfs` process row.

### WireGuard self-route diagnostics

When a node-owned service process endpoint host equals the owning node's
WireGuard service address, the process probe runs `ip route get <wireguard-ip>`
on Linux and expects a local route such as `local <ip> dev lo` or an equivalent
local route. The probe is host-boundary work: on a containerized gateway it runs
on the gateway host, not inside the `orbit-gateway` container. macOS reports the
exact unsupported message
`WireGuard self-route diagnostics are only supported on Linux.` and does not
mutate routes. Unsupported platforms are **not applicable** — they produce no
process-family drift. Missing or unverifiable self-route diagnostics on a
**supported** Linux node are reported as process-family `unverifiable` drift
because route mutation belongs to node provisioning/topology work.

### Runtime placement

Doctor selects and probes app/workspace processes by current app instance and
workspace placement, not by a possibly stale denormalized `process.node_id`. A
process whose instance moved nodes is diagnosed on the current placement node.
A genuinely missing runtime unit on that current node remains reportable.
Launchd runtime units are only rendered and probed on macOS execution nodes:
when placement resolves to a Linux node for a launchd process, Doctor reports
`process.runtime_unit_unrenderable` rather than inventing a Linux
`Library/LaunchAgents` path.

### Runtime artifact presence

Each expected runtime unit exists as the selected backend artifact: a systemd
service, launchd LaunchAgent plist, Docker container, or Docker Swarm service.
Checked only when the selected runtime backend is reachable.

### Runtime artifact shape

The rendered command, working directory, restart policy, user, runtime
environment, launchd label, and launchd stdout/stderr log paths match gateway
configuration.

### Stale runtime units

Runtime artifacts that Orbit owns are reported as process-family drift when
their encoded app, workspace, node, tool, or process identity has no match in
active gateway configuration. For launchd, the probe only inspects Orbit-owned labels
under `dev.hardimpact.orbit.*` and matching plist paths in
`~/Library/LaunchAgents`; third-party LaunchAgents are outside process-family
scope. For Docker app runtimes, the node-wide inventory compares
`orbit.container.kind=app-runtime` and `orbit.app` labels with active logical
app and concrete instance runtime slugs. Inventory failure reports
`process.runtime_backend_unavailable` instead of silently hiding orphan drift.

### Lifecycle events as history

Latest lifecycle events are history, not desired state. The processes probe may read them to explain observed runtime state, but it does not repair or adopt event history.

Doctor does not install, verify, restore, or adopt a process crash hook or an
external notification adapter.

## Process Issue Codes

Every public issue code that this family can emit is listed below and registered
in the Doctor issue catalog with an explicit public disposition
(`genuine_drift`,
`blocked_inspection`, `invalid_intent`, or `runtime_incident`). Genuine drift
codes declare a restore action in the Fix Map and catalog; non-genuine
dispositions are never auto-repaired as if they were restorable drift. See the
global
[doctor technical contract](../11_operation/3_doctor/technical/1_doctor.md#issue-dispositions)
for disposition semantics.

Each code below identifies a specific process-family drift condition that the probe can detect.

| Code | Detected when |
| --- | --- |
| `process.record_incomplete` | A selected instance/workspace process definition lacks app, concrete instance, name, command, restart policy, or crash-notification policy. |
| `process.owner_app_invalid` | The process definition points at a missing app, missing instance, or instance whose serving node is not active. |
| `process.owner_node_invalid` | The process definition points at a node owner that is not active. |
| `process.runtime_context_unresolved` | The expected main instance or same-instance workspace runtime context cannot be derived from gateway configuration. |
| `process.wireguard_self_route_unavailable` | A node-owned service endpoint points at the owning node's own WireGuard service address, but supported Linux self-route diagnostics are missing or unhealthy. Unsupported platforms are not applicable and emit no issue. |
| `process.runtime_backend_unavailable` | The selected process runtime backend is unavailable. Downstream runtime-unit checks are skipped while this code is active. |
| `process.runtime_unit_unrenderable` | Gateway process intent is incomplete or invalid, so the expected runtime unit cannot be rendered. |
| `process.runtime_unit_missing` | An expected Orbit-owned runtime unit has no corresponding backend artifact, or an active instance of a managed PHP app lacks its canonical FrankenPHP process row. |
| `process.runtime_unit_extra` | An Orbit-owned backend artifact exists without matching active app, workspace, and process configuration. |
| `process.runtime_unit_mismatch` | The runtime artifact command, working directory, user, or unit name differs from gateway process configuration. |
| `process.runtime_unit_down` | A Docker runtime unit whose configured restart policy is `always` exists but is not running, unless its app-development instance or workspace scope is explicitly marked hibernated. Units configured as `never` are intentionally excluded. |
| `process.runtime_unit_unloaded` | A launchd-backed node-owned or non-`app-dev` runtime unit has an Orbit-owned plist but its label is not loaded in the current user GUI domain. |
| `process.restart_policy_mismatch` | The rendered backend restart policy differs from the process definition. |
| `process.runtime_environment_mismatch` | The rendered runtime environment differs from the runtime unit environment contract. |
| `process.remote_shell_probe_failed` | The remote process probe raised before it could return a usable runtime observation for the selected node. |

## Process Fix Map

Use `doctor --restore` to trigger the repair action listed for each code.

| Code | `doctor --restore` behavior |
| --- | --- |
| `process.runtime_backend_unavailable` | No `doctor --restore` action. Process manager installation and recovery belong to node operations. Process doctor reports the dependency and does not attempt to install Docker, systemd, or launchd. |
| `process.wireguard_self_route_unavailable` | No `doctor --restore` action. WireGuard self-route mutation belongs to node provisioning/topology repair, not the process family. |
| `process.runtime_unit_unrenderable` | Rebuild a node-owned managed service from its catalog entry and stored process intent, then restore its runtime unit. Other invalid definitions have no automatic action. |
| `process.runtime_unit_missing` | Re-render and reload the missing backend artifact from gateway instance, workspace, and process configuration. For a managed PHP instance missing its canonical FrankenPHP process row, recreate that derived row first and then restore its container. |
| `process.runtime_unit_extra` | Stop and remove the stale Orbit-owned backend artifact whose identity has no match in registered gateway instance, workspace, and process configuration. Docker orphan containers use the `orbit-app-*` inventory path. Systemd and launchd extras are removed only when the unit identity is a strict Orbit-owned `orbit_*` name and the expected path is the canonical managed location (`/etc/systemd/system/{unit}.service` or the node user's `~/Library/LaunchAgents/dev.hardimpact.orbit.{unit}.plist`). Arbitrary units are never removed. |
| `process.runtime_unit_mismatch` | Rewrite the backend artifact from gateway instance, workspace, and process configuration. |
| `process.runtime_unit_down` | Start the exact current Orbit-owned Docker runtime unit when its configured restart policy is `always`. |
| `process.runtime_unit_unloaded` | No `doctor --restore` action. Doctor reports the unloaded label; `process:start` is the explicit lifecycle command. |
| `process.restart_policy_mismatch` | Rewrite the backend restart policy from the process definition. |
| `process.runtime_environment_mismatch` | Rewrite the runtime environment from the runtime unit environment contract. |

`doctor --restore` does not handle `process.record_incomplete`,
`process.owner_app_invalid`, `process.owner_node_invalid`,
`process.runtime_context_unresolved`,
`process.wireguard_self_route_unavailable`,
`process.runtime_backend_unavailable`,
or `process.runtime_unit_unloaded`.

Invalid user-managed process definitions and instance ownership problems
remain explicit process, app, or workspace command work. Process doctor does
not create arbitrary definitions, change process names, edit instance or
workspace records, or adopt arbitrary runtime-unit files as gateway
configuration. Its only definition-recreation exception is the canonical,
derived FrankenPHP process row for an active instance of an app that already
owns managed FrankenPHP runtime intent.

For a node-owned managed service,
`process.runtime_unit_unrenderable` can also restore canonical service intent
from the service catalog when the process row stores enough service and version
detail. The process row remains the authority for stored credentials, service
options, image overrides, and publish binds. For a service that uses a generated
secret, missing or inconsistent stored credentials fail the Doctor action.
Doctor does not generate replacement credentials. It does not infer arbitrary
commands or repair invalid app or workspace process definitions.

For launchd, restore is limited to Orbit-owned user LaunchAgents. It may write
the expected plist under the configured node user's `~/Library/LaunchAgents`,
operate the matching `dev.hardimpact.orbit.*` label through launchd lifecycle
actions, and leave stdout/stderr log files in place. It does not write system
LaunchDaemons, adopt third-party LaunchAgents, or inventory unrelated launchd
jobs.

## Process Adopt Map

Use `doctor --adopt` to apply the adoption action listed for each code.

| Code | `doctor --adopt` behavior |
| --- | --- |
| `process.runtime_backend_unavailable` | No adoption action. |
| `process.wireguard_self_route_unavailable` | No adoption action. |
| `process.runtime_unit_unrenderable` | No adoption action. Invalid gateway intent must be corrected instead of adopted from runtime state. |
| `process.runtime_unit_extra` | No adoption action. Runtime artifacts are derived and must not create process configuration. |
| `process.runtime_unit_mismatch` | No adoption action. Update process configuration with `process:update` when the observed runtime command should become configuration. |
| `process.runtime_unit_unloaded` | No adoption action. Use `process:start` when the unit must be loaded; Doctor only reports the current state. |
| `process.restart_policy_mismatch` | No adoption action. Update restart policy with `process:update` when the observed policy should become configuration. |
| `process.runtime_environment_mismatch` | No adoption action. Runtime environment is derived from app, workspace, and node configuration. |

Process doctor does not adopt runtime backend artifacts, logs, event history, app source, workspace source, scheduler artifacts, proxy backend artifacts, tool installs, or firewall rules as process configuration.

## Test Mapping

Required test files:

| Path | Coverage |
| --- | --- |
| `apps/gateway/tests/Feature/Http/Api/DoctorRunControllerTest.php` | Gateway doctor API coverage for process family scope and process drift reporting. |
| `apps/gateway/tests/Unit/Services/Processes/ProcessesProbeTest.php` | In-memory probe diff behavior for the processes family (see breakdown below). |
| `apps/gateway/tests/Unit/Services/Doctor/DoctorReportRunnerTest.php` | Node-wide managed runtime inventory dispatch, safe removal of orphaned app containers, and detection and restoration of missing secondary-instance FrankenPHP runtime rows and containers. |

No current E2E test is mapped for process-family doctor coverage.

`DoctorRunControllerTest` covers process-family API scope, process drift
reporting, and the `process.runtime_backend_unavailable` short-circuit.

`ProcessesProbeTest` covers registry configuration, node/instance/workspace
owner validation, same-instance workspace expansion, process manager
availability, and hibernation-aware Docker runtime liveness.
It also covers runtime-unit identity, canonical FrankenPHP and SeaweedFS
process rows, and Docker/Docker Swarm managed service metadata.
WireGuard self-route diagnostics (including unsupported-platform not-applicable
behavior and true supported unhealthy drift), current-placement resolution after
instance moves, missing/extra/drifted runtime artifacts, launchd plist and
loaded-state drift, restart policy drift, runtime environment drift, event
history handling, and exclusion of non-process drift from issue codes are also covered.

`DoctorReportRunnerTest` also covers process selection by current app instance
placement when denormalized `process.node_id` is stale.
