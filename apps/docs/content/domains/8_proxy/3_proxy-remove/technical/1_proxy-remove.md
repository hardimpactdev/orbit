# Technical Contract: `orbit proxy:remove <domain> [--force] [--json]`

[Back to public `proxy:remove` documentation.](../proxy-remove.md)

**Owner:** `proxy`.

**Effects:** `write, destructive, stream`.

**Prerequisites:**
- The CLI caller can reach the Orbit gateway.
- The caller identity is authorized by the gateway to manage custom proxy routes for the route's serving node.

## Signature

```bash
orbit proxy:remove <domain> [--force] [--json]
```

## Input Contract

This command follows the shared [Invocation Model](../../../README.md#invocation-model).

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `domain` | `argument` | `Required in non-interactive mode.` | `Never.` | `None.` | Existing custom route domain. |
| `force` | `--force` | `Required in non-interactive mode.` | `Never.` | `false` | Explicit destructive consent. |
| `json` | `--json` | `Optional.` | `Never.` | `false` | Selects the JSON renderer. |

## Input Mode Contracts

- [Interactive input mode](5.1_proxy-remove_input-mode_interactive.md)
- [Non-interactive input mode](5.2_proxy-remove_input-mode_non-interactive.md)

## Behavior Contract

### Custom Route Removal Rules

- Resolves the route by domain from gateway proxy route configuration.
- Fails before side effects unless the route owner is `custom`.
- Removes the custom proxy route row from gateway configuration.
- Removes the backend route artifact from the serving node.
- Removes TLS material that Orbit manages only when it is route-scoped.
- TLS material shared by remaining proxy routes is not removed.

### Destructive Consent Rules

- Interactive mode requires an explicit confirmation prompt before gateway configuration is removed.
- Non-interactive mode requires `--force`.
- `--json` does not imply destructive consent.

### Scope Boundaries

`proxy-remove` must not remove app, app-websocket, workspace, gateway,
websocket, S3, or tool-owned routes. It must not delete app files, app
WebSocket bindings, workspaces, tools, S3 route publication records, DNS
records, firewall rules, or service processes. Owned-route removal belongs to
the owner domain.

## Renderer Contracts

- [Human renderer](6.1_proxy-remove_output-render_human.md)
- [JSON renderer](6.2_proxy-remove_output-render_json.md)

## Activity Logging

The gateway API endpoint emits an activity entry for successful and failed destructive removals.

| Field | Value |
| --- | --- |
| Type | `api:DELETE /proxy-routes/{domain}` |
| Effect | `destructive` |
| Subject | The caller `Node`. |
| Properties | `domain` (string from route parameter). |
| Description | `derived` |

## Failure Semantics
Standard failures defined in [Common Failures](../../../README.md#common-failures) apply; command-specific failures below.

| Failure | Condition | Outcome |
| --- | --- | --- |
| Route not found | The selected domain has no proxy route row. | `error.code=proxy.not_found` |
| Owned route denied | The selected route is owned by app, app-websocket, workspace, gateway, websocket, S3, or tool. | `error.code=proxy.owned_route_denied` |
| Destructive consent missing | Non-interactive input omitted `--force`, or the interactive confirmation was rejected. | `error.code=destructive_consent_required` |
| Cleanup failed | Gateway configuration was removed, but backend route or TLS cleanup failed. | `error.code=proxy.cleanup_failed` |

## Doctor Relationship

`proxy-remove` removes custom gateway proxy route configuration and performs command-owned cleanup only. [`proxy-doctor.md`](../../proxy-doctor.md) owns the authoritative `proxy` probe, issue codes, fix map, and adopt map.

## Test Mapping

| Path | Coverage |
| --- | --- |
| `apps/gateway/tests/Feature/Http/Api/ProxyRouteMutationControllerTest.php` | Gateway proxy route removal authorization, custom route deletion, destructive consent requirement, and mutation API shape. |
| `apps/cli/tests/Feature/Commands/Proxy/ProxyWriteCommandTest.php` | CLI `proxy:remove` force consent handling, interactive confirmation, DELETE forwarding, JSON success envelope, and gateway error passthrough. |
| `apps/gateway/tests/Unit/Services/Proxy/ProxyRouteIntentTest.php` | In-memory proxy route intent DTOs, custom-only removal, destructive consent mapping, cleanup warnings, ownership checks, and authorization. |
