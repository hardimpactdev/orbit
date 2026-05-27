# Technical Contract: `orbit workspace:exec [workspace] -- <command...>`

[Back to public `workspace:exec` documentation.](../workspace-exec.md)

**Owner:** `workspace`.

**Effects:** `stream`. The underlying command may read or write any state
the caller chooses to invoke; `workspace:exec` itself does not mutate
gateway configuration.

**Prerequisites:**
- The CLI caller can reach the Orbit gateway.
- The authenticated peer has `workspace:exec` on the workspace's owning
  node.
- The target workspace exists in gateway configuration and its parent app
  has `runtime_kind=php`.
- The workspace's FrankenPHP runtime container exists and is running on
  the owning node.

## Signature

```bash
orbit workspace:exec {workspace?} -- {command...} [--app=<slug>] [--json]
```

The signature uses the canonical Orbit token order. At invocation time
`--app`, `--json`, and any other Orbit option must appear BEFORE `--`,
because Symfony stops parsing options after the separator. See the
public documentation's Usage section for the working invocation form.

## Input Contract

This command follows the shared
[Invocation Model](../../../README.md#invocation-model).

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `workspace` | `[workspace]` | Non-interactive mode when `ORBIT_HOST_CWD` does not resolve to a workspace. | Never. | None. | Must resolve to an existing workspace whose parent app has `runtime_kind=php`. |
| `command` | tokens after `--` | Always. | Never. | None. | At least one non-empty token. |
| `app` | `--app=<slug>` | When the workspace name is shared across apps. | Never. | None. | Must match an existing app slug. Used to disambiguate workspace names that collide across apps. |
| `json` | `--json` | Optional. | Never. | `false`. | Selects the JSON renderer and non-interactive input mode. |

## Input Resolution

1. Resolve `command`. Reject an empty command list with `validation_failed`,
   `meta.field=command`.
2. Resolve `workspace`. The CLI does not prompt — exec is treated as a
   non-interactive command surface.
   - If `[workspace]` is supplied, forward it as the selector. When the
     name matches workspaces in multiple apps and `--app` is not supplied,
     fail with `workspace.ambiguous_name`. When `--app` is supplied,
     scope the lookup to that app.
   - Otherwise, forward `ORBIT_HOST_CWD` from the host launcher; the
     gateway resolves it server-side. Workspace match always wins over
     parent-app match.
   - If neither `[workspace]` nor a resolvable `ORBIT_HOST_CWD` is
     available, fail with `validation_failed`, `meta.field=workspace`.
3. The gateway validates the target workspace exists in gateway
   configuration.
4. The gateway validates the parent app uses `runtime_kind=php`.
   Workspaces whose parent app is not PHP cannot be targets and fail
   with `workspace.exec_unsupported_runtime`.

## State Model

`workspace:exec` does not own durable gateway state. It reads the canonical
workspace entity (and its parent app) to resolve container identity and
authorization context. The command relies on the
[workspace runtime container](../../workspace-concepts.md#workspace-runtime-container)
being present on the owning node.

The container name is the same `orbit-ws-<app>-<workspace>` value the
workspace runtime renderer produces. The command does not maintain a
separate registry of container names.

## Renderer Selection

| Mode | Renderer |
| --- | --- |
| Default | [Human output](6.1_workspace-exec_output-render_human.md) |
| `--json` | [JSON output](6.2_workspace-exec_output-render_json.md) |

## API Surface

The gateway HTTP API mirrors the command and exposes two entry points:

| Method | Path | Permission | Action |
| --- | --- | --- | --- |
| `POST` | `/api/workspaces/{name}/exec` | `workspace:exec` | Run a command inside the workspace's runtime container. Optional `?app=<slug>` query disambiguates collisions. Body: `command` array. |
| `POST` | `/api/workspaces/exec/by-path` | `workspace:exec` | Resolve the target workspace from a launcher-supplied host cwd and run the command. Body: `host_cwd` string plus `command` array. |

CLI control-mode invocations use the by-path endpoint when no explicit
selector is supplied, forwarding the raw `ORBIT_HOST_CWD` so the
authoritative workspace identity is resolved on the gateway.

API responses share the JSON envelope and error codes documented in the
[JSON renderer contract](6.2_workspace-exec_output-render_json.md).

HTTP status mapping:

| `error.code` | HTTP status |
| --- | --- |
| `workspace.not_found` | 404 |
| `workspace.ambiguous_name` | 400 |
| `workspace.exec_unsupported_runtime` | 422 |
| `workspace.exec_container_not_running` | 422 |
| `workspace.exec_command_not_executable` | 422 |
| `workspace.exec_command_not_found` | 422 |
| `validation_failed` | 422 |
| `workspace.exec_docker_unavailable` | 502 |
| `workspace.exec_node_unreachable` | 502 |
| `authorization_failed` | 403 |

## Behavior Contract

### Execution Rules

1. **Gateway is the authority.** CLI invocations on non-gateway nodes
   forward through the gateway typed API at
   `POST /api/workspaces/{name}/exec`. The CLI never resolves gateway-owned
   state directly when running in control mode; the gateway authorizes the
   request and orchestrates the `RemoteShell` call into the owning node.
   Only the gateway-local CLI path runs `RemoteShell` itself.
2. **Container is the workspace runtime container.** `workspace:exec` runs
   the command inside the `orbit-ws-<app>-<workspace>` FrankenPHP runtime
   container on the owning node. The container is the same one rendered by
   the workspace runtime renderer; the command does not start, stop, or
   recreate it.
3. **PHP-only.** Workspaces whose parent app has `runtime_kind != php`
   cannot be exec targets. `workspace:exec` fails with
   `workspace.exec_unsupported_runtime`.
4. **Container must be running.** A preflight `docker container inspect`
   runs before `docker exec`. When the preflight or the exec step reports
   the container is missing or not running, `workspace:exec` fails with
   `workspace.exec_container_not_running`. The command does not implicitly
   start the container; that is `doctor --family=workspace` territory.
5. **Working directory is the container's workspace mount.** The command
   runs with the container's default working directory at the workspace
   source mount target. Callers do not pick a working directory; that is
   the container's contract.
6. **No token rewriting.** The command tokens are passed to the container
   exec verbatim. `workspace:exec` does not interpret, expand, or
   substitute tokens.

### Host Cwd Resolution

`ORBIT_HOST_CWD` is the launcher-supplied host working directory.
Resolution is the gateway's responsibility, not the CLI's: control-mode
callers forward the raw cwd string in the request body and the gateway
queries its workspace registry for the canonical workspace entity whose
`path` is an exact match for, or a parent of, the host cwd. Workspace
match wins over parent-app match so a working directory under
`apps/docs/.worktrees/docs-feature` resolves to workspace `docs-feature`
even when app `docs` would also match its source path.

The CLI does not mount every workspace path into `orbit-runtime`, and it
does not query local SQLite for gateway-owned workspace or app state when
running in control mode. Only the gateway-local CLI invocation reads
gateway state directly because it IS the gateway.

## Failure Semantics

Standard failures defined in
[Common Failures](../../../README.md#common-failures) apply; command-specific
failures below.

| Failure | Condition | Outcome |
| --- | --- | --- |
| Missing command | The `command` token list is empty after parsing. | `validation_failed`, `meta.field=command`. |
| Missing workspace | Non-interactive mode and `ORBIT_HOST_CWD` does not resolve to a workspace. | `validation_failed`, `meta.field=workspace`. |
| Ambiguous workspace name | The workspace name matches multiple apps and `--app` (CLI) or `?app=` (API) is not supplied. | `workspace.ambiguous_name`. |
| Workspace not found | No workspace record matches `workspace`. | `workspace.not_found`. |
| Unsupported runtime | The parent app has `runtime_kind != php`. | `workspace.exec_unsupported_runtime`. |
| Container not running | Preflight or exec reports the container is missing or stopped. | `workspace.exec_container_not_running`. |
| Command not executable | Docker exec returns exit code `126` — the target is in the container but is not executable (permission denied, wrong architecture). | `workspace.exec_command_not_executable`. |
| Command not found | Docker exec returns exit code `127` — the target is not present in the container's `$PATH`. | `workspace.exec_command_not_found`. |
| Docker unavailable | Preflight or exec reports the docker daemon is unreachable. | `workspace.exec_docker_unavailable`. |
| Node unreachable | Preflight returns an unknown failure before docker can answer (typical SSH-level failures). | `workspace.exec_node_unreachable`. |
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
| Container is in any other state | `workspace.exec_container_not_running` (carries the observed state). |
| Docker daemon-down stderr signature | `workspace.exec_docker_unavailable`. |
| `No such container` / `No such object` / `is not running` stderr signature | `workspace.exec_container_not_running`. |
| Unknown failure | `workspace.exec_node_unreachable` (carries the underlying signal for diagnostics). |

The exec step is also inspected for docker-wrapper failure signatures so
a container that vanished between preflight and exec is reported as an
infra failure, not as the user command's result. Any other non-zero
result from `docker exec` flows through as the child command's result.

## Doctor Relationship

- [`doctor --family=workspace`](../../workspace-doctor.md) verifies the
  FrankenPHP workspace runtime container is present and running.
  `workspace.exec_container_not_running` failures from `workspace:exec`
  are repaired through doctor, not through `workspace:exec` itself.

## Side Effects

`workspace:exec` runs whichever command the caller chose, inside the
workspace runtime container. The wrapper itself does not:

- Start, stop, recreate, or mutate the runtime container.
- Write to gateway configuration.
- Pre-process or rewrite the command tokens. Each token is passed to the
  container exec verbatim.

## Test Mapping

| Path | Coverage |
| --- | --- |
| `apps/gateway/tests/Feature/Commands/Workspaces/WorkspaceExecCommandTest.php` | Gateway-mode and control-mode paths end to end (see notes). |
| `apps/gateway/tests/Feature/Http/Api/WorkspaceExecControllerTest.php` | API surface and HTTP status mapping (see notes). |
| `apps/gateway/tests/Unit/Services/Runtime/OrbitHostCwdResolverTest.php` | Workspace-preference resolution including `..` normalization (see notes). |

`WorkspaceExecCommandTest` must cover the points below.

- Workspace resolution: explicit selector, `ORBIT_HOST_CWD` fallback
  (gateway mode only), and workspace-wins-over-parent-app match.
- `--app` disambiguation. Tests must include the
  ambiguous-without-`--app` rejection and the `--app`-supplied success
  case.
- Command tokenization.
- Child vs infra failure separation. One test must prove a child that
  prints Docker-daemon stderr but exits with a code that is not 125 is
  treated as a child failure, not infra failure.
- Every preflight failure code plus the exec-step wrapper failures
  (`exit 125` container-vanished, `exit 126` command-not-executable,
  `exit 127` command-not-found).
- The `validation_failed`, `workspace.not_found`, and
  `workspace.exec_unsupported_runtime` paths.
- Control-mode `gateway_unavailable` and success-forwarding paths.
- Cwd forwarding in control mode. One test must prove the CLI does NOT
  query local Workspace rows and instead forwards the raw
  `ORBIT_HOST_CWD` through the typed gateway request.

`WorkspaceExecControllerTest` must cover the points below.

- Both `POST /api/workspaces/{name}/exec` and `POST
  /api/workspaces/exec/by-path`.
- The full success envelope shape.
- `?app=` disambiguation. Tests must include the
  `workspace.ambiguous_name` rejection without `?app=` and the
  `?app=`-supplied success case.
- Gateway-side cwd resolution. Tests must include the `host_cwd`
  `validation_failed` paths for missing input and for unresolvable cwd
  (the API surface must NOT return `*.not_found` when the input is a
  malformed path).
- HTTP status mapping for `workspace.not_found` (404),
  `workspace.ambiguous_name` (400),
  `workspace.exec_unsupported_runtime` /
  `workspace.exec_container_not_running` /
  `workspace.exec_command_not_executable` /
  `workspace.exec_command_not_found` / `validation_failed` (422),
  `workspace.exec_docker_unavailable` /
  `workspace.exec_node_unreachable` (502), and `authorization_failed`
  (403).

`OrbitHostCwdResolverTest` must cover: workspace-vs-app preference for
nested workspace paths, exact and subdirectory matches, the null cases
(no match, empty cwd, relative cwd), and lexical `..` normalization
including the parent-escape-returns-null case.
