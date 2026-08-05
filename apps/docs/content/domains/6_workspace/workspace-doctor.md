# Workspace Doctor

[Back to Workspace commands.](README.md)

The workspace family doctor implements the
[Family Doctor Implementation Contract](../11_operation/3_doctor/technical/1_doctor.md#family-doctor-implementation-contract).
`key()` returns `workspace`.

`doctor --family=workspace` verifies whether gateway workspace records still
match the workspace facts that make those records usable development contexts
on their effective app-dev node. Every supported workspace belongs to an
app-dev instance, which selects its effective node. It also detects stale workspace artifacts owned by Orbit with identities
absent from active gateway workspace configuration, so
post-removal cleanup can be repaired without recreating deleted workspace
records.

The workspace family owns these facts:

- gateway-owned workspace records: name, parent project, selected instance,
  workspace path, derived hostname, PHP version override or inheritance, and
  lifecycle status;
- workspace source location: the managed workspace path exists on the
  effective workspace node and is allowed by the workspace source driver that
  created it;
- workspace runtime intent: effective PHP image selection, managed runtime
  configuration, and filesystem
  ownership required for the workspace environment;
- development workspace security: per-workspace runtime user and filesystem
  permissions for nodes with the
  `app-dev` role, reported as `workspace.security.*` issue keys;
- workspace-owned adoption facts: selected existing workspace paths that can be
  tied to an explicit app and workspace name during `doctor --adopt`.
- stale workspace artifacts owned by Orbit with identities absent from
  active gateway workspace records.

A workspace record that points at a missing parent project is a parent-project issue.
A missing or mismatched selected instance, or an instance that does not
resolve to an active `app-dev` node, is an instance issue because the
workspace has no valid apply target. A persisted row owned by an `app-prod`
instance is invalid gateway configuration, but an `app-prod` node or instance
cannot be selected as a workspace-doctor target to inspect or report it.

Parent project runtime health belongs to the instance family. Node reachability belongs
to the node family. Workspace-owned proxy routes belong to `proxy`.
Workspace FrankenPHP runtime units, containers, lifecycle, and logs belong to
`process`. Tool installation and
firewall policy belong to `tool` and `firewall_rule`. HTTP probe warnings from setup time, such as `workspace.http_probe_unhealthy`, are command outcome
metadata from `workspace:setup`, not doctor issue codes for the workspace family.

## Probe Layers

The workspaces probe reads gateway workspace records and checks these layers:

1. **Registry configuration:** every selected workspace record has a valid name,
   parent project reference, non-null instance reference, workspace path,
   derived hostname, effective PHP version, and lifecycle fields required by
   the workspace model.
2. **Parent project eligibility:** the parent project reference resolves to an app
   record that can own workspaces. App runtime health is not diagnosed here;
   app drift is reported by the instance family.
3. **Instance eligibility:** the selected instance belongs to the parent
   app and resolves to an active node with the `app-dev` role. The probe retains
   `workspace.unsupported_for_production` as a defensive invariant when invalid
   gateway configuration enters an otherwise supported development scope; it
   never uses that issue as permission to target an `app-prod` node.
4. **Source path:** the workspace path exists on the effective workspace node, is
   usable as the workspace source directory, and is distinct from the parent
   project root. Workspace sources may live outside the parent project path,
   including external agent worktree directories.
5. **PHP runtime:** active workspaces have an effective PHP image that can serve
   the workspace runtime on the owning node. Concrete FrankenPHP unit presence
   and shape are process-family checks. Workspaces still
   in `expected` or `setup-pending` lifecycle states do not
   require image availability yet.
6. **Runtime artifacts:** workspace runtime configuration and managed
   filesystem ownership match gateway workspace configuration.
7. **Development workspace security:** workspace runtime isolation is checked
   only for workspaces on `app-dev` nodes. Persisted production rows are not a
   supported production doctor scope and never trigger production inspection.
8. **Adoption hints:** during `doctor --adopt`, an explicitly selected existing
   workspace path may be inspected for compatible workspace facts. `composer.json`
   is the only project file that may provide a PHP version hint, and only for a
   PHP project.
9. **Stale workspace artifacts:** Orbit-owned worktrees or managed workspace
   configuration whose identity is absent from active workspace records are
   reported as workspace drift. Stale runtime units are process-family drift.

The workspaces probe may mention related family drift only as a handoff. It
must not duplicate proxy route, process, app, node, tool, or firewall probe
results as workspace-family issue codes.

## Workspace Issue Codes

Every code below is registered in the Doctor issue catalog owned by this
family, with an explicit public disposition (`genuine_drift`,
`blocked_inspection`, `invalid_intent`, or `runtime_incident`). Genuine drift
codes declare a restore action in the Fix Map and catalog; non-genuine
dispositions are never auto-repaired as if they were restorable drift. See the
global
[doctor technical contract](../11_operation/3_doctor/technical/1_doctor.md#issue-dispositions)
for disposition semantics.

Each code below corresponds to a specific layer in the workspaces probe.

| Code | Detected when |
| --- | --- |
| `workspace.record_incomplete` | A selected workspace record lacks name, parent project identity, selected instance identity, workspace path, derived hostname, effective PHP version, or required lifecycle fields. |
| `workspace.parent_project_invalid` | The workspace record points at a missing parent project. |
| `workspace.instance_invalid` | The selected instance is missing, belongs to another app, or does not resolve to an active `app-dev` node for a reason other than production placement. |
| `workspace.unsupported_for_production` | Defensive gateway validation encounters a persisted workspace row belonging to an `app-prod` instance while evaluating a supported development scope. The production node is never probed. |
| `workspace.path_missing` | The configured workspace path does not exist on the effective workspace node. |
| `workspace.path_unusable` | The configured workspace path exists but cannot be read, entered, or managed by Orbit. |
| `workspace.path_outside_policy` | The workspace path equals the parent project root instead of a distinct workspace path. |
| `workspace.php_version_unavailable` | An active workspace's effective PHP version cannot serve the workspace runtime on the owning node. |
| `workspace.runtime_config_missing` | Managed workspace runtime configuration required by Orbit is absent. |
| `workspace.runtime_config_mismatch` | Managed workspace runtime configuration exists but differs from gateway workspace configuration. |
| `workspace.security.system_user` | A development workspace is missing its expected runtime user or group. |
| `workspace.security.fs_permissions` | Workspace filesystem ownership or permissions are weaker than workspace runtime policy. |
| `workspace.artifact_extra` | An Orbit-owned workspace worktree or managed workspace artifact exists without matching active workspace configuration. |
| `workspace.unregistered_path` | During an explicit adoption scope, a selected workspace path exists without a matching gateway workspace record. |
| `workspace.php_hint_unsupported` | During adoption, `composer.json` provides a PHP version hint that Orbit does not support. |

## Workspace Fix Map

Workspace family restore actions are report-only in the current Docker-first
runtime. Catalog dispositions for workspace codes are `runtime_incident`,
`blocked_inspection`, or `invalid_intent` — never `genuine_drift` — so
`doctor --restore` does not auto-fix workspace codes. Operator repair paths
are explicit commands such as `workspace:setup`, `workspace:remove`, or
manual path/registry correction after reading the probe findings.

Failed setup and teardown runs are visible through `workspace:history` and
`workspace:log`; doctor verifies current workspace reality and does not rewrite
past runs. Workspace doctor never creates parent apps, changes workspace
names, moves a workspace to another project, edits setup or teardown step
definitions, edits workspace-owned proxy routes, edits inherited runtime units,
or changes node reachability.

Workspace doctor is a development surface. An explicit `app-prod` node may
not select the workspace family or an explicit workspace scope. The gateway
rejects either request with `family_not_in_node_scope` before probing,
dispatching Agent work, planning a restore, or adopting state. Normal workspace
commands reject production targets with `workspace.unsupported_for_production`
before side effects.

## Workspace Adopt Map

The table below shows what `doctor --adopt` does for each adoptable code.

| Code | `doctor --adopt` behavior |
| --- | --- |
| `workspace.unregistered_path` | Create workspace configuration only when the selected scope provides an explicit app, workspace name, and path, and the observed path is compatible with `workspace:setup` adoption rules. |
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

`WorkspacesProbeTest.php` covers registry configuration, parent project and selected
instance eligibility, source path, workspace path policy, PHP runtime, managed runtime configuration,
stale Orbit-owned workspace artifacts, adoption hints, and handoff of concrete
runtime-unit drift to process. It also covers
`.php-version` exclusion, and exclusion of proxy route/process/app/node/tool/firewall
drift from workspace issue codes.
