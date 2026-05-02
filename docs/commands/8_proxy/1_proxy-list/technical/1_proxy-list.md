# Technical Contract: `orbit proxy:list [--filter=<filter>] [--node=<node>] [--json]`

[Back to public `proxy:list` documentation.](../proxy-list.md)

**Owner:** `proxy`.

**Effects:** `read`.

**Prerequisites:**
- The CLI caller can reach the Orbit gateway, or the command is running on the gateway.
- The local caller role can be resolved according to the foundation `general.local_node_role` contract.
- The current node identity is authorized to inspect proxy routes for the selected route owners or serving nodes.

## Signature

```bash
orbit proxy:list [--filter=<filter>] [--node=<node>] [--json]
```

## Input Contract

This command follows the shared [Invocation Model](../../../README.md#invocation-model).

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `filter` | `--filter` | `Optional.` | `Never.` | `all` | `all`, `app`, `workspace`, `gateway`, `tool`, `custom`, or `redirect`. |
| `node` | `--node` | `Optional.` | `Never.` | `None.` | Visible node slug used as serving-node filter. |
| `json` | `--json` | `Optional.` | `Never.` | `false` | Selects the JSON renderer. |

## Caller Role Behavior

All authenticated caller roles use the same gateway-owned access policy.
App-node callers may list only proxy routes whose serving node or owner is
visible to their node identity. App-node local context may help select a node
filter in future commands, but this command does not infer a filter from local
context by default.

## Input Mode Contracts

No input-mode-specific contracts are required. The command does not prompt;
invalid filters or node filters fail according to the shared invocation model.

## Behavior Contract

### Registry Visibility Rules

- Reads gateway proxy route intent.
- Includes all visible route owners and route kinds by default.
- Applies `--filter` after authorization-visible routes are resolved.
- Applies `--node` as a serving-node filter.
- Does not synchronously SSH to nodes, probe proxy reality, or verify TLS
  material.

### Filter Rules

- `all` includes every visible route.
- `app`, `workspace`, `gateway`, and `tool` filter by owner type.
- `custom` filters user-authored upstream routes with owner `custom` and kind
  `proxy`.
- `redirect` filters routes with kind `redirect`.

### Scope Boundaries

`proxy-list` must not create, update, remove, adopt, fix, or probe proxy route
artifacts. Live backend drift belongs to `doctor --family=proxy`.

## Renderer Contracts

- [Human renderer](6.1_proxy-list_output-render_human.md)
- [JSON renderer](6.2_proxy-list_output-render_json.md)

## Failure Semantics

| Failure | Condition | Outcome |
| --- | --- | --- |
| Validation failed | `--filter` is invalid or `--node` cannot be resolved. | `error.code=validation_failed` |
| Gateway unavailable | The CLI cannot reach the gateway API. | `error.code=gateway_unavailable` |
| Authorization failed | The caller is not authorized to inspect the selected serving node or route owner. | `error.code=authorization_failed` |

## Doctor Relationship

`proxy-list` reports gateway proxy route intent only. [`proxy-doctor.md`](../../proxy-doctor.md)
owns the authoritative `proxy` probe, issue codes, fix map, and adopt map.

## Test Mapping

| Path | Coverage |
| --- | --- |
| `tests/Feature/Commands/Proxy/ProxyListCommandTest.php` | Command contract for filter validation, node filter validation, gateway authorization, no-live-probe behavior, and doctor handoff behavior. |
| `tests/Unit/Services/Proxy/ProxyRouteQueryTest.php` | In-memory proxy route visibility filtering, filter semantics, node filter semantics, and proxy route entity mapping. |
