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
- Removes a route when the owner is `custom`.
- With destructive consent, also removes a non-custom route only when the
  recorded owner reference is proven missing in gateway configuration (orphan
  owner). Owner proof matches proxy doctor `proxy.owner_invalid`: `project`,
  `instance`, `analytics`, and `websocket` owners require the corresponding
  living project, instance, or binding row; `workspace` owners require a living
  workspace row. Missing proof means the relation does not resolve (including a
  null foreign key after cascade null).
- `--force` never becomes a general ownership bypass. A living project,
  instance, WebSocket, workspace, gateway, S3, or tool owner still denies
  removal with `proxy.owned_route_denied`.
- Cleans backend route artifacts and Orbit-managed route-scoped TLS material
  through the canonical `ProxyRouteFixer::removeExtra()` path first
  (`/etc/orbit/certs/<domain>.{crt,key}` via the existing Caddy config remove path).
- Deletes the gateway registry row only after that cleanup succeeds.
- When cleanup fails, the registry row remains, the command returns hard
  `error.code=proxy.cleanup_failed` with `backend_removed=false`,
  `tls_removed=false`, and `next_command` for doctor repair, and no registry
  deletion occurs.
- TLS material shared by remaining proxy routes is not removed.
- When an orphan owner is removed successfully, the success payload includes
  `removal_reason=orphan_owner` and the public owner type that was proven
  missing so operators can audit the repair.

### Destructive Consent Rules

- Interactive mode requires an explicit confirmation prompt before effects.
- Non-interactive mode requires `--force`.
- `--json` does not imply destructive consent.
- Orphan-owner removal uses the same destructive consent rules as custom
  route removal; there is no separate orphan flag.

### Scope Boundaries

`proxy-remove` must not remove project, instance, WebSocket, workspace, gateway,
S3, or tool-owned routes while those owners still exist. It must not delete
project or instance files, WebSocket bindings, workspaces, tools, S3 route
publication records, DNS records, firewall rules, or service processes.
Living-owner route removal belongs to the owner domain. Orphan-owner rows that
doctor reports as `proxy.owner_invalid` are the narrow repair exception for
this command because proxy doctor restore does not delete those registry rows.

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
| Owned route denied | The selected route is owned by a project, instance, workspace, gateway, WebSocket binding, S3 publication, or tool whose owner record still exists. Orphan owners are not denied. | `error.code=proxy.owned_route_denied` |
| Destructive consent missing | Non-interactive input omitted `--force`, or the interactive confirmation was rejected. | `error.code=validation_failed`, `error.meta.field=force`, `error.meta.reason=destructive_consent_required` |
| Cleanup failed | Backend route or TLS cleanup failed before registry deletion. | `error.code=proxy.cleanup_failed`; registry row remains; `error.meta.backend_removed=false`, `error.meta.tls_removed=false`, `error.meta.next_command` for doctor repair. |

## Doctor Relationship

`proxy-remove` cleans backend and TLS through `ProxyRouteFixer` and then removes
the registry row when cleanup succeeds. With destructive consent it may also
remove orphan-owner registry rows that doctor reports as `proxy.owner_invalid`
(restore does not delete those rows). Doctor is only for genuine cleanup
failures and remaining orphan extras; it is not a mandatory second step after a
healthy remove. [`proxy-doctor.md`](../../proxy-doctor.md) owns the authoritative
`proxy` probe, issue codes, fix map, and adopt map.

## Test Mapping

| Path | Coverage |
| --- | --- |
| `apps/gateway/tests/Feature/Http/Api/ProxyRouteMutationControllerTest.php` | Gateway proxy route removal authorization, custom route deletion, orphan-owner force removal, living-owner denial, destructive consent requirement, and mutation API shape. |
| `apps/cli/tests/Feature/Commands/Proxy/ProxyWriteCommandTest.php` | CLI `proxy:remove` force consent handling, interactive confirmation, DELETE forwarding, JSON success envelope, orphan-owner safety prose, and gateway error passthrough. |
| `apps/gateway/tests/Unit/Services/Proxy/ProxyRouteIntentTest.php` | Custom removal, orphan-owner force removal, living-owner denial, cleanup failure keeps registry, ownership checks, and authorization. |
