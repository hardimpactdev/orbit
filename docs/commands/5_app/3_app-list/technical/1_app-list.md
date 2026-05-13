# Technical Contract: `orbit app:list`

[Back to public `app:list` documentation.](../app-list.md)

**Owner:** `app`.

**Effects:** `read`.

**Prerequisites:**
- The CLI caller can reach the Orbit gateway.
- The current node identity is authorized to read visible app registry configuration.

## Signature

```bash
orbit app:list [--node=<name>] [--environment=<development|production>] [--json]
```

## Input Contract

This command follows the shared
[Invocation Model](../../../README.md#invocation-model).

No required inputs exist; the command takes no positional arguments and all
options are optional.

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `node` | `--node` | Optional. | Never. | None. | App-node name in the gateway registry. Single value only. |
| `environment` | `--environment` | Optional. | Never. | None. | One of `development`, `production`. Single value only. |
| `json` | `--json` | Optional. | Never. | `false`. | Selects the JSON renderer and non-interactive input mode. |

`--node` and `--environment` are scalar filters. Multi-value semantics are not
part of the initial contract.

## Authorization By Caller Role

`app:list` authorization is owned by the gateway. The CLI does not branch on
client-side role detection. The gateway identifies the caller through its
WireGuard peer identity on every API call.

Command behavior does not vary by caller role. All authenticated peers with
visible registry access receive the same command contract. Visibility scoping
is access-policy-driven, not role-driven.

## Visibility Behavior

Visibility is filtered at the gateway as set membership against
gateway-owned app access policy. Callers receive only the apps their
authenticated identity is authorized to see.

- An authorized caller whose visible set is empty receives an empty list
  (`success.data.apps=[]` in JSON, `No apps found.` in human output) with
  exit zero.
- A caller whose identity is not authorized to read the app registry at
  all receives `error.code=authorization_failed`.
- Hidden apps are omitted entirely.

## Input Resolution

1. Resolve `app_list.node` from `--node` when present. Validate immediately.
2. Resolve `app_list.environment` from `--environment` when present. Validate
   immediately.
3. Select the output renderer and query the gateway for visible app registry
   configuration.

## Input Mode Contracts

No input-mode-specific contracts are required. The command takes no required
arguments and does not prompt.

## Behavior Contract

### App Registry Listing Rules

1. **Query gateway registry.** Read visible app registry configuration scoped to the
   current consuming node's access policy. No host probing is performed.
2. **Apply filters.** If `--node` is present, include only apps on that node.
   If `--environment` is present, include only apps with that environment.
   Filters combine with AND semantics.
3. **Sort results.** Apps are sorted by owning node name (ascending,
   case-insensitive) and then by app name (ascending, case-insensitive). Both
   renderers use this single ordering: the human renderer displays it as tables
   grouped by node, and the JSON renderer emits the same ordering as a flat
   array of list-item objects under `success.data.apps`.
4. **Attach workspaces.** Each app list item includes the app's registered
   workspaces sorted by workspace name (ascending, case-insensitive).
   Workspaces are registry configuration rows; no live workspace probing is performed.
5. **Render output.** Return the filtered app list through the selected output
   renderer.

### Scope Boundaries

`app:list` must not:
- SSH into nodes.
- Probe host reachability or health.
- Modify gateway configuration or node artifacts.
- Touch downstream family state.

## Renderer Contracts

- [Human renderer](6.1_app-list_output-render_human.md)
- [JSON renderer](6.2_app-list_output-render_json.md)

## Failure Semantics

| Failure | Condition | Outcome |
| --- | --- | --- |
| Invalid filter value | `--node` or `--environment` contains an unsupported value. | Failure |
| Gateway unavailable | The CLI cannot reach the gateway API. | Failure |
| Authorization failed | The caller identity is not authorized to read the app registry. | Failure |

## Doctor Relationship

- `app:list` reports configuration. `doctor --family=app` verifies reality.
- `app:list` does not expose `--doctor`; live verification belongs to
  `doctor --family=app`.
- See [`app-doctor.md`](../../app-doctor.md) for the authoritative app-family
  probe, drift, fix, and adopt contract.

## Activity Logging

The gateway API endpoint emits an activity entry for successful and failed
registry reads.

| Field | Value |
| --- | --- |
| Type | `api:GET /apps` |
| Effect | `read` |
| Subject | `none` |
| Properties | No command-specific properties. The API activity middleware adds transport context such as method, path, client, and serving gateway node. |
| Description | derived |

## Test Mapping

Primary test owners:

| Path | Coverage |
| --- | --- |
| `tests/Feature/Commands/Apps/AppListCommandTest.php` | Command contract: listing all visible apps with nested workspaces, node filtering, environment filtering, combined filters, gateway-unavailable failure, invalid filter validation, authorization failure, and read-only guarantee. |
| `tests/E2E/Read/AppListTest.php` | Real read-only `app:list --json` against registered apps. |

Renderer-specific test mapping lives in:

- [`6.1_app-list_output-render_human.md`](6.1_app-list_output-render_human.md#test-mapping)
- [`6.2_app-list_output-render_json.md`](6.2_app-list_output-render_json.md#test-mapping)
