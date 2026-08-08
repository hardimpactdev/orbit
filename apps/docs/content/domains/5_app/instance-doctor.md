# Instance Doctor

[Back to App and instance commands.](README.md)

The instance family doctor implements the
[Family Doctor Implementation Contract](../11_operation/3_doctor/technical/1_doctor.md#family-doctor-implementation-contract).
The public family key is `instance`; private compatibility internals may still use `app`.

`doctor --family=instance` verifies whether selected instance records match the
facts that make those concrete placements runnable on their serving nodes.
Apps own identity and shared product configuration, not node, path,
root, domain, adoption, runtime placement, or binding defaults. Instance Doctor
therefore never probes an app as live placement. It also detects stale
managed instance configuration whose dotted identity is absent from active
gateway instance configuration. Concrete runtime units and containers are
owned by the process family.

## Selection and scope

Selection resolves instance doctor to concrete placements before any probe begins.

- `--instance=<app.instance>` selects one concrete instance. A targeted run
  always requires the dotted name; a bare app name or hostname fails before
  probing with `error.code=validation_failed`, `error.meta.field=instance`, and
  `error.meta.reason=instance_required`.
- The selected instance determines the serving node, source path, document
  root, domain, adoption state, runtime policy, and bindings. An explicit
  `--node` must name the same serving node.
- Without `--instance`, a node-scoped instance-family run enumerates the concrete Orbit
  instances served by that node and checks each independently. It does not
  synthesize a runtime target from an app's default fields.

The instance family owns these facts:

- app identity and shared product configuration only; every observed
  placement, runtime, adoption, and binding fact below is scoped to a selected
  instance;
- instance records owned by the gateway: instance name, driver, driver
  configuration, required PHP extensions, instance env values, and related
  instance database targets;
- instance source location: the selected instance path exists on its serving node and
  the configured document root exists inside that path;
- app runtime intent: the selected instance's effective PHP/image selection,
  production instance runtime user and ownership policy, managed app runtime configuration,
  and runtime readiness for the configured PHP image;
- production app runtime security: instance runtime user isolation that applies only in
  production, filesystem permissions, release mount boundaries, and runtime
  container isolation,
  reported as `instance.security.*` issue keys inside the instance family;
- production instance health: production instance health checks plus deployment pipeline
  validity and latest deployment status recorded on each concrete instance;
- adoption facts recorded on the instance by `instance:register`; Doctor never
  infers or creates an app or instance from an observed path;
- stale managed instance runtime configuration with dotted identities absent
  from active gateway instance records.

Node reachability belongs to the node family. Instance-owned proxy routes belong to
`proxy`. Workspace artifacts belong to `workspace`. FrankenPHP runtime units,
containers, lifecycle, and logs for apps and instances belong to `process`.
Instance schedules belong to `schedule`. Tool installation
and firewall policy belong to `tool` and `firewall_rule`.

## Probe Layers

The instance probe reads gateway app and instance records and checks these layers:

1. **Registry configuration:** every selected instance belongs to a valid
   app, has one supported driver, and has complete driver placement.
2. **Serving node eligibility:** an Orbit instance's configured node resolves
   to an active app-host node. Node runtime reachability is not diagnosed here;
   unreachable nodes are reported by the node family.
3. **Source path:** the selected instance path exists on its serving node and
   is usable as the instance source directory.
4. **Document root:** the configured document root exists inside the instance path
   and is not outside the instance path.
5. **PHP runtime:** the configured PHP image can serve the selected instance
   runtime on its serving node. Concrete FrankenPHP unit presence and shape are process-family
   checks.
6. **Runtime artifacts:** managed app runtime configuration and filesystem
   ownership match selected instance configuration and the production policy that
   applies when its serving node carries `app-prod`.
7. **Production readiness:** production instances have required production runtime
   policy, instance runtime user isolation where configured, deployment pipeline configuration,
   configured health checks, and no unsuccessful or stale latest deployment
   run.
8. **Production runtime security:** instances on nodes with the `app-prod`
   role satisfy the instance-owned security posture. These findings use
   `instance.security.*` keys and do not depend on workspaces.
9. **Instance runtime targets:** Orbit instances whose driver
   configuration places them on the selected node are probed for instance-owned
   PHP/image requirements, managed config such as
   `~/.config/orbit/apps/hauser-nmbp.ini`, and instance-scoped runtime
   configuration.
10. **Stale instance configuration:** Managed runtime config whose encoded
   dotted instance identity is absent from active instance records is
   reported as instance drift. Ambiguous or app-only identities are not
   adopted or bound automatically. Stale containers are process-family drift.

The instance probe may mention related family drift only as a handoff. It must not
duplicate proxy route, workspace, process, schedule, tool, firewall, or node
probe results as instance-family issue codes.

## Instance Issue Codes

Every code below is registered in the Doctor issue catalog owned by this
family, with an explicit public disposition (`genuine_drift`,
`blocked_inspection`, `invalid_intent`, or `runtime_incident`). Genuine drift
codes declare a restore action in the Fix Map and catalog; non-genuine
dispositions are never auto-repaired as if they were restorable drift. See the
global
[doctor technical contract](../11_operation/3_doctor/technical/1_doctor.md#issue-dispositions)
for disposition semantics.

Each code below corresponds to a specific layer in the instance probe.

| Code | Detected when |
| --- | --- |
| `instance.record_incomplete` | A selected instance lacks required identity, driver, or placement fields. |
| `instance.serving_node_invalid` | The selected Orbit instance points at a missing serving node or a node that is not active. |
| `instance.path_missing` | The selected instance path does not exist on its serving node. |
| `instance.path_unusable` | The configured instance path exists but cannot be read, entered, or managed by Orbit. |
| `instance.root_missing` | The configured document root does not exist inside the instance path. |
| `instance.root_outside_path` | The configured document root resolves outside the instance path. |
| `instance.php_version_unavailable` | The selected instance's effective PHP version cannot serve its runtime on the serving node. |
| `instance.runtime_config_missing` | Managed runtime configuration required by the selected instance is absent. |
| `instance.runtime_config_mismatch` | Managed instance runtime configuration differs from the selected instance configuration. |
| `instance.runtime_config_extra` | An Orbit-owned runtime artifact has a dotted identity with no matching active instance. |
| `instance.runtime_config_probe_failed` | The managed runtime configuration directory could not be reliably scanned for orphan artifacts. Reported once per node so stale `instance.runtime_config_extra` is not hidden. |
| `instance.runtime_extensions_unverifiable` | Required PHP extensions are configured for an Orbit instance, but the FrankenPHP runtime cannot be queried. |
| `instance.runtime_extension_missing` | Required PHP extensions are configured for an Orbit instance and one or more are absent from the running FrankenPHP runtime. |
| `instance.production_user_missing` | A production app that requires app-user isolation has no matching path-derived instance runtime user or ownership policy. |
| `instance.production_user_mismatch` | Production instance runtime user or ownership policy differs from gateway instance configuration. |
| `instance.security.system_user` | A production app is missing its expected path-derived runtime user or group, or that user has forbidden privileges such as Docker group membership. |
| `instance.security.fs_permissions` | Production app filesystem ownership, permissions, symlink targets, or release mount paths are weaker than app runtime policy. |
| `instance.security.runtime_container_isolation` | The production app runtime container lacks required isolation settings, such as no Docker socket, no Docker group access, internal-only port `8080`, and the app/release bind mount boundary. |
| `instance.production_health_unhealthy` | A configured production instance health check fails after app runtime is reachable. |
| `instance.deployment_pipeline_invalid` | A production instance's deployment pipeline is incomplete or references unsupported deployment behavior. |
| `instance.latest_deployment_failed` | The latest deployment run for a production instance finished as `failed` or `cancelled` and no newer successful deployment exists. |
| `instance.deployment_run_stuck` | The latest deployment run for a production instance is still `running` after the deployment staleness threshold. |
| `instance.unregistered_path` | An explicitly inspected path is not represented by a named instance; the finding is a handoff to `instance:register`, never an automatic adoption candidate. |

## Instance Fix Map

The table below shows what `doctor --restore` does for each fixable code.

| Code | `doctor --restore` behavior |
| --- | --- |
| `instance.runtime_config_missing` | Reinstall managed runtime configuration from the selected instance configuration. |
| `instance.runtime_config_mismatch` | Rewrite managed runtime configuration to match the selected instance. |
| `instance.runtime_config_extra` | Remove the stale Orbit-owned artifact only when its encoded dotted identity is unambiguous and absent from active instance configuration. |
| `instance.security.system_user` | Restore the production app runtime user and group when the instance configuration is complete. |
| `instance.security.fs_permissions` | Reapply production app ownership, permission, symlink, and release mount policy. |

`doctor --restore` does not handle `instance.record_incomplete`, `instance.serving_node_invalid`,
`instance.path_missing`, `instance.path_unusable`, `instance.root_missing`,
`instance.root_outside_path`, `instance.php_version_unavailable`,
`instance.runtime_config_probe_failed`,
`instance.runtime_extensions_unverifiable`, `instance.runtime_extension_missing`,
`instance.unregistered_path`,
`instance.security.runtime_container_isolation`,
`instance.production_user_missing`, `instance.production_user_mismatch`,
`instance.production_health_unhealthy`, `instance.deployment_pipeline_invalid`,
`instance.latest_deployment_failed`, or `instance.deployment_run_stuck`.
Production user findings are `runtime_incident` until a safe AppsFixer restorer
exists; operators repair them with explicit instance/user commands.
`instance.runtime_extensions_unverifiable` and `instance.runtime_extension_missing`
are diagnostic-only until an explicit extension repair command owns them.
`instance.unregistered_path` is adopt-only through explicit `instance:register`.

`instance.runtime_config_probe_failed` is Unverifiable diagnostic-only drift.
Doctor must not present it as restorable; re-run verify after the underlying
permission or transport issue is fixed.

`instance.security.runtime_container_isolation` remains an instance-owned security
diagnostic, but concrete repair is handed to
`doctor --family=process --restore` through the app's canonical FrankenPHP
process row. Missing source, invalid roots, unsupported PHP versions, unhealthy application
code, deployment policy changes for an instance, failed deployment recovery, stuck deployment
triage, and preference changes remain explicit app or deploy commands
or operator work. Instance doctor never creates a new app or instance record, moves an app to
another node, changes an app name, edits instance-owned proxy routes, edits
workspace/process/schedule configuration, runs deployments, clears deployment history,
or changes node reachability.

## Instance Adopt Map

Instance Doctor never creates an app, chooses an instance name, or binds an
observed path to an instance automatically. `instance.unregistered_path` always hands off
to an explicit `instance:register <app.instance> --node=<node> --path=<path>`
invocation. The caller must name both the app and the
instance. If more than one app, instance, node, path, domain, or runtime binding
could match, Doctor stops with `validation_failed` and
`error.meta.reason=ambiguous_instance`; it never chooses a candidate.

`doctor --adopt` may update supported observed values only for an already
selected dotted instance when the managed artifact encodes that exact
identity. It does not adopt unknown virtual hosts, infer database ownership,
adopt deployment outcomes, or adopt workspace/process/schedule artifacts as
instance facts.

## Deployment Health Recovery

Deployment health issues are observable instance health facts, not convergence drift
that `doctor --restore` or `doctor --adopt` can resolve.

- `instance.latest_deployment_failed` points operators to
  [`deploy:log`](../10_deploy/6_deploy-log/deploy-log.md) for the failed run
  and [`deploy:run`](../10_deploy/4_deploy-run/deploy-run.md) after the
  underlying cause is fixed.
- `instance.deployment_run_stuck` points operators to
  [`deploy:history`](../10_deploy/5_deploy-history/deploy-history.md) and
  [`deploy:log`](../10_deploy/6_deploy-log/deploy-log.md) to inspect the run.

## Test Mapping

Required test files:

| Path | Coverage |
| --- | --- |
| `apps/gateway/tests/Feature/Http/Api/DoctorRunControllerTest.php` | Gateway doctor API coverage for instance family scope, instance drift reporting, and related family behavior. |
| `apps/gateway/tests/Unit/Services/Apps/AppsProbeTest.php` | In-memory instance probe behavior, including proof that concrete runtime unit drift is handed to process. |
| `apps/gateway/tests/Unit/Services/Apps/AppsFixerTest.php` | Managed app config/security repair and refusal to repair process-owned units. |
| `apps/gateway/tests/Unit/Services/Doctor/DoctorReportRunnerTest.php` | Instance-family runner coverage for instance-owned configuration only. |

No current E2E test is mapped for instance-family doctor coverage.

`AppsProbeTest` covers registry configuration, serving-node eligibility, source
path, document root, PHP runtime, managed runtime configuration, the
configuration for instance runtime targets, production
user policy, and production health. It also covers deployment pipeline
configuration per instance, latest deployment status, stale artifacts, and exclusion of
proxy/workspace/process/schedule/node/tool/firewall drift.
