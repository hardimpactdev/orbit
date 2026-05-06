# Technical Contract: `orbit proxy:add <domain> (--upstream=<url>|--redirect=<url>) [--node=<node>] [--code=<code>] [--force] [--json]`

[Back to public `proxy:add` documentation.](../proxy-add.md)

**Owner:** `proxy`.

**Effects:** `write, stream`.

**Prerequisites:**
- The CLI caller can reach the Orbit gateway, or the command is running on the gateway.
- The local caller role can be resolved according to the foundation `general.local_node_role` contract.
- The current node identity is authorized to manage custom proxy routes for the resolved serving node.

## Signature

```bash
orbit proxy:add <domain> (--upstream=<url>|--redirect=<url>) [--node=<node>] [--code=<code>] [--force] [--json]
```

## Input Contract

This command follows the shared [Invocation Model](../../../README.md#invocation-model).

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `domain` | `argument` | `Required in non-interactive mode.` | `Never.` | `None.` | Hostname or documented host/path route identity not owned by app, workspace, gateway, or tool routes. |
| `node` | `--node` | `Required when no local default node resolves the serving node.` | `Never.` | `local node:default when configured` | Visible active Ubuntu node with proxy capability. |
| `upstream` | `--upstream` | `Required when `redirect` is absent.` | `Forbidden with `redirect` or `code`.` | `None.` | HTTP or HTTPS upstream URL reachable from the serving node. |
| `redirect` | `--redirect` | `Required when `upstream` is absent.` | `Forbidden with `upstream`.` | `None.` | Absolute HTTP or HTTPS redirect URL. |
| `code` | `--code` | `Optional with `redirect`.` | `Forbidden with `upstream`.` | `302` | `301`, `302`, `307`, or `308`. |
| `force` | `--force` | `Required in non-interactive mode when replacing an existing custom route with different target intent.` | `Never.` | `false` | Explicit replacement consent; does not permit overwriting non-custom routes. |
| `json` | `--json` | `Optional.` | `Never.` | `false` | Selects the JSON renderer. |

## Caller Role Behavior

All authenticated caller roles use the same gateway-owned access policy.
App-node callers may add custom proxy routes only when their node identity has
explicit proxy route management authorization for the resolved serving node.
Management remains gateway-owned and enacted through gateway-to-node transport.

## Input Mode Contracts

- [Interactive input mode](5.1_proxy-add_input-mode_interactive.md)
- [Non-interactive input mode](5.2_proxy-add_input-mode_non-interactive.md)

## Behavior Contract

### Custom Route Rules

- Resolves a proxy-capable serving node.
- Validates that exactly one of `--upstream` or `--redirect` is selected.
- Creates or updates a custom gateway proxy route row.
- Stores upstream routes with owner `custom`, kind `proxy`, and target type
  `upstream`.
- Stores redirect routes with owner `custom`, kind `redirect`, target type
  `redirect`, and a redirect code.
- Enacts the proxy backend route and Orbit-managed TLS material through the
  gateway.

### Ownership Boundary Rules

- Fails before side effects when the domain is owned by an app, workspace,
  gateway, or tool route.
- Never uses `--force` to overwrite a non-custom route.
- Updating an existing custom route with a different target requires explicit
  replacement consent: an interactive confirmation prompt or `--force`.

### Scope Boundaries

`proxy-add` must not create apps, workspaces, tools, nodes, firewall rules, DNS
records, or process definitions. It must not infer tool ownership from a port;
future tool-owned routes belong to tool-family commands and are only visible in
`proxy:list`.

## Renderer Contracts

- [Human renderer](6.1_proxy-add_output-render_human.md)
- [JSON renderer](6.2_proxy-add_output-render_json.md)

## Activity Logging

The gateway API endpoint emits an activity entry for successful and failed
intent writes.

| Field | Value |
| --- | --- |
| Type | `api:POST /proxy-routes` |
| Effect | `write` |
| Subject | The caller `Node`. |
| Properties | `domain` (string), `node` (string). |
| Description | `derived` |

## Failure Semantics

| Failure | Condition | Outcome |
| --- | --- | --- |
| Validation failed | Required input is missing, invalid, mutually exclusive, or forbidden with another option. | `error.code=validation_failed` |
| Gateway unavailable | The CLI cannot reach the gateway API. | `error.code=gateway_unavailable` |
| Authorization failed | The caller is not authorized to manage custom proxy routes for the selected serving node. | `error.code=authorization_failed` |
| Domain conflict | The selected domain is owned by an app, workspace, gateway, or tool route. | `error.code=proxy.domain_conflict` |
| Replacement consent missing | Existing custom route differs and non-interactive input omitted `--force`. | `error.code=proxy.replacement_consent_required` |
| Enactment failed | Gateway intent was written, but proxy or TLS backend enactment failed. | `error.code=proxy.enactment_failed` |

## Doctor Relationship

`proxy-add` changes custom gateway proxy route intent and performs command-owned
enactment only. [`proxy-doctor.md`](../../proxy-doctor.md) owns the authoritative
`proxy` probe, issue codes, fix map, and adopt map.

## Test Mapping

| Path | Coverage |
| --- | --- |
| `tests/Feature/Commands/Proxy/ProxyAddCommandTest.php` | Command contract for input validation, mutually exclusive route shape, gateway authorization, target resolution, replacement consent, failure codes, and doctor handoff behavior. |
| `tests/Unit/Services/Proxy/ProxyCommandContractTest.php` | Shared in-memory proxy command DTO shape, custom-route entity mapping, route ownership conflict detection, and target resolution rules. |
