# Technical Contract: `orbit php:use [version] [--app=<app>] [--workspace=<workspace>] [--node=<node>] [--inherit] [--cli] [--json]`

[Back to public `php:use` documentation.](../php-use.md)

**Owner:** `php`.

**Effects:** `write`, `stream`.

**Prerequisites:**
- The CLI caller can reach the Orbit gateway, or the command is running on the gateway.
- The current node identity is authorized to manage the resolved app, workspace, or node CLI target.

## Signature

```bash
orbit php:use [version] [--app=<app>] [--workspace=<workspace>] [--node=<node>] [--inherit] [--cli] [--json]
```

## Input Contract

This command follows the shared [Invocation Model](../../../README.md#invocation-model).

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `version` | `[version]` | Required unless `inherit=true`. | `inherit=true`. | None. | Orbit-supported PHP version installed on the resolved target node. |
| `app` | `--app` | No app or workspace context resolves for app/workspace targets. | `cli=true`. | Cwd-inferred app when present. | Visible app selector. |
| `workspace` | `--workspace` | `inherit=true`, unless cwd resolves a workspace. | `cli=true`. | Cwd-inferred workspace when present. | Visible workspace selector belonging to the resolved app. |
| `inherit` | `--inherit` | Optional. | `version` present or `cli=true`. | `false`. | Clears a workspace override only. |
| `cli` | `--cli` | Optional. | `app`, `workspace`, or `inherit` present. | `false`. | Selects node CLI default scope. |
| `node` | `--node` | Required for `cli=true` when no local node target resolves. | Never. | Local `node:default` for CLI scope, or app/workspace owning node for runtime scope. | Visible node slug. |
| `json` | `--json` | Optional. | Never. | `false`. | Selects the JSON renderer. |

## Authorization By Caller Role

The CLI is a thin gateway client. The gateway authenticates the caller's WireGuard peer identity and applies authorization. Callers may change PHP runtime selection only when the gateway authorizes their identity to manage the resolved app, workspace, or node CLI target. Local cwd app or workspace context is used as input hint only and never grants write authority. Management remains gateway-owned and applied through gateway-to-node transport.

## Input Resolution

1. Resolve target scope:
   - `--cli` selects node CLI default.
   - `--workspace` or cwd workspace context selects workspace runtime.
   - `--app` or cwd app context selects app runtime.
   - With no resolved target, interactive mode prompts from authorized app,
     workspace, and node CLI choices; non-interactive mode fails.
2. Resolve `version` from positional input or prompt unless `--inherit` is supplied.
3. Validate mutually exclusive inputs.
4. Validate the requested version against Orbit-supported versions and installed
   versions on the target node.
5. Apply post-input authorization before side effects.

## Input Mode Contracts

- [Interactive input mode](5.1_php-use_input-mode_interactive.md)
- [Non-interactive input mode](5.2_php-use_input-mode_non-interactive.md)

## Behavior Contract

### App Runtime Selection

- Writes the app PHP version in gateway app configuration.
- Re-renders and applies app PHP-FPM artifacts through the gateway-to-node SSH
  path.
- Re-renders affected app-owned proxy backend artifacts when the route target
  depends on the selected PHP runtime.
- Reports app-family drift warnings when configuration was written but app artifact
  application did not converge.
- Reports proxy-family drift warnings when app-owned proxy backend artifact
  convergence did not complete.

### Workspace Runtime Selection

- Writes a workspace PHP override when `version` targets a workspace.
- Clears the workspace PHP override when `--inherit` is supplied.
- Re-renders and applies workspace PHP-FPM artifacts through the
  gateway-to-node SSH path.
- Re-renders affected workspace-owned proxy backend artifacts when the route
  target depends on the selected PHP runtime.
- Reports workspace-family drift warnings when configuration was written but workspace
  artifact application did not converge.
- Reports proxy-family drift warnings when workspace-owned proxy backend
  artifact convergence did not complete.

### Node CLI Selection

- Writes node CLI PHP default configuration.
- Updates the target node's default `php` binary through the gateway-to-node SSH
  path.
- Reports `node.cli_php_default_mismatch` when configuration was written but node CLI
  application did not converge.

### Scope Boundaries

`php:use` must not install missing PHP versions, remove PHP runtimes, mutate
Composer constraints, edit project files, read `.php-version`, change framework
cache state, or create app/workspace records.

## Renderer Contracts

- [Human renderer](6.1_php-use_output-render_human.md)
- [JSON renderer](6.2_php-use_output-render_json.md)

## Failure Semantics

| Failure | Condition | Outcome |
| --- | --- | --- |
| Validation failed | Required input is missing, mutually exclusive input is supplied, or a requested version is unsupported or not installed on the target node. | `error.code=validation_failed` |
| Gateway unavailable | The CLI cannot reach the gateway API. | `error.code=gateway_unavailable` |
| Authorization failed | The caller is not authorized to manage the resolved target. | `error.code=authorization_failed` |

## Doctor Relationship

`php:use` writes runtime configuration owned by other state families. App runtime drift
is verified and repaired by [`doctor --family=app`](../../../5_app/app-doctor.md).
Workspace runtime drift is verified and repaired by
[`doctor --family=workspace`](../../../6_workspace/workspace-doctor.md). Node
CLI default drift is verified and repaired by
[`doctor --family=node`](../../../1_node/node-doctor.md). Installed PHP runtime
availability is verified by [`doctor --family=tool`](../../../3_tool/tool-doctor.md).
App and workspace proxy backend drift caused by runtime-target changes is
verified and repaired by [`doctor --family=proxy`](../../../8_proxy/proxy-doctor.md).

## Test Mapping

| Path | Coverage |
| --- | --- |
| `tests/Feature/Commands/Php/PhpUseCommandTest.php` | Target-scope resolution, mutual exclusion validation, version support and installed-version validation, gateway configuration writes, node and proxy application boundaries, and failure codes. |
| `tests/Feature/Commands/Php/PhpUseCallerRoleTest.php` | Gateway-applied authorization for the resolved app, workspace, and node CLI target, including denial when the authenticated WireGuard identity is not authorized to manage the target. |
| `tests/Unit/Services/Php/PhpRuntimeSelectionTest.php` | Runtime selection DTO shape, app/workspace/node target modeling, inheritance behavior, and app/workspace/proxy/node partial-application warning mapping. |
