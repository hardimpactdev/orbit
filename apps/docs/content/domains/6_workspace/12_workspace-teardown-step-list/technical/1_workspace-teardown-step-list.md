# Technical Contract: `orbit workspace-teardown-step:list`

[Back to public `workspace-teardown-step:list` documentation.](../workspace-teardown-step-list.md)

**Owner:** `workspace`.

**Effects:** `read`.

**Prerequisites:**
- The CLI caller can reach the Orbit gateway.
- The current node identity is authorized to read workspace teardown-step
  policy for the resolved app.
- The resolved app exists in gateway configuration.

## Signature

```bash
orbit workspace-teardown-step:list [--instance=<app.instance>] [--json]
```

## Input Contract

This command follows the shared
[Invocation Model](../../../README.md#invocation-model).

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `instance` | `--instance` | When no instance can be inferred from the caller filesystem. | Never. | Cwd-inferred instance. | Dotted instance selector such as `happie.nmbp`, present in the gateway registry and authorized for this caller. Single value only. |
| `json` | `--json` | Optional. | Never. | `false`. | Selects the JSON renderer and non-interactive input mode according to the shared invocation model in [`docs/domains/README.md`](../../../README.md#invocation-model). |

## Visibility Behavior

The command returns the full ordered set of teardown steps for the resolved
instance's `phase=teardown` policy, scoped to what the caller is authorized
to read.

- An authorized caller for an app with no configured teardown steps
  receives an empty list (`success.data.steps=[]` in JSON,
  `No teardown steps defined for [app.instance].` in human output) with exit zero.
- A caller whose identity is not authorized to read the resolved app's
  policy receives `error.code=authorization_failed`.
- Explicitly requested instances that do not exist receive
  `error.code=instance.not_found`.

## Input Resolution

1. **Resolve instance.** Apply the precedence chain in order:
   1. `--instance=<app.instance>` flag, using a dotted instance selector such
      as `happie.nmbp`.
   2. `.orbit/config` marker on the caller filesystem (installed by
      `app:new` / `instance:register` and any workspace-installed marker) that
      names the owning project slug.
   3. Gateway path-ownership lookup keyed on
      `(caller node identity, absolute cwd)`.
   4. Resolution failure: in non-interactive mode, fail with
      `error.code=validation_failed`, `error.meta.field=instance`. The command
      does not prompt because it has no required interactive inputs.
   - **Forbidden**: `workspace-teardown-step:list` must not read
     `composer.json`, `package.json`, `.php-version`, or any other project
     file content during instance inference. This matches the
     `workspace:new` and `workspace-teardown-step:add` contracts and the
     `architecture.md` "Workspaces" project-file inspection prohibition.
2. **Validate resolved app.** Confirm the app exists in gateway configuration.
   Unknown instances fail with `error.code=instance.not_found` before any
   read.
3. **Select renderer.** Use the shared invocation model to select the output
   renderer.
4. **Issue the registry read.** Query gateway-owned teardown-step policy
   for the resolved `(instance, phase=teardown)` and pass the result to the
   renderer.

## Behavior Contract

### Teardown Step Listing Rules

1. **Query gateway registry.** Read the gateway-owned teardown-step policy
   for the resolved `(instance, phase=teardown)` tuple. No host probing is
   performed.
2. **Sort results.** Steps are sorted by `order` ascending. Teardown steps
   already encode an authoritative ordering field; insertions performed by
   [`workspace-teardown-step:add`](../../11_workspace-teardown-step-add/workspace-teardown-step-add.md)
   mutate that field directly. Every output renderer uses this single ordering,
   so callers reading any output form see the same steps in the same relative
   order. The list is not reversed relative to setup: `order=1`
   names the first teardown step that runs during
   [`workspace:remove`](../../5_workspace-remove/workspace-remove.md), not
   a symmetric inverse of any setup ordering.
3. **Project step record shape.** Every returned record uses the shared
   step shape `{ id, project, instance, phase, order, command, timeout_seconds }` already
   published by `workspace-teardown-step:add`. `phase` is always
   `"teardown"`. There is no `name`, no per-step `working_directory`, no
   `env_overrides`, and no per-step `on_failure` field.
4. **Render output.** Return the ordered step list through the selected
   renderer. The JSON envelope key is `success.data.steps`; the human
   renderer emits a table with `ID`, `ORDER`, `COMMAND`, and `TIMEOUT`
   columns.

### Scope Boundaries

`workspace-teardown-step:list` must not:
- SSH into nodes.
- Probe host reachability or step artifact health.
- Modify gateway configuration or node artifacts.
- Execute teardown steps or mutate workspace lifecycle state.
- Touch downstream family state.

## Renderer Contracts

- [Human renderer](6.1_workspace-teardown-step-list_output-render_human.md)
- [JSON renderer](6.2_workspace-teardown-step-list_output-render_json.md)

## Failure Semantics
Standard failures defined in [Common Failures](../../../README.md#common-failures) apply; command-specific failures below.

| Failure | Condition | Outcome |
| --- | --- | --- |
| Instance not found | The resolved instance selector does not exist in gateway configuration (`error.code=instance.not_found`, `error.meta.instance`). | Failure |
| Instance required | The selector does not resolve a concrete instance. | Failure (`error.code=validation_failed`, `error.meta.reason=instance_required`) |
| Production app unsupported | The selected instance is served by an `app-prod` node. | Failure (`error.code=workspace.unsupported_for_production`) |
| Unauthorized app | The caller is not authorized to read the resolved app's teardown-step policy (`error.code=authorization_failed`). | Failure |

### Exit Status

Uses the shared exit status policy. Success, including the
authorized-but-empty case, exits `0`; all documented command failures exit with
the standard command failure status (`1`). This command defines no
numeric exit codes specific to it.

## Doctor Relationship

- `workspace-teardown-step:list` reports the teardown-step configuration owned by the gateway.
  It does not verify whether previous teardown runs cleaned up node-side
  artifacts.
- [`doctor --family=workspace`](../../workspace-doctor.md) owns the
  workspace-family probe, drift, fix, and adopt contract. Workspace
  teardown-run reality (worktree removal, dropped databases, lifecycle
  artifact cleanup) is reported by `workspace-doctor.md`, not by this
  command.
- Drift between teardown-step policy and workspace runtime reality is the
  doctor's job, not this command's.

## Activity Logging

The gateway API endpoint emits an activity entry for successful and failed
teardown-step policy reads.

| Field | Value |
| --- | --- |
| Type | `api:GET /workspaces/steps/{phase}` |
| Effect | `read` |
| Subject | `none` |
| Properties | No command-specific properties. The API activity middleware adds transport context such as method, path, client, and serving gateway node. |
| Description | derived |

## Test Mapping

Primary test owners:

| Path | Coverage |
| --- | --- |
| `apps/cli/tests/Feature/Commands/Workspace/WorkspaceStepListCommandTest.php` | CLI teardown-step list request forwarding, app/path resolution, human output, empty state, and JSON payload. |
| `apps/gateway/tests/Feature/Http/Api/WorkspaceStepListControllerTest.php` | Gateway teardown-step list ordering and phase filtering. |

Renderer-specific test mapping lives in:

- [`6.1_workspace-teardown-step-list_output-render_human.md`](6.1_workspace-teardown-step-list_output-render_human.md#test-mapping)
- [`6.2_workspace-teardown-step-list_output-render_json.md`](6.2_workspace-teardown-step-list_output-render_json.md#test-mapping)
