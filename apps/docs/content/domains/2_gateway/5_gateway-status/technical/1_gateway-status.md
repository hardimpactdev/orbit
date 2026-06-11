# Technical Contract: `orbit gateway:status`

[Back to public `gateway:status` documentation.](../gateway-status.md)

**Owner:** `gateway`.

**Effects:** `read`.

**Prerequisites:**
- A configured local gateway endpoint exists.
- The local machine has an active WireGuard connection to the Orbit network so
  the gateway API at the configured endpoint is routable.

## Signature

```bash
orbit gateway:status [--json]
```

## Input Contract

This command follows the shared
[Invocation Model](../../../README.md#invocation-model).

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `json` | `--json` | Optional. | Never. | `false`. | Selects the JSON renderer and non-interactive input mode. |

## Input Resolution

1. Select the output renderer based on `--json` presence.
2. Issue GET `/api/status` against the configured gateway endpoint.

No input-mode-specific contracts are required. The command has no required
inputs that can be collected by prompt.

## Behavior Contract

### Gateway Status Query Rules

- Issue a GET request to `/api/status` on the configured gateway endpoint.
- Pass the response directly to the renderer. Rendering details are in the
  [6.1](6.1_gateway-status_output-render_human.md) and
  [6.2](6.2_gateway-status_output-render_json.md) contracts.

### Scope Boundaries

`gateway:status` must not:
- Modify local gateway configuration or trust material.
- Create or update gateway node records, client records, or node records.
- Verify `/api/me` or assess node identity.
- Perform CA trust installation or repair.
- Mint WireGuard identity, peer material, or node access grants.
- SSH to the gateway or nodes.
- Repair node, app, workspace, process, proxy route, schedule, tool, or
  firewall drift.

## Renderer Contracts

- [Human renderer](6.1_gateway-status_output-render_human.md)
- [JSON renderer](6.2_gateway-status_output-render_json.md)

## Failure Semantics

Standard failures defined in [Common Failures](../../../README.md#common-failures) apply; command-specific failures below.

| Failure | Condition | Outcome |
| --- | --- | --- |
| Gateway unreachable (WireGuard) | A `ConnectionException` is thrown when calling `/api/status`; the WireGuard route to the gateway is not established. | `error.code=gateway_unreachable_wireguard` |
| Gateway unavailable (HTTP) | The gateway API returns a non-success HTTP status for `/api/status`. | `error.code=gateway_unavailable` with `error.meta.status_code` and `error.meta.body_excerpt` when available. |

## Doctor Relationship

- `gateway:status` is a direct reachability check, not a doctor probe. It
  makes no attempt to repair state.
- `doctor --family=node` verifies gateway API reachability, WireGuard peer
  identity, gateway CA trust, and broader node drift. See
  [`node-doctor.md`](../../../1_node/node-doctor.md).
- A `gateway_unreachable_wireguard` or `gateway_unavailable` failure from
  `gateway:status` is a signal to run `doctor --family=node` for diagnosis and
  guided repair.

## Test Mapping

| Path | Coverage |
| --- | --- |
| `apps/gateway/tests/Feature/Commands/Gateway/GatewayStatusCommandTest.php` | Command contract: GET `/api/status`, human output renders `gateway` field only, JSON envelope shape with full response data and `meta.endpoint`, `gateway_unreachable_wireguard` on connection failure, `gateway_unavailable` on HTTP error, and exit codes. |
