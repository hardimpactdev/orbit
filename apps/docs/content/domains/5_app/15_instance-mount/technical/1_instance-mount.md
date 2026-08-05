# Technical Contract: `orbit instance:mount list|add|remove [instance]`

[Back to public `instance:mount` documentation.](../instance-mount.md)

**Owner:** `instance`.

**Effects:** `read` for `list`; `write` for `add` and `remove`.

**Prerequisites:**
- The CLI caller can reach the Orbit gateway.
- The authenticated peer has `instance:read` on the selected instance's serving node for `list`.
- The authenticated peer has `instance:mount` on the selected instance's serving node for `add`
  and `remove`.
- The target instance exists in gateway configuration.

## Signature

```bash
orbit instance:mount [action] [instance] [source] [target] [--read-only] [--read-write] [--json]
```

## Input Contract

This command follows the shared
[Invocation Model](../../../README.md#invocation-model).

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `action` | `{action}` | Always. | Never. | None. | Must be one of `list`, `add`, `remove`. |
| `instance` | `[instance]` | Always. | Never. | None. | Must be a dotted instance selector such as `hauser.nmbp` and resolve an existing instance. |
| `source` | `[source]` | `action=add`. | `action=list`. | None. | Must be an absolute safe source under the resolved target node's home. |
| `target` | `[target]` | `action=add` and `action=remove`. | `action=list`. | None. | Must be an absolute container path and must not collide with reserved runtime targets. |
| `read_only` | `--read-only` / `--read-write` | Optional for `action=add`. | `action=list`, `action=remove`. | `true`. | `--read-only` and `--read-write` are mutually exclusive. |
| `json` | `--json` | Optional. | Never. | `false`. | Selects the JSON renderer and non-interactive input mode. |

For command-line ergonomics, `orbit instance:mount remove <app.instance> <target>` is
accepted even though the shared command signature names the third positional
argument `source`.

## State Model

Gateway-owned configuration stores configurable runtime mounts in
`instance_runtime_mounts`. Each row belongs to one instance and contains:

| Field | Type | Meaning |
| --- | --- | --- |
| `source` | string | Absolute host path on the resolved target node. |
| `target` | string | Absolute container path. Unique per instance. |
| `read_only` | boolean | Whether Docker renders the bind mount read-only. |

The unique key is `(instance_id, target)`. Adding a mount for an
existing instance target updates that target's source and read/write mode.

## API Surface

The gateway HTTP API mirrors the command. The `{instance}` path segment requires a
dotted instance selector such as `hauser.nmbp`:

| Method | Path | Permission | Action |
| --- | --- | --- | --- |
| `GET` | `/api/instances/{instance}/mounts` | `instance:read` | Maps to `list`; `{instance}` resolves an instance. |
| `POST` | `/api/instances/{instance}/mounts` | `instance:mount` | Maps to `add`; `{instance}` resolves an instance. |
| `DELETE` | `/api/instances/{instance}/mounts` | `instance:mount` | Maps to `remove`; `{instance}` resolves an instance. |

HTTP status codes: `200` for success, `404` for `instance.not_found`, `422` for
validation failures, and `403` for permission denials.

## Renderer Selection

| Mode | Renderer |
| --- | --- |
| Default | [Human output](6.1_instance-mount_output-render_human.md) |
| `--json` | [JSON output](6.2_instance-mount_output-render_json.md) |

## Behavior Contract

### Selector Resolution

1. **Dotted instance selectors.** Selectors containing one dot, such as
   `hauser.nmbp`, resolve the named instance. Its serving node is the sole
   authorization and host-path boundary. Every action targets
   `instance_runtime_mounts`.
2. **Bare project selectors.** Selectors without an instance suffix fail with
   `error.meta.reason=instance_required`.
3. **Name precedence.** Project and instance resolution follow the shared instance
   selector rules used elsewhere in the app and instance command surface.

### Runtime Mount Rules

1. **Instance-scoped intent.** New configurable runtime mounts belong to
   instances, not apps.
2. **Workspace inheritance.** Workspace runtime containers inherit mounts from
   the workspace's selected instance.
3. **PHP/app-dev only.** The current slice accepts configurable runtime mounts
   only for PHP instances whose serving node has the active `app-dev` role.
4. **Read-only default.** New mounts default to `read_only=true`; callers must
   pass `--read-write` or `read_only=false` to make them writable.
5. **Reserved target protection.** Custom mounts cannot target `/app`,
   `/packages`, `/data`, `/config`, the managed PHP ini target, the app
   source mirror target, or the internal ephemeral FrankenPHP XDG root
   `/tmp/orbit-frankenphp`.
6. **Home-source boundary.** Custom sources must live below the resolved target
   node's home directory and must not point at the home root or credential-bearing
   home paths. macOS instances validate against `/Users/<node-user>/`; Linux
   instances validate against `/home/<node-user>/`.
7. **Renderer integration.** The app and workspace runtime renderers append
   configured mounts after Orbit's built-in runtime mounts. The mount list is
   part of the runtime `spec_hash`.
8. **Directory preparation.** Before `docker run`, the runtime manager creates
   safe configured source directories with owner and group set to the source
   home user. Unsafe configured sources fail before Docker is invoked.
9. **Entity separation.** Renderers return the logical `project` and concrete
   `instance` separately. All node, URL, path,
   root, domain, placement, and `adopted` fields belong only to `instance`.

## Failure Semantics

Standard failures defined in
[Common Failures](../../../README.md#common-failures) apply; command-specific
failures below.

| Failure | Condition | Outcome |
| --- | --- | --- |
| Validation failed (action) | `action` is missing or not one of `list`, `add`, `remove`. | Failure |
| Validation failed (instance) | `instance` is missing in non-interactive mode. | Failure |
| Instance required | Any action resolves a bare project instead of an instance. | Failure with `error.meta.reason=instance_required`. |
| Validation failed (source) | `add` is missing `source`, or `source` is not an allowed absolute host path. | Failure |
| Validation failed (target) | `add` or `remove` is missing `target`, or `target` is not an allowed absolute target path. | Failure |
| Instance not found | No app record or instance matches the selector. | Failure |
| Unsupported app runtime | The app has `runtime != php`; `error.meta.reason=instance_runtime_not_php`. | Failure |
| Unsupported serving role | The selected instance is not served by an active `app-dev` node; `error.meta.reason=instance_mounts_app_dev_only`. | Failure |
| Authorization denied | The caller lacks the action permission on the selected instance's serving node. | `authorization_failed` with `instance` and `serving_node`. |

Validation failures use `error.code=validation_failed`. Source and target
policy failures include `error.meta.reason`; current reasons include
`source_must_be_absolute`, `source_outside_app_dev_home`, `source_sensitive`,
`target_must_be_absolute`, and `target_reserved`.

## Doctor Relationship

Runtime mount configuration changes are ordinary app runtime configuration.
[`doctor --family=instance`](../../instance-doctor.md) is responsible for reporting and
repairing runtime container drift when the rendered app runtime container
differs from the gateway-owned mount intent. Workspace runtime drift remains
owned by [`doctor --family=workspace`](../../../6_workspace/workspace-doctor.md),
and the source of mount intent is the workspace's selected instance.

## Side Effects

`add` and `remove` write gateway-owned instance configuration. They
do not restart or recreate runtime containers directly.

The next runtime convergence for the app or a workspace uses the stored mount
configuration. The runtime manager creates safe source directories for bind
mounts immediately before Docker creates the container.

## Test Mapping

| Path | Coverage |
| --- | --- |
| `apps/cli/tests/Feature/Commands/App/AppWriteCommandTest.php` | CLI validation for required inputs and forwarding of `list`, `add`, and `remove` to typed gateway endpoints, including dotted instance selectors. |
| `apps/gateway/tests/Feature/Http/Api/AppRuntimeMountControllerTest.php` | API persistence, updates, removal, permission split, PHP/app-dev gates, dotted selector resolution, instance target metadata, source policy, and reserved target validation. |
| `apps/gateway/tests/Unit/Services/Apps/AppRuntimeContainerRendererTest.php` | App runtime configured instance mounts and runtime `spec_hash` changes. |
| `apps/gateway/tests/Unit/Services/Workspaces/WorkspaceRuntimeContainerRendererTest.php` | Workspace use of selected instance runtime mounts and runtime `spec_hash` changes. |
| `apps/gateway/tests/Unit/Services/Apps/AppRuntimeContainerManagerTest.php` | App runtime source-directory preparation before Docker run. |
| `apps/gateway/tests/Unit/Services/Workspaces/WorkspaceRuntimeContainerManagerTest.php` | Workspace runtime source-directory preparation for inherited app mounts before Docker run. |
