# Workspace Doctor

[Back to Workspace commands.](README.md)

The workspace family doctor implements the
[Family Doctor Implementation Contract](../11_operation/3_doctor/technical/1_doctor.md#family-doctor-implementation-contract).
`key()` returns `workspace`.

`doctor --family=workspace` verifies whether gateway workspace records still
match the workspace facts that make those records usable development contexts
on their effective workspace node. Instance-bound workspaces use their selected
app instance node; app-only legacy workspaces use the parent app's canonical
node. It also detects stale workspace artifacts owned by Orbit with identities
absent from active gateway workspace configuration, so
post-removal cleanup can be repaired without recreating deleted workspace
records.

The workspace family owns these facts:

- gateway-owned workspace records: name, parent app, workspace path, derived
  hostname, PHP version override or inheritance, and lifecycle status;
- workspace source location: the managed workspace path exists on the
  effective workspace node and is allowed by the workspace source driver that
  created it;
- workspace runtime artifacts: workspace FrankenPHP container configuration,
  effective PHP image selection, managed runtime configuration, and filesystem
  ownership required for the workspace environment;
- development workspace security: per-workspace runtime user, filesystem
  permissions, and runtime container isolation for nodes with the
  `app-dev` role, reported as `workspace.security.*` issue keys;
- workspace-owned adoption facts: selected existing workspace paths that can be
  tied to an explicit app and workspace name during `doctor --adopt`.
- stale workspace artifacts owned by Orbit with identities absent from
  active gateway workspace records.

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
3. **Source path:** the workspace path exists on the effective workspace node, is
   usable as the workspace source directory, and is distinct from the parent
   app root. Generic and adapter-owned workspace sources may live outside the
   parent app path.
4. **PHP runtime:** active workspaces have an effective PHP image that can serve
   the workspace runtime on the owning node, and their workspace FrankenPHP
   container endpoint matches gateway workspace configuration. Workspaces still
   in `expected`, `setup-pending`, or `setting_up` lifecycle states do not
   require runtime containers or image availability yet.
5. **Runtime artifacts:** workspace runtime configuration and managed
   filesystem ownership match gateway workspace configuration.
6. **Development workspace security:** workspace runtime isolation is checked
   only for workspaces on `app-dev` nodes. Production app-role targets
   do not select the workspace family.
7. **Adoption hints:** during `doctor --adopt`, an explicitly selected existing
   workspace path may be inspected for compatible workspace facts. `composer.json`
   is the only project file that may provide a PHP version hint, and only for a
   PHP project.
8. **Stale workspace artifacts:** Orbit-owned worktrees, runtime containers, or
   runtime artifacts whose encoded workspace identity is absent from
   active workspace records are reported as orphaned workspace drift.

The workspaces probe may mention related family drift only as a handoff. It
must not duplicate proxy route, process, app, node, tool, or firewall probe
results as workspace-family issue codes.

## Workspace Issue Codes

Each code below corresponds to a specific layer in the workspaces probe.

| Code | Detected when |
| --- | --- |
| `workspace.record_incomplete` | A selected workspace record lacks name, parent app reference, workspace path, derived hostname, effective PHP version, or required lifecycle fields. |
| `workspace.parent_app_invalid` | The workspace record points at a missing app, unauthorized app, or app that cannot own workspaces. |
| `workspace.path_missing` | The configured workspace path does not exist on the effective workspace node. |
| `workspace.path_unusable` | The configured workspace path exists but cannot be read, entered, or managed by Orbit. |
| `workspace.path_outside_policy` | A generic workspace path equals the parent app root instead of a distinct workspace path. Adapter-owned paths are checked against their adapter metadata instead of this generic policy. |
| `workspace.php_version_unavailable` | An active workspace's effective PHP version cannot serve the workspace runtime on the owning node. |
| `workspace.runtime_container_missing` | The workspace runtime container or endpoint is absent. |
| `workspace.runtime_container_mismatch` | The workspace runtime container or endpoint differs from gateway workspace configuration. |
| `workspace.runtime_config_missing` | Managed workspace runtime configuration required by Orbit is absent. |
| `workspace.runtime_config_mismatch` | Managed workspace runtime configuration exists but differs from gateway workspace configuration. |
| `workspace.security.system_user` | A development workspace is missing its expected runtime user or group. |
| `workspace.security.fs_permissions` | Workspace filesystem ownership or permissions are weaker than workspace runtime policy. |
| `workspace.security.runtime_container_isolation` | The workspace runtime container lacks required isolation settings. |
| `workspace.artifact_extra` | An Orbit-owned workspace worktree, runtime container, or runtime artifact exists on a node with an app role without matching active workspace configuration. |
| `workspace.unregistered_path` | During an explicit adoption scope, a selected workspace path exists without a matching gateway workspace record. |
| `workspace.php_hint_unsupported` | During adoption, `composer.json` provides a PHP version hint that Orbit does not support. |

## Workspace Fix Map

The table below shows what `doctor --restore` does for each fixable code.

| Code | `doctor --restore` behavior |
| --- | --- |
| `workspace.path_missing` | Recreate the workspace path only when gateway workspace configuration has enough source information and the repair will not overwrite unrelated files. |
| `workspace.runtime_container_missing` | Recreate or restart the workspace runtime container from gateway workspace configuration and the selected PHP image. |
| `workspace.runtime_container_mismatch` | Recreate the workspace runtime container to match gateway workspace configuration. |
| `workspace.runtime_config_missing` | Reinstall managed workspace runtime configuration from gateway workspace configuration. |
| `workspace.runtime_config_mismatch` | Rewrite managed workspace runtime configuration to match gateway workspace configuration. |
| `workspace.security.system_user` | Restore the development workspace runtime user and group when workspace configuration is complete. |
| `workspace.security.fs_permissions` | Reapply development workspace ownership and permission policy. |
| `workspace.security.runtime_container_isolation` | Recreate the workspace runtime container with required isolation settings. |
| `workspace.artifact_extra` | Remove the stale Orbit-owned workspace artifact when its encoded identity is absent from active workspace configuration. |

`doctor --restore` does not handle `workspace.record_incomplete`,
`workspace.parent_app_invalid`, `workspace.path_unusable`,
`workspace.path_outside_policy`, `workspace.php_version_unavailable`,
`workspace.unregistered_path`, or `workspace.php_hint_unsupported`.

Unsupported PHP versions and invalid parent app records remain explicit command
or operator work. Failed setup and teardown runs are visible through
`workspace:history` and `workspace:log`; doctor verifies current workspace
reality and does not rewrite past runs. Workspace doctor never creates
parent apps, changes workspace names, moves a workspace to another app, edits
setup or teardown step definitions, edits workspace-owned proxy routes,
edits inherited runtime units, or changes node reachability.

Workspace doctor is a development app-role surface. `app-prod` targets
reject `doctor --family=workspace` before probes with
`family_not_in_role_scope`.

## Workspace Adopt Map

The table below shows what `doctor --adopt` does for each adoptable code.

| Code | `doctor --adopt` behavior |
| --- | --- |
| `workspace.unregistered_path` | Create workspace configuration only when the selected scope provides an explicit app, workspace name, and path, and the observed path is compatible with `workspace:setup` adoption rules. |
| `workspace.runtime_container_mismatch` | Update workspace runtime configuration only when the observed runtime container metadata proves the same app and workspace identity and the observed values are supported. |
| `workspace.runtime_config_mismatch` | Update workspace runtime configuration only when the observed runtime configuration proves the same app and workspace identity and the observed values are supported. |

`doctor --adopt` does not scan arbitrary filesystem paths for workspaces, adopt unknown
virtual hosts, adopt proxy route backend artifacts as workspace configuration, infer
database ownership, read `.php-version`, or adopt process/schedule/tool/firewall
artifacts as workspace facts.

## Test Mapping

Required test files:

| Path | Coverage |
| --- | --- |
| `apps/gateway/tests/Feature/Http/Api/DoctorRunControllerTest.php` | Gateway doctor API coverage for workspace family scope and workspace drift reporting. |
| `apps/gateway/tests/Unit/Services/Workspaces/WorkspacesProbeTest.php` | In-memory workspace probe diff behavior; see detail below. |
| `apps/e2e/tests/Feature/Commands/Ephemeral/WorkspacesDoctorTest.php` | Real workspace doctor coverage against registered workspaces. |

No current E2E test is mapped for workspace-family fix or adopt coverage.

`WorkspacesProbeTest.php` covers registry configuration, parent app eligibility,
source path, workspace path policy, PHP runtime, runtime container configuration,
runtime configuration, stale Orbit-owned workspace artifacts, adoption hints,
`.php-version` exclusion, and exclusion of proxy route/process/app/node/tool/firewall
drift from workspace issue codes.
