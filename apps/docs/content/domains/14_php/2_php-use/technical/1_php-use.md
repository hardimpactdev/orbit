# Technical Contract: `orbit php:use [version] [--instance=<project.instance>] [--workspace=<workspace>] [--node=<node>] [--inherit] [--cli] [--json]`

[Back to public `php:use` documentation.](../php-use.md)

**Owner:** `php`.

**Effects:** `write`, `stream`.

**Prerequisites:**
- The CLI caller can reach the Orbit gateway, or the command is running on the gateway.
- The current node identity has `php:write` on every affected Orbit app
  instance serving node for a project write, or on the resolved workspace
  or node-CLI target. Gateway identity remains implicit.

## Signature

```bash
orbit php:use [version] [--instance=<project.instance>] [--workspace=<workspace>] [--node=<node>] [--inherit] [--cli] [--json]
```

## Input Contract

This command follows the shared [Invocation Model](../../../README.md#invocation-model).

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `version` | `[version]` | Required unless `inherit=true`. | `inherit=true`. | None. | Orbit-supported PHP image version available on every affected Orbit instance serving node for an app target, or on the resolved workspace node. For `cli=true`, only `8.5` is supported. |
| `app` | `--instance` | No app or workspace context resolves for app/workspace targets. | Never. | Cwd-inferred project when present. | Visible project selector for app policy; workspace resolution still selects one concrete instance. |
| `workspace` | `--workspace` | `inherit=true`, unless cwd resolves a workspace. | Never. | Cwd-inferred workspace when present. | Visible workspace selector belonging to the resolved app. |
| `inherit` | `--inherit` | Optional. | `version` present. | `false`. | Clears a workspace override only. |
| `cli` | `--cli` | Optional. | `app`, `workspace`, or `inherit` present. | `false`. | Selects the node CLI PHP default; only PHP 8.5 is supported. |
| `node` | `--node` | Optional. | Project policy target. | Concrete workspace serving node for workspace scope; default node for CLI scope. | Visible node slug. For a workspace target it may only confirm placement; mismatches fail with `error.meta.reason=target_mismatch` before any writes. |
| `json` | `--json` | Optional. | Never. | `false`. | Selects the JSON renderer. |

## Input Resolution

1. Resolve target scope:
   - `--cli` selects node CLI runtime intent.
   - `--workspace` or cwd workspace context selects workspace runtime.
   - `--instance` or cwd app context selects app runtime.
   - With no resolved target, interactive mode prompts from authorized app,
     workspace choices; non-interactive mode fails.
2. Resolve `version` from positional input or prompt unless `--inherit` is supplied.
3. Validate mutually exclusive inputs.
4. For a project target, enumerate every active `orbit` instance and its
   serving node. Preauthorize `php:write` on every distinct serving node, then
   verify the approved image on each node. Refresh stale inventory before
   rejecting. Any denial, unavailable inventory, or missing image stops before
   the shared policy write.
5. For a workspace target, authorize and verify only its concrete serving node.
   For CLI scope, accept only PHP 8.5 and use the host toolchain boundary.
6. Begin side effects only after the complete target-specific preflight passes.

Instances driven by external platforms remain in result inventory with their driver and
`status=external`; they have no Orbit serving node, do not participate in
Orbit-node authorization or image preflight, and are not reconciled.

## Input Mode Contracts

- [Interactive input mode](5.1_php-use_input-mode_interactive.md)
- [Non-interactive input mode](5.2_php-use_input-mode_non-interactive.md)

## Behavior Contract

### App Runtime Selection

- Writes one shared project PHP version after every affected Orbit instance
  has passed authorization and image preflight.
- Fans out app runtime-container and affected proxy-backend reconciliation to
  every Orbit instance and waits for every result.
- Returns one result with at least `instance`, `node`, `driver`, `status`,
  and `image` for each instance. External-driver rows use `node=null`,
  `image=null`, and `status=external` and are never presented as reconciled.
- Returns success only when every affected Orbit instance is `reconciled` or
  `unchanged`. A failed instance makes the command fail with the complete
  result list; completed node effects and the desired logical policy remain
  available for owning-family Doctor repair.

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

For app and workspace runtime selection, a successful inventory probe that does
not find the approved image fails validation as a confirmed missing image. An
unreachable provider or otherwise failed inventory probe reports image
inventory unavailability and does not claim that the image is missing.

A project reconciliation failure uses
`error.code=php.reconciliation_failed` with every per-instance result in
`error.meta.instances`. It never returns a success envelope with partial
warnings.

## Doctor Relationship

`php:use` writes runtime configuration owned by other state families. App runtime drift
is verified and repaired by [`doctor --family=instance`](../../../5_project/instance-doctor.md).
Workspace runtime drift is verified and repaired by
[`doctor --family=workspace`](../../../6_workspace/workspace-doctor.md). PHP
image availability is verified by the runtime/image catalog and node runtime
readiness checks.
App and workspace proxy backend drift caused by runtime-target changes is
verified and repaired by [`doctor --family=proxy`](../../../8_proxy/proxy-doctor.md).

## Test Mapping

| Path | Coverage |
| --- | --- |
| `apps/cli/tests/Feature/Commands/Php/PhpUseCommandTest.php` | CLI posts project/workspace selections, inherit semantics, mutual exclusion validation, per-instance rendering, and gateway error pass-through. |
| `apps/gateway/tests/Feature/Http/Api/PhpRuntimeControllerTest.php` | All-instance preauthorization, image preflight before mutation, concrete workspace placement, wrong-permission denial, and gateway implicit authority. |
| `apps/gateway/tests/Unit/Services/Php/PhpRuntimeManagerTest.php` | Shared app-policy fan-out, external-driver exclusion, per-instance results, failure aggregation, and workspace selection/inheritance behavior. |
