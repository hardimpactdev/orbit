# Technical Contract: `orbit proxy:add [domain] [--node=<node>] [--upstream=<url>] [--redirect=<url>] [--code=<code>] [--force] [--json]`

[Back to public `proxy:add` documentation.](../proxy-add.md)

**Owner:** `proxy`.

**Effects:** `write, stream`.

**Prerequisites:**
- The CLI caller can reach the Orbit gateway.
- The caller identity is authorized by the gateway to manage custom proxy routes for the resolved serving node.

## Signature

```bash
orbit proxy:add [domain] [--node=<node>] [--upstream=<url>] [--redirect=<url>] [--code=<code>] [--force] [--json]
```

## Input Contract

This command follows the shared [Invocation Model](../../../README.md#invocation-model).

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `domain` | `argument` | `Required in non-interactive mode.` | `Never.` | `None.` | Hostname or documented host/path route identity not owned by a project, instance, WebSocket binding, workspace, gateway, websocket service, S3 service, or tool route. |
| `node` | `--node` | `Optional.` | `Never.` | `node:default if set; otherwise --self (the calling peer).` | Visible active Ubuntu node with proxy capability. |
| `upstream` | `--upstream` | `Required when `redirect` is absent.` | `Forbidden with `redirect` or `code`.` | `None.` | HTTP or HTTPS upstream URL reachable from the serving node. |
| `redirect` | `--redirect` | `Required when `upstream` is absent.` | `Forbidden with `upstream`.` | `None.` | Absolute HTTP or HTTPS redirect URL. |
| `code` | `--code` | `Optional with `redirect`.` | `Forbidden with `upstream`.` | `302` | `301`, `302`, `307`, or `308`. |
| `force` | `--force` | `Required in non-interactive mode when replacing an existing custom route with different target configuration.` | `Never.` | `false` | Explicit replacement consent; does not permit overwriting non-custom routes. |
| `json` | `--json` | `Optional.` | `Never.` | `false` | Selects the JSON renderer. |

## Input Mode Contracts

- [Interactive input mode](5.1_proxy-add_input-mode_interactive.md)
- [Non-interactive input mode](5.2_proxy-add_input-mode_non-interactive.md)

## Behavior Contract

### Custom Route Rules

- Resolves a proxy-capable serving node.
- Validates that exactly one of `--upstream` or `--redirect` is selected.
- Creates or updates a custom gateway proxy route row.
- Persists the route with enactment `pending`, then enacts backend and TLS through
  the canonical `ProxyRouteFixer` path in the same command.
- On successful enactment, marks the route `converged`. A registry row alone is
  not success evidence; `status=converged` means planned backend/TLS operations completed.
- On enactment failure, keeps the partial route row, records enactment
  `failed`/`partial`, and returns a **successful** command envelope whose
  `meta.warnings[]` includes registered `proxy.enactment_failed` with
  `next_command` prefix `doctor --family=proxy --restore`. Doctor is the repair
  path only; it is not a mandatory second step after a healthy add.
- Stores upstream routes with owner `custom`, kind `proxy`, and target type `upstream`.
- Stores redirect routes with owner `custom`, kind `redirect`, target type `redirect`, and a redirect code.
- When an upstream route targets a host-local service through `127.0.0.1`,
  `localhost`, or `host.docker.internal`, reports
  the registered `firewall_rule.host_upstream_may_block` command handoff in the
  `firewall_rule` family. Its allowed next-command prefix is `firewall:allow`,
  with the upstream port and a scoped command shape. This warning is
  informational; the `proxy` family does not create firewall-rule intent.

### Ownership Boundary Rules

These rules govern how `proxy:add` interacts with routes owned by other families and what consent is required to replace a custom route.

The command fails before side effects if the domain is already owned by a
project, instance, WebSocket binding, workspace, gateway, websocket service, S3 service, or
tool route. It never uses `--force` to overwrite a non-custom route. Updating
an existing custom route with a different target requires explicit replacement
consent, supplied either as an interactive confirmation prompt or `--force`.

### Scope Boundaries

`proxy-add` must not create projects, instances, WebSocket bindings, workspaces, tools,
nodes, firewall rules, DNS records, or process definitions. It must not infer
tool ownership from a port, WebSocket ownership from a hostname, or S3
ownership from a hostname. When an upstream targets a service on the same host
and that service needs firewall access, that access belongs to the
`firewall_rule` family even when `proxy:add` reports the likely missing rule.
S3 routes belong to `s3:*` commands and are only visible in `proxy:list`.

## Renderer Contracts

- [Human renderer](6.1_proxy-add_output-render_human.md)
- [JSON renderer](6.2_proxy-add_output-render_json.md)

## Activity Logging

The gateway API endpoint emits an activity entry for successful and failed configuration writes.

| Field | Value |
| --- | --- |
| Type | `api:POST /proxy-routes` |
| Effect | `write` |
| Subject | The caller `Node`. |
| Properties | `domain` (string), `node` (string). |
| Description | `derived` |

## Failure Semantics
Standard failures defined in [Common Failures](../../../README.md#common-failures) apply; command-specific failures below.

| Failure | Condition | Outcome |
| --- | --- | --- |
| Domain conflict | The selected domain is owned by a project, instance, WebSocket binding, workspace, gateway, websocket service, S3 service, or tool route. | `error.code=proxy.domain_conflict` |
| Replacement consent missing | Existing custom route differs and non-interactive input omitted `--force`. | `error.code=proxy.replacement_consent_required` |
| Apply failed | Gateway configuration was written, but proxy or TLS backend apply failed. | Successful envelope with route row retained; `meta.warnings[]` contains `proxy.enactment_failed` and `next_command` for doctor repair. Route enactment status is `failed` or `partial`. |

## Doctor Relationship

`proxy-add` changes custom gateway proxy route configuration and enacts backend/TLS
in the same command when apply succeeds. [`proxy-doctor.md`](../../proxy-doctor.md)
owns the authoritative `proxy` probe, issue codes, fix map, and adopt map, and is
the repair path when add left a partial `proxy.enactment_failed` state.

## Test Mapping

| Path | Coverage |
| --- | --- |
| `apps/gateway/tests/Feature/Http/Api/ProxyRouteMutationControllerTest.php` | Gateway proxy route creation authorization, custom upstream route intent, non-custom domain conflict denial, and mutation API shape. |
| `apps/cli/tests/Feature/Commands/Proxy/ProxyWriteCommandTest.php` | CLI `proxy:add` upstream and redirect payloads, local default node resolution, required node validation, mutually exclusive target validation, and gateway error passthrough. |
| `apps/cli/tests/Feature/Commands/Proxy/ProxyInteractiveInputModeTest.php` | Interactive `proxy:add` custom upstream prompt before contacting the gateway. |
| `apps/gateway/tests/Unit/Services/Proxy/ProxyRouteIntentTest.php` | One-step enactment, replacement consent, ownership conflicts, custom-route removal, authorization, and app-owned domain rejection. |
