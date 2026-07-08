# Technical Contract: `orbit workspace:list`

[Back to public `workspace:list` documentation.](../workspace-list.md)

**Owner:** `workspace`.

**Effects:** `read`.

**Prerequisites:**
- The CLI caller can reach the Orbit gateway.
- The current node identity is authorized to read visible workspace registry configuration.

## Signature

```bash
orbit workspace:list [--app=<app>] [--node=<slug>] [--node-transport=<transport>] [--json]
```

## Input Contract

This command follows the shared
[Invocation Model](../../../README.md#invocation-model).

No required inputs exist; the command takes no positional arguments and all
options are optional.

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `app` | `--app` | Optional. | Never. | None. | Parent app slug or app-instance selector present in the gateway registry. Dot notation such as `happie.nmbp` selects one concrete app instance. Single value only; comma-separated input fails as `validation_failed` because it is not a valid single app selector. Unknown selectors fail before side effects. |
| `node` | `--node` | Optional. | Never. | None. | App-role slug present in the gateway registry. Single value only; comma-separated input fails as `validation_failed` because it is not a valid single node slug. Unknown slugs fail before side effects. |
| `node_transport` | `--node-transport` | Optional. | Never. | `auto`. | One of `auto`, `agent-push`, or `transitional-ssh-fallback`. |
| `json` | `--json` | Optional. | Never. | `false`. | Selects the JSON renderer and non-interactive input mode according to the shared invocation model in [`docs/domains/README.md`](../../../README.md#invocation-model). |

`--app` and `--node` are scalar filters. Multi-value semantics are not part of
the initial contract. Operators who need to query multiple apps, app
instances, or nodes at once should run `workspace:list --json` without that
filter and post-filter the result, or run separate scoped invocations.

## Visibility Behavior

Visibility is filtered at the gateway against the workspace access policy that the gateway owns. Callers receive only the workspaces their authenticated
identity is authorized to see.

- An authorized caller whose visible set is empty receives an empty list
  (`success.data.workspaces=[]` in JSON, `No workspaces found.` in human output)
  with exit zero.
- A caller whose identity is not authorized to read the workspace registry at
  all receives `error.code=authorization_failed`.
- Hidden workspaces are omitted entirely.

## Input Resolution

1. Resolve `workspace_list.app` from `--app` when present. Validate immediately
   against the gateway app/app-instance registry.
2. Resolve `workspace_list.node` from `--node` when present. Validate
   immediately against the gateway node registry.
3. Select the output renderer and query the gateway for visible workspace
   registry configuration.

## Behavior Contract

1. **Query gateway registry.** Read visible workspace registry configuration scoped to
   the current consuming node's access policy. No host probing is performed.
2. **Apply filters.** If `--app` is present, include only workspaces belonging
   to that app or selected app instance. If `--node` is present, include only
   workspaces whose effective workspace node is that node. Filters combine
   with AND semantics.
3. **Sort results.** Workspaces are sorted by owning node name (ascending,
   case-insensitive), then by parent app name (ascending, case-insensitive), then
   by workspace name (ascending, case-insensitive). Every output renderer uses
   this single ordering.
4. **Render output.** Return the filtered workspace list through the selected
   output renderer.

`workspace:list` must not:
- SSH into nodes.
- Probe host reachability or workspace artifact health.
- Modify gateway configuration or node artifacts.
- Touch downstream family state.

### Lifecycle Status Taxonomy

| Lifecycle status | Description |
| --- | --- |
| `expected` | Gateway configuration treats the workspace as the desired steady-state row; no setup or teardown action is pending. This does not certify node artifacts. |
| `setup-pending` | Workspace is registered on the gateway but `workspace:setup` has not run. |

Workspace removal deletes gateway workspace configuration, and then node cleanup starts on a best-effort basis. Removed workspaces disappear from registry-backed list
output instead of moving through a retained removal lifecycle state.

Lifecycle status is registry configuration only. It is rendered as
`lifecycle_status` in JSON to distinguish it from setup-run status and live
HTTP probe results. Live workspace reality belongs to
[`doctor --family=workspace`](../../workspace-doctor.md).

## Renderer Contracts

- [Human renderer](6.1_workspace-list_output-render_human.md)
- [JSON renderer](6.2_workspace-list_output-render_json.md)

## Failure Semantics
Standard failures defined in [Common Failures](../../../README.md#common-failures) apply; command-specific failures below.

| Failure | Condition | Outcome |
| --- | --- | --- |
| Invalid filter value | `--app` or `--node` references an unknown selector/slug, or contains comma-separated input. | Failure |

## Doctor Relationship

- `workspace:list` reports configuration. `doctor --family=workspace` verifies reality.
- `workspace:list` does not expose `--doctor`; live verification belongs to
  `doctor --family=workspace`.
- See [`workspace-doctor.md`](../../workspace-doctor.md) for the authoritative
  workspace-family probe, drift, fix, and adopt contract.
- Workspace hostname artifact convergence belongs to `doctor --family=proxy`;
  inherited process-unit convergence belongs to `doctor --family=process`.

## Activity Logging

The gateway API endpoint emits an activity entry for successful and failed
workspace registry reads.

| Field | Value |
| --- | --- |
| Type | `api:GET /workspaces` |
| Effect | `read` |
| Subject | `none` |
| Properties | No command-specific properties. The API activity middleware adds transport context such as method, path, client, and serving gateway node. |
| Description | derived |

## Test Mapping

Primary test owners:

| Path | Coverage |
| --- | --- |
| `apps/gateway/tests/Feature/Http/Api/WorkspaceListControllerTest.php` | Gateway workspace registry visibility, app/node filters, entity shape, validation failures, and authorization failures. |
| `apps/cli/tests/Feature/Commands/Workspace/WorkspaceListCommandTest.php` | CLI workspace listing filter forwarding, grouped human output, empty state, JSON envelope, and gateway error passthrough. |
| `apps/cli/tests/Feature/Commands/Workspace/WorkspaceListCommandTest.php` | JSON workspace list envelope and workspace entity passthrough. |
| `apps/cli/tests/Feature/Commands/Workspace/WorkspaceListCommandTest.php` | Human workspace table grouping, headers, empty-state prose, and missing-cell rendering. |

Renderer-specific test mapping lives in:

- [`6.1_workspace-list_output-render_human.md`](6.1_workspace-list_output-render_human.md#test-mapping)
- [`6.2_workspace-list_output-render_json.md`](6.2_workspace-list_output-render_json.md#test-mapping)
