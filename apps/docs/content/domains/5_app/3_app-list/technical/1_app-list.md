# Technical Contract: `orbit app:list`

[Back to public `app:list` documentation.](../app-list.md)

**Owner:** `app`.

**Effects:** `read`.

**Prerequisites:**
- The CLI caller can reach the Orbit gateway.
- The current node identity is authorized to read visible app registry configuration.

## Signature

```bash
orbit app:list [--node=<name>] [--json]
```

## Input Contract

This command follows the shared
[Invocation Model](../../../README.md#invocation-model).

No required inputs exist; the command takes no positional arguments and all
options are optional.

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `node` | `--node` | Optional. | Never. | None. | App-role name in the gateway registry. Single value only. |
| `json` | `--json` | Optional. | Never. | `false`. | Selects the JSON renderer and non-interactive input mode. |

`--node` is a scalar filter. Multi-value semantics are not part of the initial
contract.

## Visibility Behavior

Visibility is filtered at the gateway as set membership against the app access
policy that the gateway owns. Callers receive only the apps their
authenticated identity is authorized to see.

- An authorized caller whose visible set is empty receives an empty list
  (`success.data.apps=[]` in JSON, `No apps found.` in human output) with
  exit zero.
- A caller whose identity is not authorized to read the app registry at
  all receives `error.code=authorization_failed`.
- Hidden apps are omitted entirely.

## Input Resolution

1. Resolve `app_list.node` from `--node` when present. Validate immediately.
2. Select the output renderer and query the gateway for visible app registry
   configuration.

## Behavior Contract

### App Registry Listing Rules

1. **Query gateway registry.** Read visible app registry configuration scoped to the
   current consuming node's access policy. No host probing is performed.
2. **Apply filters.** If `--node` is present, include only apps on that node.
3. **Sort results.** Apps are sorted by owning node name (ascending,
   case-insensitive) and then by app name (ascending, case-insensitive). Every
   output renderer uses this single ordering.
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
Standard failures defined in [Common Failures](../../../README.md#common-failures) apply; command-specific failures below.

| Failure | Condition | Outcome |
| --- | --- | --- |
| Invalid filter value | `--node` contains an unsupported value. | Failure |

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
| `apps/cli/tests/Feature/Commands/App/AppListCommandTest.php` | CLI command contract: JSON envelope, node filter forwarding, retired environment filter guard, human output, gateway-unavailable failure, and WireGuard-specific failure mapping. |
| `apps/gateway/tests/E2E/Read/AppListTest.php` | Real read-only `app:list --json` against registered apps until the E2E harness moves to `apps/e2e`. |

Renderer-specific test mapping lives in:

- [`6.1_app-list_output-render_human.md`](6.1_app-list_output-render_human.md#test-mapping)
- [`6.2_app-list_output-render_json.md`](6.2_app-list_output-render_json.md#test-mapping)
