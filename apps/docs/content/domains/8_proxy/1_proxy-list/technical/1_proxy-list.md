# Technical Contract: `orbit proxy:list [--node=<node>] [--filter=<filter>] [--json]`

[Back to public `proxy:list` documentation.](../proxy-list.md)

**Owner:** `proxy`.

**Effects:** `read`.

**Prerequisites:**
- The CLI caller can reach the Orbit gateway.
- The caller identity is authorized by the gateway to inspect proxy routes for the selected route owners or serving nodes.

## Signature

```bash
orbit proxy:list [--node=<node>] [--filter=<filter>] [--json]
```

## Input Contract

This command follows the shared [Invocation Model](../../../README.md#invocation-model).

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `filter` | `--filter` | `Optional.` | `Never.` | `all` | `all`, `app`, `app-websocket`, `app-analytics`, `workspace`, `gateway`, `websocket`, `s3`, `analytics`, `tool`, `custom`, or `redirect`. |
| `node` | `--node` | `Optional.` | `Never.` | `None.` | Visible node slug used as serving-node filter. |
| `json` | `--json` | `Optional.` | `Never.` | `false` | Selects the JSON renderer. |

## Behavior Contract

### Registry Visibility Rules

- Reads gateway proxy route configuration.
- Includes all visible route owners and route kinds by default.
- Applies `--filter` after authorization-visible routes are resolved.
- Applies `--node` as a serving-node filter.
- Does not synchronously SSH to nodes, probe proxy reality, or verify TLS
  material.

### Filter Rules

- `all` includes every visible route.
- `app`, `app-websocket`, `app-analytics`, `workspace`, `gateway`, and `tool` filter by owner
  type.
- `websocket`, `s3`, and `analytics` are service filters, not owner-enum mirrors:
  `websocket` selects the router-owned `websocket.orbit` service route; `s3`
  selects the router-owned `s3.orbit` service route plus public S3 host routes
  (owner `s3`); and `analytics` selects the router-owned `analytics.orbit`
  service route plus public app analytics host routes (owner `app-analytics`).
- `custom` filters user-authored upstream routes with owner `custom` and kind
  `proxy`.
- `redirect` filters routes with kind `redirect`.

### Scope Boundaries

`proxy-list` must not create, update, remove, adopt, fix, or probe proxy route artifacts. Live backend drift belongs to `doctor --family=proxy`.

## Renderer Contracts

- [Human renderer](6.1_proxy-list_output-render_human.md)
- [JSON renderer](6.2_proxy-list_output-render_json.md)

## Failure Semantics
Standard failures defined in [Common Failures](../../../README.md#common-failures) apply; command-specific failures below.

## Activity Logging

The gateway API endpoint emits an activity entry for successful and failed registry reads.

| Field | Value |
| --- | --- |
| Type | `api:GET /proxy-routes` |
| Effect | `read` |
| Subject | `none` |
| Properties | No command-specific properties. The API activity middleware adds transport context such as method, path, client, and serving gateway node. |
| Description | `derived` |

## Doctor Relationship

`proxy-list` reports gateway proxy route configuration only. [`proxy-doctor.md`](../../proxy-doctor.md) owns the authoritative `proxy` probe, issue codes, fix map, and adopt map.

## Test Mapping

| Path | Coverage |
| --- | --- |
| `apps/gateway/tests/Feature/Commands/Proxy/ProxyListCommandTest.php` | Command contract for filter validation, node filter validation, gateway authorization, no-live-probe behavior, and doctor handoff behavior. |
| `apps/gateway/tests/Unit/Services/Proxy/ProxyRouteQueryTest.php` | In-memory proxy route visibility filtering, filter semantics, node filter semantics, and proxy route entity mapping. |
