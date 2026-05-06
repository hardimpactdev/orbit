# Technical Contract: `orbit proxy:remove <domain> [--force] [--json]`

[Back to public `proxy:remove` documentation.](../proxy-remove.md)

**Owner:** `proxy`.

**Effects:** `write, destructive, stream`.

**Prerequisites:**
- The CLI caller can reach the Orbit gateway, or the command is running on the gateway.
- The local caller role can be resolved according to the foundation `general.local_node_role` contract.
- The current node identity is authorized to manage custom proxy routes for the route's serving node.

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

## Caller Role Behavior

All authenticated caller roles use the same gateway-owned access policy.
App-node callers may remove custom proxy routes only when their node identity has
explicit proxy route management authorization for the route's serving node.
Management remains gateway-owned and enacted through gateway-to-node transport.

## Input Mode Contracts

- [Interactive input mode](5.1_proxy-remove_input-mode_interactive.md)
- [Non-interactive input mode](5.2_proxy-remove_input-mode_non-interactive.md)

## Behavior Contract

### Custom Route Removal Rules

- Resolves the route by domain from gateway proxy route intent.
- Fails before side effects unless the route owner is `custom`.
- Removes the custom proxy route row from gateway intent.
- Removes the backend route artifact from the serving node.
- Removes Orbit-managed TLS material only when it is route-scoped and not
  shared by any remaining proxy route.

### Destructive Consent Rules

- Interactive mode requires an explicit confirmation prompt before gateway
  intent is removed.
- Non-interactive mode requires `--force`.
- `--json` does not imply destructive consent.

### Scope Boundaries

`proxy-remove` must not remove app, workspace, gateway, or tool-owned routes.
It must not delete app files, workspaces, tools, DNS records, firewall rules, or
service processes. Owned-route removal belongs to the owner domain.

## Renderer Contracts

- [Human renderer](6.1_proxy-remove_output-render_human.md)
- [JSON renderer](6.2_proxy-remove_output-render_json.md)

## Activity Logging

The gateway API endpoint emits an activity entry for successful and failed
destructive removals.

| Field | Value |
| --- | --- |
| Type | `api:DELETE /proxy-routes/{domain}` |
| Effect | `destructive` |
| Subject | The caller `Node`. |
| Properties | `domain` (string from route parameter). |
| Description | `derived` |

## Failure Semantics

| Failure | Condition | Outcome |
| --- | --- | --- |
| Validation failed | Required input is missing or invalid. | `error.code=validation_failed` |
| Gateway unavailable | The CLI cannot reach the gateway API. | `error.code=gateway_unavailable` |
| Authorization failed | The caller is not authorized to manage custom proxy routes for the serving node. | `error.code=authorization_failed` |
| Route not found | The selected domain has no proxy route row. | `error.code=proxy.not_found` |
| Owned route denied | The selected route is owned by app, workspace, gateway, or tool. | `error.code=proxy.owned_route_denied` |
| Destructive consent missing | Non-interactive input omitted `--force`, or the interactive confirmation was rejected. | `error.code=destructive_consent_required` |
| Cleanup failed | Gateway intent was removed, but backend route or TLS cleanup failed. | `error.code=proxy.cleanup_failed` |

## Doctor Relationship

`proxy-remove` removes custom gateway proxy route intent and performs
command-owned cleanup only. [`proxy-doctor.md`](../../proxy-doctor.md) owns the
authoritative `proxy` probe, issue codes, fix map, and adopt map.

## Test Mapping

| Path | Coverage |
| --- | --- |
| `tests/Feature/Commands/Proxy/ProxyRemoveCommandTest.php` | Command contract for input validation, gateway authorization, destructive consent, custom-only removal, owned-route denial, cleanup failure codes, and doctor handoff behavior. |
| `tests/Unit/Services/Proxy/ProxyCommandContractTest.php` | Shared in-memory proxy command DTO shape, route ownership checks, destructive consent mapping, and proxy route entity mapping. |
