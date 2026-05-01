# Technical Contract: `orbit workspace:list`

[Back to public `workspace:list` documentation.](../workspace-list.md)

**Owner:** `workspace`.

**Effects:** `read`.

**Prerequisites:**
- The CLI caller can reach the Orbit gateway.
- The current node identity is authorized to read visible workspace registry intent.

## Signature

```bash
orbit workspace:list [--app=<slug>] [--node=<slug>] [--json]
```

## Input Contract

This command follows the shared
[Invocation Model](../../../README.md#invocation-model).

No required inputs exist; the command takes no positional arguments and all
options are optional.

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `app` | `--app` | Optional. | Never. | None. | App slug present in the gateway registry. Single value only; comma-separated input fails as `validation_failed` because it is not a valid single app slug. Unknown slugs fail before side effects. |
| `node` | `--node` | Optional. | Never. | None. | App-node slug present in the gateway registry. Single value only; comma-separated input fails as `validation_failed` because it is not a valid single node slug. Unknown slugs fail before side effects. |
| `json` | `--json` | Optional. | Never. | `false`. | Selects the JSON renderer and non-interactive input mode according to the shared invocation model in [`docs/commands/README.md`](../../../README.md#invocation-model). |

`--app` and `--node` are scalar slug filters. Multi-value semantics are not part
of the initial contract. Operators who need to query multiple apps or nodes at
once should run `workspace:list --json` without that filter and post-filter the
result, or run separate scoped invocations.

## Caller Role Behavior

`workspace:list` behavior does not vary by caller role. All authenticated
callers with visible registry access receive the same command contract.
Visibility scoping is access-policy-driven, not role-driven.

## Visibility Behavior

Visibility is filtered at the gateway as set membership against gateway-owned
workspace access policy. Callers receive only the workspaces their authenticated
identity is authorized to see.

- An authorized caller whose visible set is empty receives an empty list
  (`success.data.workspaces=[]` in JSON, `No workspaces found.` in human output)
  with exit zero.
- A caller whose identity is not authorized to read the workspace registry at
  all receives `error.code=authorization_failed`.
- Hidden workspaces are omitted entirely.

## Input Resolution

1. Resolve `workspace_list.app` from `--app` when present. Validate immediately
   against the gateway app registry.
2. Resolve `workspace_list.node` from `--node` when present. Validate
   immediately against the gateway node registry.
3. Select the output renderer and query the gateway for visible workspace
   registry intent.

## Input Mode Contracts

No input-mode-specific contracts are required. The command takes no required
arguments and does not prompt.

## Behavior Contract

1. **Query gateway registry.** Read visible workspace registry intent scoped to
   the current consuming node's access policy. No host probing is performed.
2. **Apply filters.** If `--app` is present, include only workspaces belonging
   to that app. If `--node` is present, include only workspaces residing on that
   node. Filters combine with AND semantics.
3. **Sort results.** Workspaces are sorted by owning node name (ascending,
   case-insensitive), then by parent app name (ascending, case-insensitive), then
   by workspace name (ascending, case-insensitive). Both renderers use this single
   ordering: the human renderer displays it as tables grouped by app within node,
   and the JSON renderer emits the same ordering as a flat array under
   `success.data.workspaces`.
4. **Render output.** Return the filtered workspace list through the selected
   output renderer.

`workspace:list` must not:
- SSH into nodes.
- Probe host reachability or workspace artifact health.
- Modify gateway intent or node artifacts.
- Touch downstream family state.

### Lifecycle Status Taxonomy

| Lifecycle status | Description |
| --- | --- |
| `expected` | Gateway intent treats the workspace as the desired steady-state row; no setup or teardown action is pending. This does not certify node artifacts. |
| `setup-pending` | Workspace is registered on the gateway but `workspace:setup` has not run. |

Workspace removal deletes gateway workspace intent before best-effort node
cleanup starts. Removed workspaces disappear from registry-backed list output
instead of moving through a retained removal lifecycle state.

Lifecycle status is registry intent only. It is rendered as
`lifecycle_status` in JSON to distinguish it from setup-run status and live
HTTP probe results. Live workspace reality belongs to
[`doctor --family=workspace`](../../workspace-doctor.md).

## Renderer Contracts

- [Human renderer](6.1_workspace-list_output-render_human.md)
- [JSON renderer](6.2_workspace-list_output-render_json.md)

## Failure Semantics

| Failure | Condition | Outcome |
| --- | --- | --- |
| Invalid filter value | `--app` or `--node` references an unknown slug, or contains comma-separated input. | Failure |
| Gateway unavailable | The CLI cannot reach the gateway API. | Failure |
| Authorization failed | The caller identity is not authorized to read the workspace registry. | Failure |

## Doctor Relationship

- `workspace:list` reports intent. `doctor --family=workspace` verifies reality.
- `workspace:list` does not expose `--doctor`; live verification belongs to
  `doctor --family=workspace`.
- See [`workspace-doctor.md`](../../workspace-doctor.md) for the authoritative
  workspace-family probe, drift, fix, and adopt contract.
- Workspace hostname artifact convergence belongs to `doctor --family=proxy_route`;
  inherited process-unit convergence belongs to `doctor --family=process`.

## Test Mapping

Primary test owners:

| Path | Coverage |
| --- | --- |
| `tests/Feature/Commands/Workspaces/WorkspaceListCommandTest.php` | Command contract: listing all visible workspaces, app filtering, node filtering, combined filters, gateway-unavailable failure, invalid filter validation, authorization failure, and read-only guarantee. |
| `tests/Feature/Commands/Workspaces/WorkspaceListJsonRendererTest.php` | JSON envelope shape, success payload with workspace array, sort ordering, filter error JSON shape, and lifecycle status taxonomy. |
| `tests/Feature/Commands/Workspaces/WorkspaceListHumanRendererTest.php` | Human renderer selection, table grouping by app within node, success prose, filter error prose, and authorization failure prose. |

Renderer-specific test mapping lives in:

- [`6.1_workspace-list_output-render_human.md`](6.1_workspace-list_output-render_human.md#test-mapping)
- [`6.2_workspace-list_output-render_json.md`](6.2_workspace-list_output-render_json.md#test-mapping)
