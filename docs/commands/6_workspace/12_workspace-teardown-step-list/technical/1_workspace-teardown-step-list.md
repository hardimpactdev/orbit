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
orbit workspace-teardown-step:list [--app=<app>] [--json]
```

## Input Contract

This command follows the shared
[Invocation Model](../../../README.md#invocation-model).

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `app` | `--app` | When no parent app can be inferred from the caller filesystem. | Never. | Cwd-inferred parent app. | App slug present in the gateway registry and authorized for this caller. Single value only. |
| `json` | `--json` | Optional. | Never. | `false`. | Selects the JSON renderer and non-interactive input mode according to the shared invocation model in [`docs/commands/README.md`](../../../README.md#invocation-model). |

## Visibility Behavior

The command returns the full ordered set of teardown steps for the resolved
app's `phase=teardown` policy, scoped to what the caller is authorized to
read.

- An authorized caller for an app with no configured teardown steps
  receives an empty list (`success.data.steps=[]` in JSON,
  `No teardown steps defined for [app].` in human output) with exit zero.
- A caller whose identity is not authorized to read the resolved app's
  policy receives `error.code=authorization_failed`.
- Explicitly requested apps that do not exist receive
  `error.code=workspace.app_not_found`.

## Input Resolution

1. **Resolve parent app.** Apply the precedence chain in order:
   1. `--app=<app>` flag.
   2. `.orbit/config` marker on the caller filesystem (installed by
      `app:new` / `app:register` and any workspace-installed marker) that
      names the owning app slug.
   3. Gateway path-ownership lookup keyed on
      `(caller node identity, absolute cwd)`.
   4. Resolution failure: in non-interactive mode, fail with
      `error.code=validation_failed`, `error.meta.field=app`. The command
      does not prompt because it has no required interactive inputs.
   - **Forbidden**: `workspace-teardown-step:list` must not read
     `composer.json`, `package.json`, `.php-version`, or any other project
     file content during parent-app inference. This matches the
     `workspace:new` and `workspace-teardown-step:add` contracts and the
     `ARCHITECTURE.md` "Workspaces" project-file inspection prohibition.
2. **Validate resolved app.** Confirm the app exists in gateway configuration.
   Unknown apps fail with `error.code=workspace.app_not_found` before any
   read.
3. **Select renderer.** Pick the JSON renderer if `--json` is present;
   otherwise pick the human renderer.
4. **Issue the registry read.** Query gateway-owned teardown-step policy
   for the resolved `(app, phase=teardown)` and pass the result to the
   renderer.

## Behavior Contract

### Teardown Step Listing Rules

1. **Query gateway registry.** Read the gateway-owned teardown-step policy
   for the resolved `(app, phase=teardown)` tuple. No host probing is
   performed.
2. **Sort results.** Steps are sorted by `order` ascending. Teardown steps
   already encode an authoritative ordering field; insertions performed by
   [`workspace-teardown-step:add`](../../11_workspace-teardown-step-add/workspace-teardown-step-add.md)
   mutate that field directly. Both renderers use this single ordering, so
   callers reading either output form see the same steps in the same
   relative order. The list is not reversed relative to setup: `order=1`
   names the first teardown step that runs during
   [`workspace:remove`](../../5_workspace-remove/workspace-remove.md), not
   a symmetric inverse of any setup ordering.
3. **Project step record shape.** Every returned record uses the shared
   step shape `{ id, app, phase, order, command, timeout_seconds }` already
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
| App not found | The resolved app slug does not exist in gateway configuration (`error.code=workspace.app_not_found`, `error.meta.app`). | Failure |
| Unauthorized app | The caller is not authorized to read the resolved app's teardown-step policy (`error.code=authorization_failed`). | Failure |

### Exit Status

Uses the shared exit status policy. Success, including the
authorized-but-empty case, exits `0`; all documented command failures exit with
the standard command failure status (`1`). This command defines no
command-specific numeric exit codes.

## Doctor Relationship

- `workspace-teardown-step:list` reports gateway-owned teardown-step configuration.
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
| `tests/Feature/Commands/Workspaces/WorkspaceTeardownStepListCommandTest.php` | Command contract: parent-app resolution (`--app`, `.orbit/config` marker, gateway path-ownership lookup, non-interactive failure), `order ASC` sort, full-dump (no pagination), step-record shape parity with `workspace-teardown-step:add` (`{id, app, phase, order, command, timeout_seconds}`), empty-list behavior for valid apps with no steps, `workspace.app_not_found` for unknown apps, `authorization_failed` for unauthorized callers, and read-only guarantee (no SSH, no configuration mutation, no step execution). |
| `tests/E2E/WorkspaceStepListTest.php` | Real read-only `workspace-teardown-step:list --json` against a registered app with steps, including ordering and envelope alignment. |

Renderer-specific test mapping lives in:

- [`6.1_workspace-teardown-step-list_output-render_human.md`](6.1_workspace-teardown-step-list_output-render_human.md#test-mapping)
- [`6.2_workspace-teardown-step-list_output-render_json.md`](6.2_workspace-teardown-step-list_output-render_json.md#test-mapping)
