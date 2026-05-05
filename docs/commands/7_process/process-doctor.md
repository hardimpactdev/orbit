# Process Doctor

[Back to Process commands.](README.md)

`doctor --family=process` verifies whether gateway process definitions still
match the runtime-unit artifacts that make those definitions executable on their
owning app nodes.

The process family owns these facts:

- gateway-owned process definitions: app, name, command, restart policy, and
  crash-notification policy;
- derived runtime-unit identity for the main app instance and every workspace:
  `orbit_<app>_<workspace|main>_<process>`;
- Supervisor programs rendered from process, app, workspace, and node intent,
  including command, working directory, restart policy, and runtime
  environment;
- Orbit-managed lifecycle event notifier material required to record runtime
  `crashed` events from app-node units whose process definitions require crash
  event reporting;
- stale Orbit-owned Supervisor programs whose identity no longer maps to an
  active app, workspace, or process definition.

Node reachability belongs to `node`. App source, PHP runtime, and app-owned
runtime configuration belong to `app`. Workspace source directories and setup
state belong to `workspace`. Proxy routes, schedules, tools, and firewall
rules remain outside the process family.

## Probe Layers

The processes probe reads gateway process definitions and checks these layers:

1. **Registry intent:** every selected process definition has a valid app
   reference, process name, command, restart policy, and crash-notification
   policy.
2. **Owning app and workspace expansion:** the owning app resolves to an
   active app record and the expected runtime contexts are the main app
   instance plus every active workspace for that app.
3. **Runtime backend availability:** the node has Supervisor installed, the
   `supervisord` daemon is reachable, and its control socket is responsive.
   When this layer fails, the probe stops and reports
   `process.runtime_backend_unavailable` instead of cascading downstream
   layer failures.
4. **Runtime-unit identity:** each expected runtime context maps to exactly
   one Orbit-owned runtime unit name using
   `orbit_<app>_<workspace|main>_<process>`.
5. **Supervisor program presence:** each expected runtime unit exists as a
   Supervisor program when the runtime backend is reachable.
6. **Supervisor program shape:** rendered command, working directory,
   restart policy, user, and runtime environment match gateway intent.
7. **Lifecycle notifier material:** Orbit-managed crash event hooks, gateway
   endpoint material, and gateway CA material required to write durable
   `crashed` lifecycle events exist and match the selected process
   definitions.
8. **Stale runtime units:** Orbit-owned Supervisor programs whose encoded
   app, workspace, or process identity no longer maps to active gateway
   intent are reported as process-family drift.

Latest lifecycle events are history, not desired state. The processes probe may
read them to explain observed runtime state, but it does not repair or adopt
event history.

## Process Issue Codes

| Code | Detected when |
| --- | --- |
| `process.record_incomplete` | A selected process definition lacks app, name, command, restart policy, or crash-notification policy. |
| `process.owner_app_invalid` | The process definition points at a missing app, unauthorized app, or app whose owning node is not an active app node. |
| `process.runtime_context_unresolved` | The expected main app or workspace runtime context cannot be derived from gateway intent. |
| `process.runtime_backend_unavailable` | Supervisor is not installed, `supervisord` is not running, or its control socket is not reachable. Downstream runtime-unit checks are skipped while this code is active. |
| `process.runtime_unit_missing` | An expected Orbit-owned runtime unit has no corresponding Supervisor program. |
| `process.runtime_unit_extra` | An Orbit-owned Supervisor program exists without matching active app, workspace, and process intent. |
| `process.runtime_unit_mismatch` | The Supervisor program command, working directory, user, or program name differs from gateway process intent. |
| `process.restart_policy_mismatch` | The rendered Supervisor restart policy differs from the process definition. |
| `process.runtime_environment_mismatch` | The rendered Supervisor program environment differs from the runtime unit environment contract. |
| `process.event_notifier_missing` | Runtime lifecycle event notifier material is absent for a runtime unit that should emit crash events. |
| `process.event_notifier_mismatch` | Runtime lifecycle event notifier material exists but points at the wrong gateway endpoint, app, workspace, process, or event intake identity. |

## Process Fix Map

| Code | `--fix` behavior |
| --- | --- |
| `process.runtime_backend_unavailable` | No `--fix` action. Runtime backend installation and recovery belong to `tool` family doctor and node operations. Process doctor reports the dependency and does not attempt to install Supervisor. |
| `process.runtime_unit_missing` | Re-render and reload the missing Supervisor program from gateway app, workspace, and process intent. |
| `process.runtime_unit_extra` | Stop and remove the stale Orbit-owned Supervisor program whose identity no longer maps to active gateway app, workspace, and process intent. |
| `process.runtime_unit_mismatch` | Rewrite the Supervisor program from gateway app, workspace, and process intent. |
| `process.restart_policy_mismatch` | Rewrite the Supervisor restart policy from the process definition. |
| `process.runtime_environment_mismatch` | Rewrite the Supervisor program environment from the runtime unit environment contract. |
| `process.event_notifier_missing` | Reinstall Orbit-managed lifecycle event notifier material for the selected runtime unit. |
| `process.event_notifier_mismatch` | Rewrite lifecycle event notifier material to match gateway intent and the current gateway event intake identity. |

`--fix` does not handle `process.record_incomplete`,
`process.owner_app_invalid`, `process.runtime_context_unresolved`, or
`process.runtime_backend_unavailable`.

Missing or invalid process definitions and app ownership problems remain
explicit process, app, or workspace command work. Process doctor never creates
process definitions, changes process names, edits app or workspace records, or
adopts arbitrary runtime-unit files as gateway intent.

## Process Adopt Map

| Code | `--adopt` behavior |
| --- | --- |
| `process.runtime_backend_unavailable` | No adoption action. |
| `process.runtime_unit_extra` | No adoption action. Supervisor programs are derived artifacts and must not create process intent. |
| `process.runtime_unit_mismatch` | No adoption action. Update process intent with `process:edit` when the observed runtime command should become intent. |
| `process.restart_policy_mismatch` | No adoption action. Update restart policy with `process:edit` when the observed policy should become intent. |
| `process.runtime_environment_mismatch` | No adoption action. Runtime environment is derived from app, workspace, and node intent. |
| `process.event_notifier_mismatch` | No adoption action. Event notifier material is derived from gateway-owned process intent and gateway event intake identity. |

Process doctor does not adopt Supervisor programs, logs, event history, app
source, workspace source, scheduler artifacts, proxy backend artifacts, tool
installs, or firewall rules as process intent.

## Test Mapping

Required test files:

| Path | Coverage |
| --- | --- |
| `tests/Feature/Doctor/ProcessesFamilyDoctorContractTest.php` | Processes-family dispatch, probe-layer selection, process issue codes, process fix map, denied process adopt cases, scope filtering as it affects process probes, and assertion that `process.runtime_backend_unavailable` short-circuits downstream layer checks. |
| `tests/Unit/Services/Processes/ProcessesProbeTest.php` | In-memory process probe diff behavior for registry intent, app and workspace expansion, runtime backend availability, runtime-unit identity, missing programs, extra programs, program content drift, restart policy drift, runtime environment drift, event notifier drift, and exclusion of app, workspace, node, proxy route, schedule, tool, and firewall drift from process issue codes. |
| `tests/E2E/Read/ProcessesDoctorTest.php` | Real read-only `doctor --family=process --json` on a topology with Supervisor-rendered process runtime units. Docker-eligible. |
| `tests/E2E/Ephemeral/ProcessesDoctorFixTest.php` | Real `doctor --family=process --fix` repair of missing or divergent Supervisor programs and lifecycle event notifier material. Docker-eligible. |
