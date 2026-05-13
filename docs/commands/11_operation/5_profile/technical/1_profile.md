# Technical Contract: `orbit profile [target]`

[Back to public `profile` documentation.](../profile.md)

**Owner:** `operation`.

**Effects:** `read`.

**Prerequisites:**
- The CLI caller can reach the Orbit gateway when gateway app resolution, authorization, or gateway-origin profiling is required.
- The gateway identifies the calling WireGuard peer and authorizes that peer to read the resolved app.
- The target app route is reachable from the selected request origin.
- Authenticated profiles require app-side support for the explicit Toolbar auth header contract.

## Signature

```bash
orbit profile [target] [--app=<app>] [--node=<node>] [--uri=<uri>] [--as-first-user|--user=<id>] [--json]
```

## Input Contract

This command follows the shared
[Invocation Model](../../../README.md#invocation-model).

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `target` | `[target]` | Required in non-interactive mode when `--app` is absent and no app can be resolved from the current directory. | `--app` is present. | Current directory app/workspace context, then interactive app selection. | Domain, app hostname, full `http`/`https` URL, or absolute existing app path. Full URLs are split into host target and request URI. Absolute paths must resolve to a gateway-known app. |
| `app` | `--app` | Never. | `[target]` is present. | `null`. | Existing app name or hostname visible to the caller. |
| `node` | `--node` | Never. | Never. | Owning node from resolved app, or all authorized app nodes during interactive app selection. | Gateway-known app node name. Used only to constrain app resolution or app selection. |
| `uri` | `--uri` | Never. | Never. | `/`, or the path/query parsed from a full URL target when `--uri` was not supplied. | Non-empty request path. Values are normalized to start with `/`. |
| `as_first_user` | `--as-first-user` | Never. | `--user` is present. | `false`. | Selects Toolbar auth mode `first-user`. |
| `user` | `--user` | Never. | `--as-first-user` is present. | `null`. | Non-empty user primary key string. Selects Toolbar auth mode `user`. |
| `json` | `--json` | Optional. | Never. | `false`. | Selects the JSON renderer and non-interactive input mode. |

## Input Mode Contracts

- [Interactive input mode](5.1_profile_input-mode_interactive.md)
- [Non-interactive input mode](5.2_profile_input-mode_non-interactive.md)

## Input Resolution

1. Select the output renderer.
2. Validate mutually exclusive inputs:
   - `[target]` and `--app` cannot be combined.
   - `--as-first-user` and `--user` cannot be combined.
3. If `[target]` is a full URL, parse the host into `target` and, when
   `--uri` was not supplied, parse the URL path and query into `uri`.
4. Resolve app target through the gateway:
   - `--app=<app>` resolves an existing app by name or hostname.
   - absolute `[target]` resolves the app owning that path.
   - domain `[target]` resolves an app by name, hostname, or workspace hostname.
   - omitted `[target]` forwards working-directory hints; the gateway resolves the app/workspace context.
   - unresolved omitted target in interactive mode opens the app selector.
5. Apply `--node=<node>` as a node constraint during app resolution or app
   selection.
6. The gateway authorizes the calling peer for the resolved app.
7. Resolve request origin:
   - `caller` when the CLI's calling peer is identified as a control peer and the resolved URL is reachable from that machine;
   - `gateway` for gateway peers, app peers, and control peers whose environment cannot resolve or reach the route but whose gateway can.
8. Generate a per-run request id.
9. Resolve Toolbar auth headers:
    - no auth flags: `X-TOOLBAR-AUTH: guest`;
    - `--as-first-user`: `X-TOOLBAR-AUTH: first-user`;
    - `--user=<id>`: `X-TOOLBAR-AUTH: user` and `X-TOOLBAR-USER: <id>`.
10. Start the selected renderer and perform the HTTP profile request.

## Behavior Contract

### Target Resolution Rules

- Profile only Orbit-managed apps and workspaces.
- Do not profile arbitrary internet URLs that cannot be associated with a
  visible Orbit app.
- Name matches win over hostname matches when both could resolve.
- Workspace hostnames resolve to the workspace and parent app context; `--node`
  constrains target resolution but does not grant access.
- Absolute path targets are local-context selectors. A control node that cannot
  map the supplied path to a gateway-known app must fail and ask for a domain
  target or `--app`; it must not guess which remote app-node path the user
  meant.

### Request Measurement Rules

- Perform exactly one timed HTTP `GET` request to the resolved URL and URI.
- Follow redirects and report the final effective URL when it differs from the
  requested URL.
- Preserve cURL-equivalent baseline timing fields: `dns_ms`, `connect_ms`,
  `tls_ms`, `ttfb_ms`, `download_ms`, and `total_ms`.
- Derive `tls_ms` from TLS handshake time, treat `ttfb_ms` as total time until
  the first byte arrives, and derive `download_ms` from total time minus time to
  first byte.
- Record response status, byte count, completion state, error details, and
  response headers.
- A completed non-2xx response is a successful profile result because the
  request was measured.

### Toolbar Enrichment Rules

- Always return the baseline profile result when the main request completed.
- Mark `instrumented=false` and `source=baseline` without Toolbar summary data.
  When the response includes an `x-toolbar-summary` header containing a
  base64-encoded JSON summary, decode it, attach the summary, and mark
  `instrumented=true` and `source=baseline+toolbar`.
- Toolbar data may include timing anchors, profiler stages, memory information,
  route/controller data, and query counts. Partial Toolbar data is successful;
  missing collector fields are empty, omitted, or `null` according to the JSON
  renderer contract.
- Toolbar enrichment must never mutate the measured baseline timing values.
- Request identity and auth are explicit headers. `X-REQUEST-ID` correlates the
  request; it must not authenticate the request by itself.

### Scope Boundaries

`profile` must not:
- Mutate gateway app configuration, proxy route configuration, process definitions, schedule definitions, deployment state, or local settings.
- Repair app, proxy, or node drift.
- Run repeated requests, averages, load tests, warmup requests, benchmarks, or
  arbitrary shell commands in the app.
- Treat Toolbar absence, disabled Toolbar, partial Toolbar data, or completed
  HTTP 4xx/5xx responses as command failures.

## Renderer Contracts

- [Human renderer](6.1_profile_output-render_human.md)
- [JSON renderer](6.2_profile_output-render_json.md)

## Failure Semantics
Standard failures defined in [Common Failures](../../../README.md#common-failures) apply; command-specific failures below.

| Failure | Condition | Outcome |
| --- | --- | --- |
| Target not found | No visible Orbit app or workspace matches the resolved target. | Failure before HTTP request |
| Profile request failed | The timed HTTP request could not complete. | Failure with request/timing diagnostics |

The shared exit status policy applies: `0` for successful profile results,
including completed non-2xx HTTP responses; `1` for Orbit-handled command
failures; and `2` only for console-runtime invalid usage before Orbit can apply
this command contract.

## Doctor Relationship

- `profile` observes one request and does not repair drift.
- `doctor --family=app` owns app runtime health and app configuration drift.
- `doctor --family=proxy` owns route and proxy artifact drift, including proxy
  timing marker support required for enriched profile output.
- `doctor --family=node` owns node reachability and gateway runtime readiness.
- `profile` failures may point to the owning doctor family, but the command
  must not report app, proxy, or node health as converged.

## Activity Logging

The local CLI command emits an activity entry for successful and failed profile
read attempts. Gateway API profile requests also emit through the gateway API
activity middleware. Activity logging is best-effort and must not change the
documented command result.

| Field | Value |
| --- | --- |
| Type | `profile` |
| Effect | `read` |
| Subject | `none`; the CLI command observes one request and does not mutate or own a durable operation-family entity. |
| Properties | `target`, resolved `app`, `node`, `domain`, `uri`, `origin`, `auth_mode`, and `status_code` when known. No response headers, Toolbar payloads, profile body, user secrets, timing internals, raw errors, or auth header values. |
| Description | derived |

## Test Mapping

Primary test owners:

| Path | Coverage |
| --- | --- |
| `tests/Feature/Commands/Operations/ProfileCommandTest.php` | Target resolution from cwd, absolute path, app/domain/URL target, `--node` scoping, auth-mode validation, gateway authorization by peer role, request-origin selection, request completion semantics, non-2xx success, request failure diagnostics, read-only guarantee, and doctor handoff guidance. |
| `tests/Unit/Services/CurlRequestProfilerTest.php` | Baseline HTTP timing extraction, request status/bytes/effective URL, response-header capture, completed non-2xx handling, failed request diagnostics, timeout behavior, and stable millisecond conversion. |

Input-mode-specific test mapping lives in:

- [`5.1_profile_input-mode_interactive.md`](5.1_profile_input-mode_interactive.md#test-mapping)
- [`5.2_profile_input-mode_non-interactive.md`](5.2_profile_input-mode_non-interactive.md#test-mapping)

Renderer-specific test mapping lives in:

- [`6.1_profile_output-render_human.md`](6.1_profile_output-render_human.md#test-mapping)
- [`6.2_profile_output-render_json.md`](6.2_profile_output-render_json.md#test-mapping)
