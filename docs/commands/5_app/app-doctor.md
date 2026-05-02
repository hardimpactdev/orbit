# App Doctor

[Back to App commands.](README.md)

`doctor --family=app` verifies whether gateway app records still match the
app facts that make those records runnable on their owning app nodes. It also
detects stale Orbit-owned app runtime artifacts whose identity no longer maps
to active gateway app intent, so post-removal cleanup can be repaired without
recreating deleted app records.

The app family owns these facts:

- gateway-owned app records: name, environment, owning app node, app path,
  document root, PHP version, production policy, deployment pipeline intent,
  and app-level agent IDE default;
- app source location: the managed app path exists on the owning app node and
  the configured document root exists inside that path;
- app runtime artifacts: app PHP-FPM configuration, production app user and
  ownership policy for production apps, app environment/runtime configuration,
  and runtime readiness for the configured PHP version;
- production app health: production app health checks, deployment pipeline
  validity, and latest deployment status recorded as app-owned gateway history;
- app-owned adoption facts: selected existing app paths that can be tied to an
  explicit app name and app node during `--adopt`. During adoption,
  `composer.json` is the only project file Orbit may inspect for PHP-version
  hints, and only when the app path is a PHP project. Orbit must not read
  `.php-version`, `package.json`, or other project files for app adoption
  hints.
- stale Orbit-owned app PHP-FPM and runtime artifacts whose identity no longer
  maps to an active gateway app record.

Node reachability belongs to the node family. App-owned proxy routes belong to
`proxy`. Workspace artifacts belong to `workspace`. App process units
belong to `process`. App schedules belong to `schedule`. Tool installation
and firewall policy belong to `tool` and `firewall_rule`.

## Probe Layers

The apps probe reads gateway app records and checks these layers:

1. **Registry intent:** every selected app record has a valid name,
   environment, owning app-node reference, app path, document root, PHP version,
   and lifecycle fields required by the app model.
2. **Owning node eligibility:** the owning node reference resolves to an active
   app node in gateway node intent. Node runtime reachability is not diagnosed
   here; unreachable nodes are reported by the node family.
3. **Source path:** the app path exists on the owning app node and is usable as
   the app source directory.
4. **Document root:** the configured document root exists inside the app path
   and is not outside the app path.
5. **PHP runtime:** the configured PHP version can serve the app runtime on the
   owning app node, and the app PHP-FPM endpoint matches gateway app intent.
6. **Runtime artifacts:** app environment/runtime configuration and managed
   filesystem ownership match the app environment and production policy.
7. **Production readiness:** production apps have required production runtime
   policy, app user isolation where configured, deployment pipeline intent,
   configured health checks, and no unsuccessful or stale latest deployment
   run.
8. **App agent IDE default:** an app-level agent IDE default points at a
   supported adapter when one is configured.
9. **Stale app artifacts:** Orbit-owned app PHP-FPM or runtime artifacts whose
   encoded app identity no longer maps to an active app record are reported as
   orphaned app drift.

The apps probe may mention related family drift only as a handoff. It must not
duplicate proxy route, workspace, process, schedule, tool, firewall, or node
probe results as app-family issue codes.

## App Issue Codes

| Code | Detected when |
| --- | --- |
| `app.record_incomplete` | A selected app record lacks name, environment, owning app-node reference, app path, document root, PHP version, or required lifecycle fields. |
| `app.owner_node_invalid` | The app record points at a missing node, unauthorized node, or node that is not an active app node. |
| `app.path_missing` | The configured app path does not exist on the owning app node. |
| `app.path_unusable` | The configured app path exists but cannot be read, entered, or managed by Orbit. |
| `app.root_missing` | The configured document root does not exist inside the app path. |
| `app.root_outside_path` | The configured document root resolves outside the app path. |
| `app.php_version_unavailable` | The app's configured PHP version cannot serve the app runtime on the owning app node. |
| `app.fpm_config_missing` | The app's PHP-FPM configuration or endpoint is absent. |
| `app.fpm_config_mismatch` | The app's PHP-FPM configuration or endpoint differs from gateway app intent. |
| `app.runtime_config_missing` | Managed app runtime configuration required by Orbit is absent. |
| `app.runtime_config_mismatch` | Managed app runtime configuration exists but differs from gateway app intent. |
| `app.fpm_config_extra` | An Orbit-owned app PHP-FPM artifact exists on an app node without matching active app intent. |
| `app.runtime_config_extra` | An Orbit-owned app runtime artifact exists on an app node without matching active app intent. |
| `app.production_user_missing` | A production app that requires app-user isolation has no matching app user or ownership policy. |
| `app.production_user_mismatch` | Production app user, ownership, or PHP-FPM pool identity differs from gateway app intent. |
| `app.production_health_unhealthy` | A configured production app health check fails after app runtime is reachable. |
| `app.deployment_pipeline_invalid` | Production deployment pipeline intent is incomplete or references unsupported deployment behavior. |
| `app.latest_deployment_failed` | The latest deployment run for a production app finished as `failed` or `cancelled` and no newer successful deployment exists. |
| `app.deployment_run_stuck` | The latest deployment run for a production app is still `running` after the deployment staleness threshold. |
| `app.agent_ide_default_invalid` | The app-level agent IDE default points at a missing or unsupported adapter. |
| `app.unregistered_path` | During an explicit adoption scope, a selected app path exists on an app node without a matching gateway app record. |

## App Fix Map

| Code | `--fix` behavior |
| --- | --- |
| `app.fpm_config_missing` | Re-render and install the app PHP-FPM configuration from gateway app intent. |
| `app.fpm_config_mismatch` | Rewrite the app PHP-FPM configuration to match gateway app intent. |
| `app.runtime_config_missing` | Reinstall managed app runtime configuration from gateway app intent. |
| `app.runtime_config_mismatch` | Rewrite managed app runtime configuration to match gateway app intent. |
| `app.fpm_config_extra` | Remove the stale Orbit-owned app PHP-FPM artifact when its encoded identity no longer maps to active app intent. |
| `app.runtime_config_extra` | Remove the stale Orbit-owned app runtime artifact when its encoded identity no longer maps to active app intent. |
| `app.production_user_missing` | Create or restore the production app user and ownership policy when production intent is complete. |
| `app.production_user_mismatch` | Re-apply production app user, ownership, and PHP-FPM pool identity from gateway app intent. |

`--fix` does not handle `app.record_incomplete`, `app.owner_node_invalid`,
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
workspace/process/schedule intent, runs deployments, clears deployment history,
or changes node reachability.

## App Adopt Map

| Code | `--adopt` behavior |
| --- | --- |
| `app.unregistered_path` | Create app intent only when the selected scope provides an explicit app name, app node, and path, and the observed path is compatible with `app:register` adoption rules. |
| `app.fpm_config_mismatch` | Update app runtime intent only when the observed PHP-FPM configuration proves the same app identity and the observed values are supported. |
| `app.runtime_config_mismatch` | Update app runtime intent only when the observed runtime configuration proves the same app identity and the observed values are supported. |

`--adopt` does not scan arbitrary filesystem paths for apps, adopt unknown
virtual hosts, adopt proxy route backend artifacts as app intent, infer database
ownership, adopt deployment run outcomes, or adopt workspace/process/schedule
artifacts as app facts.

## Deployment Health Recovery

Deployment health issues are observable app health facts, not convergence drift
that `doctor --fix` or `doctor --adopt` can resolve.

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
| `tests/Feature/Doctor/AppsFamilyDoctorContractTest.php` | Apps-family dispatch, app probe-layer selection, app issue codes including deployment health issue codes, app fix map, app adopt map, denied app fix/adopt cases, related-family handoff behavior, and scope filtering as it affects app probes. |
| `tests/Unit/Services/Apps/AppsProbeTest.php` | In-memory app probe diff behavior for registry intent, owning node eligibility, source path, document root, PHP runtime, PHP-FPM configuration, runtime configuration, production user policy, production health, deployment pipeline intent, latest deployment status, app agent IDE defaults, stale Orbit-owned app artifacts, and exclusion of proxy route/workspace/process/schedule/node/tool/firewall drift from apps issue codes. |
| `tests/E2E/Read/AppsDoctorTest.php` | Real read-only `doctor --family=app --json` against registered development and production apps. |
| `tests/E2E/Ephemeral/AppsDoctorFixTest.php` | Real `doctor --family=app --fix` repair of safe app runtime drift. |
| `tests/E2E/Ephemeral/AppsDoctorAdoptTest.php` | Real `doctor --family=app --adopt` for compatible selected app path adoption and supported runtime intent adoption. |
