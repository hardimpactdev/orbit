# Technical Contract: `orbit php:use [version] [--app=<app>] [--workspace=<workspace>] [--node=<node>] [--inherit] [--json]`

[Back to public `php:use` documentation.](../php-use.md)

**Owner:** `php`.

**Effects:** `write`, `stream`.

**Prerequisites:**
- The CLI caller can reach the Orbit gateway, or the command is running on the gateway.
- The current node identity is authorized to manage the resolved app or workspace.

## Signature

```bash
orbit php:use [version] [--app=<app>] [--workspace=<workspace>] [--node=<node>] [--inherit] [--json]
```

## Input Contract

This command follows the shared [Invocation Model](../../../README.md#invocation-model).

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `version` | `[version]` | Required unless `inherit=true`. | `inherit=true`. | None. | Orbit-supported PHP image version available on the resolved target node. |
| `app` | `--app` | No app or workspace context resolves for app/workspace targets. | Never. | Cwd-inferred app when present. | Visible app selector. |
| `workspace` | `--workspace` | `inherit=true`, unless cwd resolves a workspace. | Never. | Cwd-inferred workspace when present. | Visible workspace selector belonging to the resolved app. |
| `inherit` | `--inherit` | Optional. | `version` present. | `false`. | Clears a workspace override only. |
| `node` | `--node` | Optional target-resolution fallback. | Never. | App/workspace owning node for runtime scope. | Visible node slug. |
| `json` | `--json` | Optional. | Never. | `false`. | Selects the JSON renderer. |

## Input Resolution

1. Resolve target scope:
   - `--workspace` or cwd workspace context selects workspace runtime.
   - `--app` or cwd app context selects app runtime.
   - With no resolved target, interactive mode prompts from authorized app,
     workspace choices; non-interactive mode fails.
2. Resolve `version` from positional input or prompt unless `--inherit` is supplied.
3. Validate mutually exclusive inputs.
4. Validate the requested version against Orbit-supported versions and
   available images on the target node.
5. Apply post-input authorization before side effects.

## Input Mode Contracts

- [Interactive input mode](5.1_php-use_input-mode_interactive.md)
- [Non-interactive input mode](5.2_php-use_input-mode_non-interactive.md)

## Behavior Contract

### App Runtime Selection

- Writes the app PHP version in gateway app configuration.
- Re-renders and applies app runtime container artifacts through the gateway-to-node SSH
  path.
- Re-renders the proxy backend artifacts owned by the app when the route target
  depends on the selected PHP runtime.
- Reports app-family drift warnings when configuration was written but app artifact
  application did not converge.
- Reports proxy-family drift warnings when proxy backend artifact convergence for
  the app did not complete.

### Workspace Runtime Selection

- Writes a workspace PHP override when `version` targets a workspace.
- Clears the workspace PHP override when `--inherit` is supplied.
- Re-renders and applies workspace runtime container artifacts through the
  gateway-to-node SSH path.
- Re-renders the proxy backend artifacts owned by the workspace when the route
  target depends on the selected PHP runtime.
- Reports workspace-family drift warnings when configuration was written but workspace
  artifact application did not converge.
- Reports proxy-family drift warnings when proxy backend artifact convergence for
  the workspace did not complete.

### Scope Boundaries

`php:use` must not install missing host PHP versions, build images, remove PHP
runtimes, mutate Composer constraints, edit project files, read `.php-version`,
change framework cache state, or create app/workspace records.

## Renderer Contracts

- [Human renderer](6.1_php-use_output-render_human.md)
- [JSON renderer](6.2_php-use_output-render_json.md)

## Failure Semantics
Standard failures defined in [Common Failures](../../../README.md#common-failures) apply; command-specific failures below.

## Doctor Relationship

`php:use` writes runtime configuration owned by other state families. App runtime drift
is verified and repaired by [`doctor --family=app`](../../../5_app/app-doctor.md).
Workspace runtime drift is verified and repaired by
[`doctor --family=workspace`](../../../6_workspace/workspace-doctor.md). PHP
image availability is verified by the runtime/image catalog and node runtime
readiness checks.
App and workspace proxy backend drift caused by runtime-target changes is
verified and repaired by [`doctor --family=proxy`](../../../8_proxy/proxy-doctor.md).

## Test Mapping

| Path | Coverage |
| --- | --- |
| `tests/Feature/Commands/Php/PhpUseCommandTest.php` | Target-scope resolution, mutual exclusion validation, version support and image-availability validation, gateway configuration writes, runtime/proxy application boundaries, and failure codes. |
| `tests/Feature/Commands/Php/PhpUseCallerRoleTest.php` | Gateway-applied authorization for the resolved app or workspace target, including denial when the authenticated WireGuard identity is not authorized to manage the target. |
| `tests/Unit/Services/Php/PhpRuntimeSelectionTest.php` | Runtime selection DTO shape, app/workspace target modeling, inheritance behavior, and app/workspace/proxy partial-application warning mapping. |
