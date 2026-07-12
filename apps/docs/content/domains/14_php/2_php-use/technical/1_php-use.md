# Technical Contract: `orbit php:use [version] [--app=<app>] [--workspace=<workspace>] [--node=<node>] [--inherit] [--cli] [--json]`

[Back to public `php:use` documentation.](../php-use.md)

**Owner:** `php`.

**Effects:** `write`, `stream`.

**Prerequisites:**
- The CLI caller can reach the Orbit gateway, or the command is running on the gateway.
- The current node identity has `php:write` granted on the resolved target,
  app-instance serving node, or workspace-instance serving node. Gateway
  identity remains implicit.

## Signature

```bash
orbit php:use [version] [--app=<app>] [--workspace=<workspace>] [--node=<node>] [--inherit] [--cli] [--json]
```

## Input Contract

This command follows the shared [Invocation Model](../../../README.md#invocation-model).

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `version` | `[version]` | Required unless `inherit=true`. | `inherit=true`. | None. | Orbit-supported PHP image version available on the resolved target node. For `cli=true`, only `8.5` is supported. |
| `app` | `--app` | No app or workspace context resolves for app/workspace targets. | Never. | Cwd-inferred app when present. | Visible app selector. |
| `workspace` | `--workspace` | `inherit=true`, unless cwd resolves a workspace. | Never. | Cwd-inferred workspace when present. | Visible workspace selector belonging to the resolved app. |
| `inherit` | `--inherit` | Optional. | `version` present. | `false`. | Clears a workspace override only. |
| `cli` | `--cli` | Optional. | `app`, `workspace`, or `inherit` present. | `false`. | Selects the node CLI PHP default; only PHP 8.5 is supported. |
| `node` | `--node` | Optional. | Never. | Concrete app/workspace serving node for runtime scope; default node for CLI scope. | Visible node slug. For app and workspace targets, may only confirm the instance placement; mismatches fail with `error.meta.reason=target_mismatch` before any writes. |
| `json` | `--json` | Optional. | Never. | `false`. | Selects the JSON renderer. |

## Input Resolution

1. Resolve target scope:
   - `--cli` selects node CLI runtime intent.
   - `--workspace` or cwd workspace context selects workspace runtime.
   - `--app` or cwd app context selects app runtime.
   - With no resolved target, interactive mode prompts from authorized app,
     workspace choices; non-interactive mode fails.
2. Resolve `version` from positional input or prompt unless `--inherit` is supplied.
3. Validate mutually exclusive inputs.
4. Validate the requested version against Orbit-supported versions and
   available images on the target node. CLI scope accepts only PHP 8.5.
5. Apply post-input authorization before side effects.

For app and workspace runtime targets, the target node comes from the concrete
app instance or workspace placement. An explicit `--node` may only confirm
that serving node; it must not supply image facts from another node.

## Input Mode Contracts

- [Interactive input mode](5.1_php-use_input-mode_interactive.md)
- [Non-interactive input mode](5.2_php-use_input-mode_non-interactive.md)

## Behavior Contract

### App Runtime Selection

- Writes the app PHP version in gateway app configuration.
- Re-renders and applies app runtime container artifacts through Agent push.
- Re-renders the proxy backend artifacts owned by the app when the route target
  depends on the selected PHP runtime.
- Reports app-family drift warnings when configuration was written but app artifact
  application did not converge.
- Reports proxy-family drift warnings when proxy backend artifact convergence for
  the app did not complete.

### Workspace Runtime Selection

- Writes a workspace PHP override when `version` targets a workspace.
- Clears the workspace PHP override when `--inherit` is supplied.
- Re-renders and applies workspace runtime container artifacts through Agent
  push.
- Re-renders the proxy backend artifacts owned by the workspace when the route
  target depends on the selected PHP runtime.
- Reports workspace-family drift warnings when configuration was written but workspace
  artifact application did not converge.
- Reports proxy-family drift warnings when proxy backend artifact convergence for
  the workspace did not complete.

### Node CLI Selection

- Writes the node CLI PHP default in gateway `php` tool facts as `cli_version`.
- Validates the target node through the host `php-cli` toolchain boundary, not
  FrankenPHP image availability. The command requires the `php-cli` tool to be
  installed on the target node before side effects begin.
- Accepts only PHP 8.5, matching the production native Orbit CLI binary
  artifact's embedded PHP version and the `orbit-gateway` image baseline.
  Source-dev Docker/Incus development and E2E nodes invoke
  `<source>/apps/cli/orbit`.
- Does not mutate app or workspace FrankenPHP runtime versions, container
  artifacts, or proxy backends.
- Reports `success.data.result.changed=false` when the node CLI default is
  already PHP 8.5. Node CLI results omit FrankenPHP `image` data.

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
| `apps/cli/tests/Feature/Commands/Php/PhpUseCommandTest.php` | CLI posts version selections for app/workspace targets, inherit semantics, mutual exclusion validation, and gateway error pass-through. |
| `apps/gateway/tests/Feature/Http/Api/PhpRuntimeControllerTest.php` | Permission-specific authorization for resolved app/workspace targets, concrete placement, wrong-permission denial, and gateway implicit authority. |
| `apps/gateway/tests/Unit/Services/Php/PhpRuntimeManagerTest.php` | Runtime selection and `view()` selection/inheritance behavior. |
