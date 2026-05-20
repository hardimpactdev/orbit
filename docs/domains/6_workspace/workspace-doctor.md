# Workspace Doctor

[Back to Workspace commands.](README.md)

`doctor --family=workspace` verifies whether gateway workspace records still
match the workspace facts that make those records usable development contexts
on their parent app's node. It also detects stale workspace artifacts owned by Orbit
whose identity no longer maps to active gateway workspace configuration, so
post-removal cleanup can be repaired without recreating deleted workspace
records.

The workspace family owns these facts:

- gateway-owned workspace records: name, parent app, workspace path, derived
  hostname, PHP version override or inheritance, and lifecycle status;
- workspace source location: the managed workspace path exists on the parent
  app's node and is allowed by the workspace source driver that created it;
- workspace runtime artifacts: workspace PHP-FPM configuration, effective PHP
  version, managed runtime configuration, and filesystem ownership required for
  the workspace environment;
- workspace-owned adoption facts: selected existing workspace paths that can be
  tied to an explicit app and workspace name during `doctor --adopt`.
- stale workspace artifacts owned by Orbit whose identity no longer maps to an
  active gateway workspace record.

A workspace record that points at a missing, unauthorized, or non-workspaceable
parent app is a workspace record issue because the workspace cannot resolve.
Parent app runtime health belongs to the app family. Node reachability belongs
to the node family. Workspace-owned proxy routes belong to `proxy`.
Inherited process runtime units belong to `process`. Tool installation and
firewall policy belong to `tool` and `firewall_rule`. HTTP probe warnings from setup time, such as `workspace.http_probe_unhealthy`, are command outcome
metadata from `workspace:setup`, not doctor issue codes for the workspace family.

## Probe Layers

The workspaces probe reads gateway workspace records and checks these layers:

1. **Registry configuration:** every selected workspace record has a valid name,
   parent app reference, workspace path, derived hostname, effective PHP
   version, and lifecycle fields required by the workspace model.
2. **Parent app eligibility:** the parent app reference resolves to an app
   record that can own workspaces. App runtime health is not diagnosed here;
   app drift is reported by the app family.
3. **Source path:** the workspace path exists on the parent app's node, is
   usable as the workspace source directory, and satisfies source-driver path
   policy. Generic worktrees must stay inside `<app path>/.worktrees/...`.
   Adapter-owned sources such as PolyScope may live outside the parent app
   path when the workspace row records the owning adapter metadata.
4. **PHP runtime:** the effective workspace PHP version can serve the workspace
   runtime on the owning node, and the workspace PHP-FPM endpoint matches
   gateway workspace configuration.
5. **Runtime artifacts:** workspace runtime configuration and managed
   filesystem ownership match gateway workspace configuration.
6. **Adoption hints:** during `doctor --adopt`, an explicitly selected existing
   workspace path may be inspected for compatible workspace facts. `composer.json`
   is the only project file that may provide a PHP version hint, and only for a
   PHP project.
7. **Stale workspace artifacts:** Orbit-owned worktrees, PHP-FPM artifacts, or
   runtime artifacts whose encoded workspace identity no longer maps to an
   active workspace record are reported as orphaned workspace drift.

The workspaces probe may mention related family drift only as a handoff. It
must not duplicate proxy route, process, app, node, tool, or firewall probe
results as workspace-family issue codes.

## Workspace Issue Codes

Each code below corresponds to a specific layer in the workspaces probe.

| Code | Detected when |
| --- | --- |
| `workspace.record_incomplete` | A selected workspace record lacks name, parent app reference, workspace path, derived hostname, effective PHP version, or required lifecycle fields. |
| `workspace.parent_app_invalid` | The workspace record points at a missing app, unauthorized app, or app that cannot own workspaces. |
| `workspace.path_missing` | The configured workspace path does not exist on the parent app's node. |
| `workspace.path_unusable` | The configured workspace path exists but cannot be read, entered, or managed by Orbit. |
| `workspace.path_outside_policy` | A generic workspace path resolves outside the parent app's workspace policy. Adapter-owned paths are checked against their adapter metadata instead of the generic app-root policy. |
| `workspace.php_version_unavailable` | The effective workspace PHP version cannot serve the workspace runtime on the owning node. |
| `workspace.fpm_config_missing` | The workspace PHP-FPM configuration or endpoint is absent. |
| `workspace.fpm_config_mismatch` | The workspace PHP-FPM configuration or endpoint differs from gateway workspace configuration. |
| `workspace.runtime_config_missing` | Managed workspace runtime configuration required by Orbit is absent. |
| `workspace.runtime_config_mismatch` | Managed workspace runtime configuration exists but differs from gateway workspace configuration. |
| `workspace.artifact_extra` | An Orbit-owned workspace worktree, PHP-FPM artifact, or runtime artifact exists on a node with an app role without matching active workspace configuration. |
| `workspace.unregistered_path` | During an explicit adoption scope, a selected workspace path exists without a matching gateway workspace record. |
| `workspace.php_hint_unsupported` | During adoption, `composer.json` provides a PHP version hint that Orbit does not support. |

## Workspace Fix Map

The table below shows what `doctor --restore` does for each fixable code.

| Code | `doctor --restore` behavior |
| --- | --- |
| `workspace.path_missing` | Recreate the workspace path only when gateway workspace configuration has enough source information and the repair will not overwrite unrelated files. |
| `workspace.fpm_config_missing` | Re-render and install the workspace PHP-FPM configuration from gateway workspace configuration. |
| `workspace.fpm_config_mismatch` | Rewrite the workspace PHP-FPM configuration to match gateway workspace configuration. |
| `workspace.runtime_config_missing` | Reinstall managed workspace runtime configuration from gateway workspace configuration. |
| `workspace.runtime_config_mismatch` | Rewrite managed workspace runtime configuration to match gateway workspace configuration. |
| `workspace.artifact_extra` | Remove the stale Orbit-owned workspace artifact when its encoded identity no longer maps to active workspace configuration. |

`doctor --restore` does not handle `workspace.record_incomplete`,
`workspace.parent_app_invalid`, `workspace.path_unusable`,
`workspace.path_outside_policy`, `workspace.php_version_unavailable`,
`workspace.unregistered_path`, or `workspace.php_hint_unsupported`.

Unsupported PHP versions and invalid parent app records remain explicit command
or operator work. Failed setup and teardown runs are visible through
`workspace:history` and `workspace:log`; doctor verifies current workspace
reality instead of rewriting historical runs. Workspace doctor never creates
parent apps, changes workspace names, moves a workspace to another app, edits
setup or teardown step definitions, edits workspace-owned proxy routes,
edits inherited runtime units, or changes node reachability.

## Workspace Adopt Map

The table below shows what `doctor --adopt` does for each adoptable code.

| Code | `doctor --adopt` behavior |
| --- | --- |
| `workspace.unregistered_path` | Create workspace configuration only when the selected scope provides an explicit app, workspace name, and path, and the observed path is compatible with `workspace:setup` adoption rules. |
| `workspace.fpm_config_mismatch` | Update workspace runtime configuration only when the observed PHP-FPM configuration proves the same app and workspace identity and the observed values are supported. |
| `workspace.runtime_config_mismatch` | Update workspace runtime configuration only when the observed runtime configuration proves the same app and workspace identity and the observed values are supported. |

`doctor --adopt` does not scan arbitrary filesystem paths for workspaces, adopt unknown
virtual hosts, adopt proxy route backend artifacts as workspace configuration, infer
database ownership, read `.php-version`, or adopt process/schedule/tool/firewall
artifacts as workspace facts.

## Test Mapping

Required test files:

| Path | Coverage |
| --- | --- |
| `tests/Feature/Doctor/WorkspacesFamilyDoctorContractTest.php` | Workspaces-family dispatch, probe-layer selection, issue codes, fix map, adopt map, denied fix/adopt cases, related-family handoff, and scope filtering. |
| `tests/Unit/Services/Workspaces/WorkspacesProbeTest.php` | In-memory workspace probe diff behavior; see detail below. |
| `tests/E2E/Read/WorkspacesDoctorTest.php` | Real read-only `doctor --family=workspace --json` against registered workspaces. |
| `tests/E2E/Ephemeral/WorkspacesDoctorFixTest.php` | Real `doctor --fix --family=workspace --restore` repair of safe workspace runtime drift. |
| `tests/E2E/Ephemeral/WorkspacesDoctorAdoptTest.php` | Real `doctor --fix --family=workspace --adopt` for compatible selected workspace path adoption and supported runtime configuration adoption. |

`WorkspacesProbeTest.php` covers registry configuration, parent app eligibility,
source path, workspace path policy, PHP runtime, PHP-FPM configuration, runtime
configuration, stale Orbit-owned workspace artifacts, adoption hints,
`.php-version` exclusion, and exclusion of proxy route/process/app/node/tool/firewall
drift from workspace issue codes.
