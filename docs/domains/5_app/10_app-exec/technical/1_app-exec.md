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
- The app's FrankenPHP runtime container exists and is running on the owning
  node.

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
   - Otherwise, forward `ORBIT_HOST_CWD` from the host launcher; the
     gateway resolves it server-side to a canonical app entity (workspace
     paths under an app do not resolve as the parent app — they belong to
     `workspace:exec`).
   - If neither `[app]` nor a resolvable `ORBIT_HOST_CWD` is available,
     fail with `validation_failed`, `meta.field=app`.
3. The gateway validates the target app exists in gateway configuration.
4. The gateway validates the target app uses `runtime_kind=php`. Static
   apps cannot be targets and fail with `app.exec_unsupported_runtime`.

## State Model

`app:exec` does not own durable gateway state. It reads the canonical app
entity to resolve container identity and authorization context. The command
relies on the
[app runtime container](../../app-concepts.md#app-runtime-container)
being present on the owning node.

The container name is the same `orbit-app-<slug>` value the app runtime
renderer produces. The command does not maintain a separate registry of
container names.

## Renderer Selection

| Mode | Renderer |
| --- | --- |
| Default | [Human output](6.1_app-exec_output-render_human.md) |
| `--json` | [JSON output](6.2_app-exec_output-render_json.md) |

## API Surface

The gateway HTTP API mirrors the command and exposes two entry points:

| Method | Path | Permission | Action |
| --- | --- | --- | --- |
| `POST` | `/api/apps/{app}/exec` | `app:exec` | Run a command inside the app's runtime container. Body: `command` array of one or more non-empty string tokens. |
| `POST` | `/api/apps/exec/by-path` | `app:exec` | Resolve the target app from a launcher-supplied host cwd and run the command. Body: `host_cwd` string plus `command` array. |

CLI control-mode invocations use the by-path endpoint when no explicit
selector is supplied, forwarding the raw `ORBIT_HOST_CWD` so the
authoritative app/workspace identity is resolved on the gateway.

API responses share the JSON envelope and error codes documented in the
[JSON renderer contract](6.2_app-exec_output-render_json.md).

HTTP status mapping:

| `error.code` | HTTP status |
| --- | --- |
| `app.not_found` | 404 |
| `app.exec_unsupported_runtime` | 422 |
| `app.exec_container_not_running` | 422 |
| `app.exec_command_not_executable` | 422 |
| `app.exec_command_not_found` | 422 |
| `validation_failed` | 422 |
| `app.exec_docker_unavailable` | 502 |
| `app.exec_node_unreachable` | 502 |
| `authorization_failed` | 403 |

## Behavior Contract

### Execution Rules

1. **Gateway is the authority.** CLI invocations on non-gateway nodes
   forward through the gateway typed API at `POST /api/apps/{app}/exec`.
   The CLI never resolves gateway-owned state directly when running in
   control mode; the gateway authorizes the request and orchestrates the
   `RemoteShell` call into the owning node. Only the gateway-local CLI
   path runs `RemoteShell` itself.
2. **Container is the runtime container.** `app:exec` runs the command
   inside the `orbit-app-<slug>` FrankenPHP runtime container on the app's
   owning node. The container is the same one rendered by the app runtime
   renderer; the command does not start, stop, or recreate it.
3. **PHP-only.** Apps with `runtime_kind != php` cannot be exec targets.
   `app:exec` fails with `app.exec_unsupported_runtime`.
4. **Container must be running.** A preflight `docker container inspect`
   runs before `docker exec`. When the preflight or the exec step reports
   the container is missing or not running, `app:exec` fails with
   `app.exec_container_not_running`. The command does not implicitly start
   the container; that is `doctor --family=app` territory.
5. **Working directory is the container's app mount.** The command runs
   with the container's default working directory at the source mount
   target. Callers do not pick a working directory; that is the
   container's contract.
6. **No token rewriting.** The command tokens are passed to the container
   exec verbatim. `app:exec` does not interpret, expand, or substitute
   tokens.

### Host Cwd Resolution

`ORBIT_HOST_CWD` is the launcher-supplied host working directory.
Resolution is the gateway's responsibility, not the CLI's: control-mode
callers forward the raw cwd string in the request body and the gateway
queries its app registry for the canonical app entity whose `path` is an
exact match for, or a parent of, the host cwd. If multiple app paths
match (one app nested under another), the longest matching path wins so
the most specific app is selected.

The CLI does not mount every app path into `orbit-runtime`, and it does
not query local SQLite for gateway-owned app or workspace state when
running in control mode. Only the gateway-local CLI invocation reads
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
| Container not running | Preflight or exec reports the container is missing or stopped. | `app.exec_container_not_running`. |
| Command not executable | Docker exec returns exit code `126` — the target is in the container but is not executable (permission denied, wrong architecture). | `app.exec_command_not_executable`. |
| Command not found | Docker exec returns exit code `127` — the target is not present in the container's `$PATH`. | `app.exec_command_not_found`. |
| Docker unavailable | Preflight or exec reports the docker daemon is unreachable. | `app.exec_docker_unavailable`. |
| Node unreachable | Preflight returns an unknown failure before docker can answer (typical SSH-level failures). | `app.exec_node_unreachable`. |
| Gateway unavailable (control mode) | CLI on a non-gateway node cannot reach the gateway exec endpoint, or the endpoint returns an unclassified failure. | `gateway_unavailable`. |

### Infra failure disambiguation

Docker exec can fail for two distinct reasons that must not be conflated.
A legitimate non-zero result from the user's command is a child failure;
the wrapper itself still succeeded. A docker-wrapper failure (node
unreachable, docker daemon down, container vanished mid-flight) is an
infra failure and uses a dedicated error code.

The preflight `docker container inspect` captures infra failures before
the user command ever runs:

| Probe result | Outcome |
| --- | --- |
| Container is running | Continue to exec. |
| Container is in any other state | `app.exec_container_not_running` (carries the observed state). |
| Docker daemon-down stderr signature | `app.exec_docker_unavailable`. |
| `No such container` / `No such object` / `is not running` stderr signature | `app.exec_container_not_running`. |
| Unknown failure | `app.exec_node_unreachable` (carries the underlying signal for diagnostics). |

The exec step is also inspected for docker-wrapper failure signatures so
a container that vanished between preflight and exec is reported as an
infra failure, not as the user command's result. Any other non-zero
result from `docker exec` flows through as the child command's result.

## Doctor Relationship

- [`doctor --family=app`](../../app-doctor.md) verifies the FrankenPHP app
  runtime container is present and running.
  `app.exec_container_not_running` failures from `app:exec` are repaired
  through doctor, not through `app:exec` itself.

## Side Effects

`app:exec` runs whichever command the caller chose, inside the runtime
container. The wrapper itself does not:

- Start, stop, recreate, or mutate the runtime container.
- Write to gateway configuration.
- Pre-process or rewrite the command tokens. Each token is passed to the
  container exec verbatim.

## Test Mapping

| Path | Coverage |
| --- | --- |
| `apps/gateway/tests/Feature/Commands/Apps/AppExecCommandTest.php` | Gateway-mode and control-mode paths end to end (see notes). |
| `apps/gateway/tests/Feature/Http/Api/AppExecControllerTest.php` | API surface and HTTP status mapping (see notes). |
| `apps/gateway/tests/Unit/Services/Runtime/OrbitHostCwdResolverTest.php` | Host cwd resolution including `..` normalization (see notes). |

`AppExecCommandTest` must cover the points below.

- App resolution: explicit selector, `ORBIT_HOST_CWD` fallback (gateway
  mode only), and name-vs-domain precedence.
- Command tokenization.
- Child vs infra failure separation. One test must prove a child that
  prints Docker-daemon stderr but exits with a code that is not 125 is
  treated as a child failure, not infra failure.
- Every preflight failure code plus the exec-step wrapper failures
  (`exit 125` container-vanished, `exit 126` command-not-executable,
  `exit 127` command-not-found).
- The `validation_failed`, `app.not_found`, and
  `app.exec_unsupported_runtime` paths.
- Control-mode `gateway_unavailable` and success-forwarding paths.
- Cwd forwarding in control mode. One test must prove the CLI does NOT
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
  `app.exec_unsupported_runtime` / `app.exec_container_not_running` /
  `app.exec_command_not_executable` / `app.exec_command_not_found` /
  `validation_failed` (422), `app.exec_docker_unavailable` /
  `app.exec_node_unreachable` (502), and `authorization_failed` (403).

`OrbitHostCwdResolverTest` must cover: exact-path match, subdirectory
match, longest-prefix match for nested apps, workspace-path preference
over parent-app match, the null cases (no match, empty cwd, relative
cwd), the partial-segment guard, and lexical `..` normalization
including sibling-app traversal and parent-escape-returns-null.
