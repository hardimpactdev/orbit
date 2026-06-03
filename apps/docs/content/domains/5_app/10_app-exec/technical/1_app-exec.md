# Technical Contract: `orbit app:exec [app] -- <command...>`

[Back to public `app:exec` documentation.](../app-exec.md)

**Owner:** `app`.

**Effects:** `stream`. The underlying command may read or write any state the
caller chooses to invoke; `app:exec` itself does not mutate gateway
configuration.

**Prerequisites:**
- The CLI caller can reach the Orbit gateway.
- The authenticated peer has `app:exec` on the app's owning node.
- The target app exists in gateway configuration with `runtime_kind=php`.
- The owning node has the host PHP toolchain for the app's configured PHP
  version and the app source path exists.

## Signature

```bash
orbit app:exec {app?} -- {command...} [--json]
```

The signature uses the canonical Orbit token order. At invocation time
`--json` (and any other Orbit option) must appear BEFORE `--`, because
Symfony stops parsing options after the separator. See the public
documentation's Usage section for the working invocation form.

## Input Contract

This command follows the shared
[Invocation Model](../../../README.md#invocation-model).

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `app` | `[app]` | Non-interactive mode when `ORBIT_HOST_CWD` does not resolve to an app. | Never. | None. | Must resolve to an existing app with `runtime_kind=php`. Name match wins over hostname, domain, or URL match. |
| `command` | tokens after `--` | Always. | Never. | None. | At least one non-empty token. |
| `json` | `--json` | Optional. | Never. | `false`. | Selects the JSON renderer and non-interactive input mode. |

## Input Resolution

1. Resolve `command`. Reject an empty command list with `validation_failed`,
   `meta.field=command`.
2. Resolve `app`. The CLI does not prompt — exec is treated as a
   non-interactive command surface so the same invocation works the same
   way in shell pipelines, scripts, and operator terminals.
   - If `[app]` is supplied, forward it to the gateway as the selector.
   - Otherwise, forward entrypoint-provided `ORBIT_HOST_CWD`; the gateway
     resolves it server-side to a canonical app entity (workspace
     paths under an app do not resolve as the parent app — they belong to
     `workspace:exec`).
   - If neither `[app]` nor a resolvable `ORBIT_HOST_CWD` is available,
     fail with `validation_failed`, `meta.field=app`.
3. The gateway validates the target app exists in gateway configuration.
4. The gateway validates the target app uses `runtime_kind=php`. Static
   apps cannot be targets and fail with `app.exec_unsupported_runtime`.

## State Model

`app:exec` does not own durable gateway state. It reads the canonical app
entity to resolve the app source path, configured PHP version, owning node, and
authorization context. The command relies on the owning node's host PHP
toolchain for the configured PHP version. The app's FrankenPHP runtime
container serves the same source but is not used to execute the command.

## Renderer Selection

| Mode | Renderer |
| --- | --- |
| Default | [Human output](6.1_app-exec_output-render_human.md) |
| `--json` | [JSON output](6.2_app-exec_output-render_json.md) |

## API Surface

The gateway HTTP API mirrors the command and exposes two entry points:

| Method | Path | Permission | Action |
| --- | --- | --- | --- |
| `POST` | `/api/apps/{app}/exec` | `app:exec` | Run a command on the app node's host PHP toolchain from the app source path. Body: `command` array of one or more non-empty string tokens. |
| `POST` | `/api/apps/exec/by-path` | `app:exec` | Resolve the target app from an entrypoint-provided host cwd and run the command. Body: `host_cwd` string plus `command` array. |

CLI operator-mode invocations use the by-path endpoint when no explicit
selector is supplied, forwarding the raw `ORBIT_HOST_CWD` so the
authoritative app/workspace identity is resolved on the gateway.

API responses share the JSON envelope and error codes documented in the
[JSON renderer contract](6.2_app-exec_output-render_json.md).

HTTP status mapping:

| `error.code` | HTTP status |
| --- | --- |
| `app.not_found` | 404 |
| `app.exec_unsupported_runtime` | 422 |
| `app.exec_command_not_executable` | 422 |
| `app.exec_command_not_found` | 422 |
| `validation_failed` | 422 |
| `app.exec_toolchain_unavailable` | 502 |
| `app.exec_node_unreachable` | 502 |
| `authorization_failed` | 403 |

## Behavior Contract

### Execution Rules

1. **Gateway is the authority.** CLI invocations on non-gateway nodes
   forward through the gateway typed API at `POST /api/apps/{app}/exec`.
   The CLI never resolves gateway-owned state directly when running in
   operator mode; the gateway authorizes the request and orchestrates the
   `RemoteShell` call into the owning node. Only the gateway-local CLI
   path runs `RemoteShell` itself.
2. **Host PHP toolchain is the execution boundary.** `app:exec` runs the
   command on the app's owning node with the host PHP binary matched to the
   app's configured PHP version, from the app source path tracked by the
   gateway. The FrankenPHP runtime container serves that source but is not
   entered for `app:exec`.
3. **PHP-only.** Apps with `runtime_kind != php` cannot be exec targets.
   `app:exec` fails with `app.exec_unsupported_runtime`.
4. **Toolchain must be available.** A preflight verifies the matched host PHP
   binary and required command entrypoint can be resolved on the owning node.
   Missing host PHP, Composer, Artisan, or path prerequisites fail with
   `app.exec_toolchain_unavailable` or the command-specific 126/127 code.
5. **Working directory is the app source path.** The command runs from the
   gateway-tracked app source path. Callers do not pick a working directory.
6. **No token rewriting.** The command tokens are passed to the host command
   runner verbatim. `app:exec` does not interpret, expand, or substitute
   tokens.

### Host Cwd Resolution

`ORBIT_HOST_CWD` is the entrypoint-provided host working directory.
Resolution is the gateway's responsibility, not the CLI's: operator-mode
callers forward the raw cwd string in the request body and the gateway
queries its app registry for the canonical app entity whose `path` is an
exact match for, or a parent of, the host cwd. If multiple app paths
match (one app nested under another), the longest matching path wins so
the most specific app is selected.

The CLI does not mount every app path into `orbit-gateway`, and it does
not query local SQLite for gateway-owned app or workspace state when
running in operator mode. Only the gateway-local CLI invocation reads
gateway state directly because it IS the gateway.

A workspace path that lives under an app source path resolves to the
workspace, not the app. The resolver returns the workspace match when one
exists and lets `workspace:exec` handle the command. `app:exec` treats a
workspace-only cwd match as "no app match" and falls through to the
fail branch.

## Failure Semantics

Standard failures defined in
[Common Failures](../../../README.md#common-failures) apply; command-specific
failures below.

| Failure | Condition | Outcome |
| --- | --- | --- |
| Missing command | The `command` token list is empty after parsing. | `validation_failed`, `meta.field=command`. |
| Missing app | Non-interactive mode and `ORBIT_HOST_CWD` does not resolve to an app. | `validation_failed`, `meta.field=app`. |
| App not found | No app record matches `app`. | `app.not_found`. |
| Unsupported runtime | The target app has `runtime_kind != php`. | `app.exec_unsupported_runtime`. |
| Toolchain unavailable | The matched host PHP toolchain or app source execution context cannot be prepared on the owning node. | `app.exec_toolchain_unavailable`. |
| Command not executable | The host command exits with `126` — the target exists but is not executable (permission denied, wrong architecture). | `app.exec_command_not_executable`. |
| Command not found | The host command exits with `127` — the target is not present in the execution `$PATH`. | `app.exec_command_not_found`. |
| Node unreachable | Preflight returns an unknown failure before the host command can run (typical SSH-level failures). | `app.exec_node_unreachable`. |
| Gateway unavailable (operator mode) | CLI on a non-gateway node cannot reach the gateway exec endpoint, or the endpoint returns an unclassified failure. | `gateway_unavailable`. |

### Infra failure disambiguation

Host command execution can fail for two distinct reasons that must not be
conflated. A legitimate non-zero result from the user's command is a child
failure; the wrapper itself still succeeded. A wrapper failure (node
unreachable, missing host PHP toolchain, unreadable app path) is an infra
failure and uses a dedicated error code.

The preflight captures infra failures before the user command ever runs:

| Probe result | Outcome |
| --- | --- |
| Host PHP toolchain and app path are usable | Continue to host command execution. |
| Host PHP binary or required command shim is unavailable | `app.exec_toolchain_unavailable`. |
| App source path cannot be entered | `app.exec_toolchain_unavailable`. |
| Unknown failure | `app.exec_node_unreachable` (carries the underlying signal for diagnostics). |

The exec step is also inspected for wrapper failure signatures so a toolchain
or path failure between preflight and execution is reported as infra failure,
not as the user command's result. Any other non-zero result from the host
command flows through as the child command's result.

## Doctor Relationship

- [`doctor --family=app`](../../app-doctor.md) verifies app runtime artifacts
  for serving. Host PHP toolchain availability is node/tool substrate and is
  reported by `app:exec` as an execution prerequisite failure.

## Side Effects

`app:exec` runs whichever command the caller chose on the app node's host PHP
toolchain from the app source path. The wrapper itself does not:

- Start, stop, recreate, or mutate the runtime container.
- Write to gateway configuration.
- Pre-process or rewrite the command tokens. Each token is passed to the host
  command runner verbatim.

## Test Mapping

| Path | Coverage |
| --- | --- |
| `apps/gateway/tests/Feature/Commands/Apps/AppExecCommandTest.php` | Gateway-mode and operator-mode paths end to end (see notes). |
| `apps/gateway/tests/Feature/Http/Api/AppExecControllerTest.php` | API surface and HTTP status mapping (see notes). |
| `apps/gateway/tests/Unit/Services/Runtime/OrbitHostCwdResolverTest.php` | Host cwd resolution including `..` normalization (see notes). |

`AppExecCommandTest` must cover the points below.

- App resolution: explicit selector, `ORBIT_HOST_CWD` fallback (gateway
  mode only), and name-vs-domain precedence.
- Command tokenization.
- Child vs infra failure separation. One test must prove a child that
  prints toolchain-looking stderr but exits with a normal child status is
  treated as a child failure, not infra failure.
- Every preflight failure code plus the exec-step wrapper failures
  (`exit 126` command-not-executable and `exit 127` command-not-found).
- The `validation_failed`, `app.not_found`, and
  `app.exec_unsupported_runtime` paths.
- Operator-mode `gateway_unavailable` and success-forwarding paths.
- Cwd forwarding in operator mode. One test must prove the CLI does NOT
  query local App rows and instead forwards the raw `ORBIT_HOST_CWD`
  through the typed gateway request.

`AppExecControllerTest` must cover the points below.

- Both `POST /api/apps/{app}/exec` and `POST /api/apps/exec/by-path`.
- The full success envelope shape.
- Gateway-side cwd resolution. Tests must include the `host_cwd`
  `validation_failed` path (missing input AND unresolvable cwd) and the
  workspace-cwd rejection path (host_cwd lives in a workspace tree →
  steer caller to `workspace:exec` instead of silently dispatching the
  parent app).
- HTTP status mapping for `app.not_found` (404),
  `app.exec_unsupported_runtime` /
  `app.exec_command_not_executable` / `app.exec_command_not_found` /
  `validation_failed` (422), `app.exec_toolchain_unavailable` /
  `app.exec_node_unreachable` (502), and `authorization_failed` (403).

`OrbitHostCwdResolverTest` must cover: exact-path match, subdirectory
match, longest-prefix match for nested apps, workspace-path preference
over parent-app match, the null cases (no match, empty cwd, relative
cwd), the partial-segment guard, and lexical `..` normalization
including sibling-app traversal and parent-escape-returns-null.
