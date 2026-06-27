# App Doctor

[Back to App commands.](README.md)

The app family doctor implements the
[Family Doctor Implementation Contract](../11_operation/3_doctor/technical/1_doctor.md#family-doctor-implementation-contract).
`key()` returns `app`.

`doctor --family=app` verifies whether gateway app records still match the
app facts that make those records runnable on their owning nodes. It also
detects stale runtime artifacts owned by Orbit with identities absent from
active gateway app configuration, so post-removal cleanup can be repaired without
recreating deleted app records.

The app family owns these facts:

- gateway-owned app records: name, default owning node, app path, document root,
  PHP version, production policy, deployment pipeline configuration, and the
  agent IDE default set at the app level;
- app instance records owned by the gateway: instance name, driver, driver
  configuration, required PHP extensions, instance env values, and related
  instance database targets;
- app source location: the managed app path exists on the owning node and
  the configured document root exists inside that path;
- app runtime artifacts: app FrankenPHP runtime configuration, production app
  user and ownership policy for production apps, managed app runtime
  configuration, and runtime readiness for the configured PHP image;
- production app runtime security: app user isolation that applies only in
  production, filesystem permissions, release mount boundaries, and runtime
  container isolation,
  reported as `app.security.*` issue keys inside the app family;
- production app health: production app health checks, deployment pipeline
  validity, and latest deployment status recorded as app-owned gateway history;
- app-owned adoption facts: selected existing app paths that can be tied to an
  explicit app name and node during `doctor --adopt`. During adoption,
  `composer.json` is the only project file Orbit may inspect for PHP-version
  hints, and only when the app path is a PHP project. Orbit must not read
  `.php-version`, `package.json`, or other project files for app adoption
  hints.
- stale runtime containers and runtime artifacts owned by Orbit with identities
  absent from active gateway app records.

Node reachability belongs to the node family. App-owned proxy routes belong to
`proxy`. Workspace artifacts belong to `workspace`. App process units
belong to `process`. App schedules belong to `schedule`. Tool installation
and firewall policy belong to `tool` and `firewall_rule`.

## Probe Layers

The apps probe reads gateway app records and checks these layers:

1. **Registry configuration:** every selected app record has a valid name,
   owning node reference, app path, document root, PHP version, and lifecycle
   fields required by the app model.
2. **Owning node eligibility:** the owning node reference resolves to an active
   node in gateway node configuration. Node runtime reachability is not diagnosed
   here; unreachable nodes are reported by the node family.
3. **Source path:** the app path exists on the owning node and is usable as
   the app source directory.
4. **Document root:** the configured document root exists inside the app path
   and is not outside the app path.
5. **PHP runtime:** the configured PHP image can serve the app runtime on the
   owning node, and the app FrankenPHP runtime endpoint matches gateway app
   configuration.
6. **Runtime artifacts:** managed app runtime configuration and filesystem
   ownership match gateway app configuration and the production policy that
   applies when the owning node carries `app-prod`.
7. **Production readiness:** production apps have required production runtime
   policy, app user isolation where configured, deployment pipeline configuration,
   configured health checks, and no unsuccessful or stale latest deployment
   run.
8. **Production runtime security:** apps on nodes with the `app-prod`
   role satisfy the app-owned security posture. These findings use
   `app.security.*` keys and do not depend on workspaces.
9. **App agent IDE default:** a configured agent IDE default set at the app level must point at a supported adapter.
10. **Stale app artifacts:** App runtime containers or runtime artifacts owned by Orbit whose
   encoded app identity is absent from active app records are reported as
   orphaned app drift.

The apps probe may mention related family drift only as a handoff. It must not
duplicate proxy route, workspace, process, schedule, tool, firewall, or node
probe results as app-family issue codes.

## App Issue Codes

Each code below corresponds to a specific layer in the apps probe.

| Code | Detected when |
| --- | --- |
| `app.record_incomplete` | A selected app record lacks name, owning node reference, app path, document root, PHP version, or required lifecycle fields. |
| `app.owner_node_invalid` | The app record points at a missing node, unauthorized node, or node that is not an active node. |
| `app.path_missing` | The configured app path does not exist on the owning node. |
| `app.path_unusable` | The configured app path exists but cannot be read, entered, or managed by Orbit. |
| `app.root_missing` | The configured document root does not exist inside the app path. |
| `app.root_outside_path` | The configured document root resolves outside the app path. |
| `app.php_version_unavailable` | The app's configured PHP version cannot serve the app runtime on the owning node. |
| `app.runtime_container_missing` | The app's FrankenPHP runtime container or endpoint is absent. |
| `app.runtime_container_mismatch` | The app's FrankenPHP runtime container or endpoint differs from gateway app configuration. |
| `app.runtime_config_missing` | Managed app runtime configuration required by Orbit is absent. |
| `app.runtime_config_mismatch` | Managed app runtime configuration exists but differs from gateway app configuration. |
| `app.runtime_container_extra` | An Orbit-owned app runtime container exists on a node with an app role without matching active app configuration. |
| `app.runtime_config_extra` | An Orbit-owned app runtime artifact exists on a node with an app role without matching active app configuration. |
| `app.runtime_config_probe_failed` | The managed runtime configuration directory could not be reliably scanned for orphan artifacts. Reported once per node so stale `app.runtime_config_extra` is not hidden. |
| `app.runtime_container_probe_failed` | The node-wide app runtime container scan failed. Reported once per node so stale `app.runtime_container_extra` is not hidden. |
| `app.runtime_extensions_unverifiable` | Required PHP extensions are configured for an Orbit app instance, but the FrankenPHP runtime cannot be queried. |
| `app.runtime_extension_missing` | Required PHP extensions are configured for an Orbit app instance and one or more are absent from the running FrankenPHP runtime. |
| `app.production_user_missing` | A production app that requires app-user isolation has no matching path-derived app user or ownership policy. |
| `app.production_user_mismatch` | Production app user, ownership, or runtime container identity differs from gateway app configuration. |
| `app.security.system_user` | A production app is missing its expected path-derived runtime user or group, or that user has forbidden privileges such as Docker group membership. |
| `app.security.fs_permissions` | Production app filesystem ownership, permissions, symlink targets, or release mount paths are weaker than app runtime policy. |
| `app.security.runtime_container_isolation` | The production app runtime container lacks required isolation settings, such as no Docker socket, no Docker group access, internal-only port `8080`, and the app/release bind mount boundary. |
| `app.production_health_unhealthy` | A configured production app health check fails after app runtime is reachable. |
| `app.deployment_pipeline_invalid` | Production deployment pipeline configuration is incomplete or references unsupported deployment behavior. |
| `app.latest_deployment_failed` | The latest deployment run for a production app finished as `failed` or `cancelled` and no newer successful deployment exists. |
| `app.deployment_run_stuck` | The latest deployment run for a production app is still `running` after the deployment staleness threshold. |
| `app.agent_ide_default_invalid` | The app-level agent IDE default points at a missing or unsupported adapter. |
| `app.unregistered_path` | During an explicit adoption scope, a selected app path exists on a node with an app role without a matching gateway app record. |

## App Fix Map

The table below shows what `doctor --restore` does for each fixable code.

| Code | `doctor --restore` behavior |
| --- | --- |
| `app.runtime_container_missing` | Recreate or restart the app runtime container from gateway app configuration and the selected PHP image. |
| `app.runtime_container_mismatch` | Recreate the app runtime container to match gateway app configuration. |
| `app.runtime_config_missing` | Reinstall managed app runtime configuration from gateway app configuration. |
| `app.runtime_config_mismatch` | Rewrite managed app runtime configuration to match gateway app configuration. |
| `app.runtime_container_extra` | Remove the stale Orbit-owned app runtime container when its encoded identity is absent from active app configuration. |
| `app.runtime_config_extra` | Remove the stale Orbit-owned app runtime artifact when its encoded identity is absent from active app configuration. |
| `app.runtime_config_probe_failed` | Re-probe the directory. The drift clears if the underlying issue resolves; otherwise the action records a failed status with the error. |
| `app.runtime_container_probe_failed` | Re-probe the runtime container scan. Clears if docker is reachable; otherwise the action records a failed status with the underlying error. |
| `app.production_user_missing` | Create or restore the production app user and ownership policy when production configuration is complete. |
| `app.production_user_mismatch` | Re-apply production app user, ownership, and runtime container identity from gateway app configuration. |
| `app.security.system_user` | Restore the production app runtime user and group when the app configuration is complete. |
| `app.security.fs_permissions` | Reapply production app ownership, permission, symlink, and release mount policy. |
| `app.security.runtime_container_isolation` | Recreate the production app runtime container with required isolation settings. |

`doctor --restore` does not handle `app.record_incomplete`, `app.owner_node_invalid`,
`app.path_missing`, `app.path_unusable`, `app.root_missing`,
`app.root_outside_path`, `app.php_version_unavailable`,
`app.production_health_unhealthy`, `app.deployment_pipeline_invalid`,
`app.latest_deployment_failed`, `app.deployment_run_stuck`,
`app.agent_ide_default_invalid`, or `app.unregistered_path`.

Missing source, invalid roots, unsupported PHP versions, unhealthy application
code, deployment policy changes, failed deployment recovery, stuck deployment
triage, and agent IDE preference changes remain explicit app or deploy commands
or operator work. App doctor never creates a new app record, moves an app to
another node, changes an app name, edits app-owned proxy routes, edits
workspace/process/schedule configuration, runs deployments, clears deployment history,
or changes node reachability.

## App Adopt Map

The table below shows what `doctor --adopt` does for each adoptable code.

| Code | `doctor --adopt` behavior |
| --- | --- |
| `app.unregistered_path` | Create app configuration only when the selected scope provides an explicit app name, node, and path, and the observed path is compatible with `app:register` adoption rules. |
| `app.runtime_container_mismatch` | Update app runtime configuration only when the observed runtime container metadata proves the same app identity and the observed values are supported. |
| `app.runtime_config_mismatch` | Update app runtime configuration only when the observed runtime configuration proves the same app identity and the observed values are supported. |

`doctor --adopt` does not scan arbitrary filesystem paths for apps, adopt unknown
virtual hosts, adopt proxy route backend artifacts as app configuration, infer database
ownership, adopt deployment run outcomes, or adopt workspace/process/schedule
artifacts as app facts.

## Deployment Health Recovery

Deployment health issues are observable app health facts, not convergence drift
that `doctor --restore` or `doctor --adopt` can resolve.

- `app.latest_deployment_failed` points operators to
  [`deploy:log`](../10_deploy/6_deploy-log/deploy-log.md) for the failed run
  and [`deploy:run`](../10_deploy/4_deploy-run/deploy-run.md) after the
  underlying cause is fixed.
- `app.deployment_run_stuck` points operators to
  [`deploy:history`](../10_deploy/5_deploy-history/deploy-history.md) and
  [`deploy:log`](../10_deploy/6_deploy-log/deploy-log.md) to inspect the run.

## Test Mapping

Required test files:

| Path | Coverage |
| --- | --- |
| `apps/gateway/tests/Feature/Http/Api/DoctorRunControllerTest.php` | Gateway doctor API coverage for app family scope, app drift reporting, and related family behavior. |
| `apps/gateway/tests/Unit/Services/Apps/AppsProbeTest.php` | In-memory app probe diff behavior (see breakdown below). |

No current E2E test is mapped for app-family doctor coverage.

`AppsProbeTest` covers registry configuration, owning node eligibility, source
path, document root, PHP runtime, runtime container configuration, runtime
configuration, production user policy, and production health. It also covers
deployment pipeline configuration, latest deployment status, agent IDE
defaults, stale artifacts, and exclusion of
proxy/workspace/process/schedule/node/tool/firewall drift.
