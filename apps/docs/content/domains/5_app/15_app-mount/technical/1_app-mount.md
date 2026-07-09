# Technical Contract: `orbit app:mount list|add|remove [app]`

[Back to public `app:mount` documentation.](../app-mount.md)

**Owner:** `app`.

**Effects:** `read` for `list`; `write` for `add` and `remove`.

**Prerequisites:**
- The CLI caller can reach the Orbit gateway.
- The authenticated peer has `app:read` on the app's owning node for `list`.
- The authenticated peer has `app:mount` on the app's owning node for `add`
  and `remove`.
- The target app or app instance exists in gateway configuration. `add` and
  `remove` require a target app instance.

## Signature

```bash
orbit app:mount [action] [app] [source] [target] [--read-only] [--read-write] [--json]
```

## Input Contract

This command follows the shared
[Invocation Model](../../../README.md#invocation-model).

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `action` | `{action}` | Always. | Never. | None. | Must be one of `list`, `add`, `remove`. |
| `app` | `[app]` | Always. | Never. | None. | Must resolve to an existing app record or dotted app instance selector such as `hauser.nmbp`. Bare app names are valid only for compatibility `list` reads; `add` and `remove` require a dotted app instance selector. |
| `source` | `[source]` | `action=add`. | `action=list`. | None. | Must be an absolute safe source under the resolved target node's home. |
| `target` | `[target]` | `action=add` and `action=remove`. | `action=list`. | None. | Must be an absolute container path and must not collide with reserved runtime targets. |
| `read_only` | `--read-only` / `--read-write` | Optional for `action=add`. | `action=list`, `action=remove`. | `true`. | `--read-only` and `--read-write` are mutually exclusive. |
| `json` | `--json` | Optional. | Never. | `false`. | Selects the JSON renderer and non-interactive input mode. |

For command-line ergonomics, `orbit app:mount remove <app> <target>` is
accepted even though the shared command signature names the third positional
argument `source`.

## State Model

Gateway-owned configuration stores configurable runtime mounts in two tables:

- `app_instance_runtime_mounts` for instance-scoped mount intent.
- `app_runtime_mounts` for read-only compatibility app-level mount data.

Each row belongs to one app instance or one app and contains:

| Field | Type | Meaning |
| --- | --- | --- |
| `source` | string | Absolute host path on the resolved target node. |
| `target` | string | Absolute container path. Unique per app instance or app. |
| `read_only` | boolean | Whether Docker renders the bind mount read-only. |

The unique key is `(app_instance_id, target)` for instance mounts and
`(app_id, target)` for compatibility app-level rows. Adding a mount for an
existing instance target updates that target's source and read/write mode.

## API Surface

The gateway HTTP API mirrors the command. The `{app}` path segment accepts bare
app selectors and dotted instance selectors such as `hauser.nmbp`:

| Method | Path | Permission | Action |
| --- | --- | --- | --- |
| `GET` | `/api/apps/{app}/mounts` | `app:read` | Maps to `list`. |
| `POST` | `/api/apps/{app}/mounts` | `app:mount` | Maps to `add`; `{app}` must resolve to an app instance. |
| `DELETE` | `/api/apps/{app}/mounts` | `app:mount` | Maps to `remove`; `{app}` must resolve to an app instance. |

HTTP status codes: `200` for success, `404` for `app.not_found`, `422` for
validation failures, and `403` for permission denials.

## Renderer Selection

| Mode | Renderer |
| --- | --- |
| Default | [Human output](6.1_app-mount_output-render_human.md) |
| `--json` | [JSON output](6.2_app-mount_output-render_json.md) |

## Behavior Contract

### Selector Resolution

1. **Dotted instance selectors.** Selectors containing one dot, such as
   `hauser.nmbp`, resolve the named app instance. Writes and reads target
   `app_instance_runtime_mounts`.
2. **Bare app selectors.** Selectors without an instance suffix resolve
   compatibility app-level mounts in `app_runtime_mounts` for `list` only.
   `add` and `remove` fail with `error.meta.reason=app_instance_required`.
3. **Name precedence.** App and instance resolution follow the shared app
   selector rules used elsewhere in the app command surface.

### Runtime Mount Rules

1. **Instance-scoped intent.** New configurable runtime mounts belong to app
   instances, not logical apps.
2. **Compatibility fallback.** App and workspace runtime renderers prefer
   selected-instance mounts when present. App-level mounts are used only
   when the selected instance has no instance mounts configured.
3. **Workspace inheritance.** Workspace runtime containers inherit mounts from
   the workspace's selected app instance.
4. **PHP/app-dev only.** The current slice accepts configurable runtime mounts
   only for PHP apps whose owning node has the active `app-dev` role.
5. **Read-only default.** New mounts default to `read_only=true`; callers must
   pass `--read-write` or `read_only=false` to make them writable.
6. **Reserved target protection.** Custom mounts cannot target `/app`,
   `/packages`, `/data`, `/config`, the managed PHP ini target, the app
   source mirror target, or the internal ephemeral FrankenPHP XDG root
   `/tmp/orbit-frankenphp`.
7. **Home-source boundary.** Custom sources must live below the resolved target
   node's home directory and must not point at the home root or credential-bearing
   home paths. macOS instances validate against `/Users/<node-user>/`; Linux
   instances validate against `/home/<node-user>/`.
8. **Renderer integration.** The app and workspace runtime renderers append
   configured mounts after Orbit's built-in runtime mounts. The mount list is
   part of the runtime `spec_hash`.
9. **Directory preparation.** Before `docker run`, the runtime manager creates
   safe configured source directories with owner and group set to the source
   home user. Unsafe configured sources fail before Docker is invoked.

## Failure Semantics

Standard failures defined in
[Common Failures](../../../README.md#common-failures) apply; command-specific
failures below.

| Failure | Condition | Outcome |
| --- | --- | --- |
| Validation failed (action) | `action` is missing or not one of `list`, `add`, `remove`. | Failure |
| Validation failed (app) | `app` is missing in non-interactive mode. | Failure |
| App instance required | `add` or `remove` resolves to a bare app instead of an app instance. | Failure with `error.meta.reason=app_instance_required`. |
| Validation failed (source) | `add` is missing `source`, or `source` is not an allowed absolute host path. | Failure |
| Validation failed (target) | `add` or `remove` is missing `target`, or `target` is not an allowed absolute target path. | Failure |
| App not found | No app record or app instance matches the selector. | Failure |
| Unsupported app runtime | The app has `runtime != php`; `error.meta.reason=app_runtime_not_php`. | Failure |
| Unsupported owning role | The app is not owned by an active `app-dev` node; `error.meta.reason=app_mounts_app_dev_only`. | Failure |

Validation failures use `error.code=validation_failed`. Source and target
policy failures include `error.meta.reason`; current reasons include
`source_must_be_absolute`, `source_outside_app_dev_home`, `source_sensitive`,
`target_must_be_absolute`, and `target_reserved`.

## Doctor Relationship

Runtime mount configuration changes are ordinary app runtime configuration.
[`doctor --family=app`](../../app-doctor.md) is responsible for reporting and
repairing runtime container drift when the rendered app runtime container
differs from the gateway-owned mount intent. Workspace runtime drift remains
owned by [`doctor --family=workspace`](../../../6_workspace/workspace-doctor.md),
but the source of mount intent is the workspace's selected app instance, with
app-level mounts as fallback only.

## Side Effects

`add` and `remove` write gateway-owned app or app-instance configuration. They
do not restart or recreate runtime containers directly.

The next runtime convergence for the app or a workspace uses the stored mount
configuration. The runtime manager creates safe source directories for bind
mounts immediately before Docker creates the container.

## Test Mapping

| Path | Coverage |
| --- | --- |
| `apps/cli/tests/Feature/Commands/App/AppWriteCommandTest.php` | CLI validation for required inputs and forwarding of `list`, `add`, and `remove` to typed gateway endpoints, including dotted instance selectors. |
| `apps/gateway/tests/Feature/Http/Api/AppRuntimeMountControllerTest.php` | API persistence, updates, removal, permission split, PHP/app-dev gates, dotted selector resolution, instance target metadata, source policy, and reserved target validation. |
| `apps/gateway/tests/Unit/Services/Apps/AppRuntimeContainerRendererTest.php` | App runtime configured mounts, instance preference, compatibility fallback, and runtime `spec_hash` changes. |
| `apps/gateway/tests/Unit/Services/Workspaces/WorkspaceRuntimeContainerRendererTest.php` | Workspace use of selected app instance runtime mounts, compatibility fallback, and runtime `spec_hash` changes. |
| `apps/gateway/tests/Unit/Services/Apps/AppRuntimeContainerManagerTest.php` | App runtime source-directory preparation before Docker run. |
| `apps/gateway/tests/Unit/Services/Workspaces/WorkspaceRuntimeContainerManagerTest.php` | Workspace runtime source-directory preparation for inherited app mounts before Docker run. |
