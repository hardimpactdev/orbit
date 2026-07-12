# Technical Contract: `orbit profile [url]`

[Back to public `profile` documentation.](../profile.md)

**Owner:** local CLI.

**Effects:** local read-only HTTP probe.

**Prerequisites:** The caller machine can resolve, trust, and reach the selected
URL. No gateway connection, WireGuard identity, grant, node, or Agent is
required.

## Signature

```bash
orbit profile [url] [--as-first-user|--user=<id>] [--json]
```

## Input Contract

This command follows the shared [Invocation Model](../../../README.md#invocation-model).

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `url` | `[url]` | No local `APP_URL` resolves and input is non-interactive. | Never. | Nearest ancestor `.env` `APP_URL`, then interactive `profile.url`. | Absolute URL with `http` or `https` scheme and a non-empty host. Preserve the value exactly. |
| `as_first_user` | `--as-first-user` | Never. | `--user` is present. | `false`. | Selects Toolbar auth mode `first-user`. |
| `user` | `--user` | Never. | `--as-first-user` is present. | `null`. | Non-empty user primary key string. |
| `json` | `--json` | Never. | Never. | `false`. | Selects JSON output and non-interactive input. |

`--app`, `--node`, `--node-transport`, and `--uri` are not part of this command.

## Input Mode Contracts

- [Interactive input mode](5.1_profile_input-mode_interactive.md)
- [Non-interactive input mode](5.2_profile_input-mode_non-interactive.md)

## Input Resolution

1. Reject combined `--as-first-user` and `--user` with
   `validation_failed`, `field=auth`, and `reason=conflicting_auth_modes`.
2. If `[url]` is present, use it without consulting the filesystem.
3. Otherwise, start at `ORBIT_HOST_CWD` when it is non-empty; fall back to
   `getcwd()`.
4. Walk upward to the first ancestor containing `.env`. Parse only the first
   `APP_URL` assignment in that file. Accept optional `export`, surrounding
   whitespace, single or double quotes, and an unquoted trailing comment. Do
   not import or mutate any other environment value.
5. Do not continue to more distant `.env` files after the nearest file is
   found. A missing or invalid `APP_URL` in that file is not replaced by a
   farther value.
6. If no URL resolves, prompt locally in interactive human mode or return
   `validation_failed`, `field=url`, `reason=missing_required_input`.
7. If the nearest `.env` value is invalid, prompt locally in interactive human
   mode or return `validation_failed`, `field=url`, `reason=invalid_url`.
8. Reject an invalid explicit URL immediately with `validation_failed`,
   `field=url`, `reason=invalid_url`.

## Behavior Contract

### Request Contract

- Perform exactly one `GET` from the CLI process against the resolved URL.
- Preserve the complete URL string; do not split, normalize, or combine a
  separate URI.
- Send `X-REQUEST-ID` with a per-run UUID.
- Send `X-TOOLBAR-AUTH: guest`, `first-user`, or `user`. In `user` mode also
  send `X-TOOLBAR-USER: <id>`.
- Verify the remote TLS certificate and hostname using the caller's normal
  cURL trust store. Do not fetch or inject gateway CA material and never retry
  insecurely.
- Use a 2-second connection timeout and a 30-second total timeout.
- Do not follow redirects. A completed response of any status is success.
- Return `profile_request_failed` only when the HTTP request itself does not
  complete.
- Never instantiate or call the gateway API client, gateway profile API, node
  transport, or activity endpoint.

### Result Contract

Baseline fields are `dns_ms`, `connect_ms`, `tls_ms`, `ttfb_ms`,
`download_ms`, and `total_ms`, plus request status, bytes, completion state,
response headers, and safe error details. `tls_ms` is handshake duration;
`download_ms` is total time minus time to first byte.

When `x-toolbar-summary` contains valid base64-encoded JSON, attach it as
`toolbar`, set `instrumented=true`, and set `source=baseline+toolbar`.
Otherwise set `instrumented=false` and `source=baseline`. Toolbar enrichment
never mutates baseline timing.

### Scope Boundaries

`profile` must not resolve Orbit apps or workspaces, authorize through the
gateway, inspect node state, repair drift, run repeated requests, follow
redirects, or emit an activity entry.

## Renderer Contracts

- [Human renderer](6.1_profile_output-render_human.md)
- [JSON renderer](6.2_profile_output-render_json.md)

## Failure Semantics

| Failure | Condition | Stable metadata |
| --- | --- | --- |
| Missing URL | No explicit URL or nearest `.env` `APP_URL`; prompting unavailable. | `validation_failed`, `field=url`, `reason=missing_required_input` |
| Invalid URL | Value is not an absolute HTTP(S) URL with a host. | `validation_failed`, `field=url`, `reason=invalid_url` |
| Conflicting auth | Both auth flags supplied. | `validation_failed`, `field=auth`, `reason=conflicting_auth_modes` |
| Request failed | The direct HTTP request did not complete. | `profile_request_failed`, `origin=caller`, resolved `url` |

Exit status is `0` for every completed response, `1` for these handled
failures, and `2` only for console-runtime invalid usage.

## Doctor Relationship

`profile` does not run doctor or infer Orbit ownership. Operators may separately
use app or proxy doctor when the URL is Orbit-managed, but profile itself emits
no gateway handoff or convergence claim.

## Activity Logging

None. This local-only read does not contact the gateway and does not emit an
Orbit activity entry.

## Test Mapping

| Path | Coverage |
| --- | --- |
| `apps/cli/tests/Feature/Commands/Operation/ProfileCommandTest.php` | Local-only signature, URL precedence, nearest `.env` resolution, prompt/non-interactive failures, auth headers, zero gateway I/O, success, enrichment, and failure envelopes. |
| `apps/cli/tests/Feature/Services/Profile/CurlProfileRequestProfilerTest.php` | Direct cURL behavior, TLS verification, redirects disabled, timeouts, timing conversion, headers, and completed non-2xx handling. |

There is no gateway-side coverage because `profile` is a local-only command and
must never enter the gateway.

Input- and renderer-specific test mapping lives in the linked contracts.
