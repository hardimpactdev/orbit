# Technical Contract: `orbit php:use [version] [--instance=<app.instance>] [--workspace=<workspace>] [--node=<node>] [--inherit] [--cli] [--json]`

[Back to public `php:use` documentation.](../php-use.md)

**Owner:** `php`.

**Effects:** `write`, `stream`.

**Prerequisites:**
- The CLI caller can reach the Orbit gateway, or the command is running on the gateway.
- The current node identity has `php:write` on the selected instance serving
  node for an app write, or on the resolved workspace
  or node-CLI target. Gateway identity remains implicit.

## Signature

```bash
orbit php:use [version] [--instance=<app.instance>] [--workspace=<workspace>] [--node=<node>] [--inherit] [--cli] [--json]
```

## Input Contract

This command follows the shared [Invocation Model](../../../README.md#invocation-model).

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `version` | `[version]` | Required unless `inherit=true`. | `inherit=true`. | None. | Orbit-supported PHP image version available on the selected instance serving node, or on the resolved workspace node. For `cli=true`, only `8.5` is supported. |
| `instance` | `--instance` | No instance or workspace context resolves for instance/workspace targets. | Never. | Cwd-inferred selector when present. | Visible `<app.instance>` selector. A bare project is accepted only when it resolves unambiguously to one concrete instance. The write changes the parent project's shared PHP policy. |
| `workspace` | `--workspace` | `inherit=true`, unless cwd resolves a workspace. | Never. | Cwd-inferred workspace when present. | Visible workspace selector belonging to the resolved project and instance. |
| `inherit` | `--inherit` | Optional. | `version` present. | `false`. | Clears a workspace override only. |
| `cli` | `--cli` | Optional. | `instance`, `workspace`, or `inherit` present. | `false`. | Selects the node CLI PHP default; only PHP 8.5 is supported. |
| `node` | `--node` | Optional. | Project policy target. | Concrete workspace serving node for workspace scope; default node for CLI scope. | Visible node slug. For a workspace target it may only confirm placement; mismatches fail with `error.meta.reason=target_mismatch` before any writes. |
| `json` | `--json` | Optional. | Never. | `false`. | Selects the JSON renderer. |

## Input Resolution

1. Resolve target scope:
   - `--cli` selects node CLI runtime intent.
   - `--workspace` or cwd workspace context selects workspace runtime.
   - `--instance` or cwd instance context selects the parent project runtime policy.
   - With no resolved target, interactive mode prompts from authorized instance,
     workspace choices; non-interactive mode fails.
2. Resolve `version` from positional input or prompt unless `--inherit` is supplied.
3. Validate mutually exclusive inputs.
4. For an instance target, authorize `php:write` on its serving node and verify
   the approved image there. Refresh stale inventory before rejecting. Any
   denial, unavailable inventory, or missing image stops before the shared
   project-policy write.
5. For a workspace target, authorize and verify only its concrete serving node.
   For CLI scope, accept only PHP 8.5 and use the host toolchain boundary.
6. Begin side effects only after the complete target-specific preflight passes.

## Input Mode Contracts

- [Interactive input mode](5.1_php-use_input-mode_interactive.md)
- [Non-interactive input mode](5.2_php-use_input-mode_non-interactive.md)

## Behavior Contract

### Instance Runtime Selection

- Writes the shared project PHP version after the selected instance has passed
  authorization and image preflight.
- Reconciles app runtime-container and affected proxy-backend artifacts for the
  parent project after changing its shared policy.
- Returns the selected `project`, `instance`, `node`, `version`, `image`, and
  `changed` result facts.

### Workspace Runtime Selection

- Requires the workspace to resolve to an active `app-dev` serving node and
  rejects `app-prod` callers with `workspace.unsupported_for_production`
  before inventory, configuration, proxy, or runtime effects.
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
- Does not mutate instance or workspace FrankenPHP runtime versions, container
  artifacts, or proxy backends.
- Reports `success.data.result.changed=false` when the node CLI default is
  already PHP 8.5. Node CLI results omit FrankenPHP `image` data.

### Scope Boundaries

`php:use` must not install missing host PHP versions, build images, remove PHP
runtimes, mutate Composer constraints, edit project files, read `.php-version`,
change framework cache state, or create project/instance/workspace records.

## Renderer Contracts

- [Human renderer](6.1_php-use_output-render_human.md)
- [JSON renderer](6.2_php-use_output-render_json.md)

## Failure Semantics
Standard failures defined in [Common Failures](../../../README.md#common-failures) apply; command-specific failures below.

For instance and workspace runtime selection, a successful inventory probe that does
not find the approved image fails validation as a confirmed missing image. An
unreachable provider or otherwise failed inventory probe reports image
inventory unavailability and does not claim that the image is missing.

## Doctor Relationship

`php:use` writes runtime configuration owned by other state families. Instance runtime drift
is verified and repaired by [`doctor --family=instance`](../../../5_app/instance-doctor.md).
Workspace runtime drift is verified and repaired by
[`doctor --family=workspace`](../../../6_workspace/workspace-doctor.md). PHP
image availability is verified by the runtime/image catalog and node runtime
readiness checks.
Instance and workspace proxy backend drift caused by runtime-target changes is
verified and repaired by [`doctor --family=proxy`](../../../8_proxy/proxy-doctor.md).

## Test Mapping

| Path | Coverage |
| --- | --- |
| `apps/cli/tests/Feature/Commands/Php/PhpUseCommandTest.php` | CLI posts instance/workspace selections, inherit semantics, mutual exclusion validation, result rendering, and gateway error pass-through. |
| `apps/gateway/tests/Feature/Http/Api/PhpRuntimeControllerTest.php` | Serving-node authorization, image preflight before mutation, concrete workspace placement, wrong-permission denial, and gateway implicit authority. |
| `apps/gateway/tests/Unit/Services/Php/PhpRuntimeManagerTest.php` | Shared project-policy selection, concrete instance results, and workspace selection/inheritance behavior. |
