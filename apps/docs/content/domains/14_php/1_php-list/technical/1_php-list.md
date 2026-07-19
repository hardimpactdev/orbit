# Technical Contract: `orbit php:list [--app=<app.instance>] [--workspace=<workspace>] [--node=<node>] [--live] [--json]`

[Back to public `php:list` documentation.](../php-list.md)

**Owner:** `php`.

**Effects:** `read`.

**Prerequisites:**
- The CLI caller can reach the Orbit gateway, or the command is running on the gateway.
- The current node identity has `php:read` granted on the selected app-instance
  or workspace-instance serving node, or on the explicit node target. Gateway
  identity remains implicit.

## Signature

```bash
orbit php:list [--app=<app.instance>] [--workspace=<workspace>] [--node=<node>] [--live] [--json]
```

## Input Contract

This command follows the shared [Invocation Model](../../../README.md#invocation-model).

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `node` | `--node` | Optional. | Never. | Local `node:default` when no app or workspace context resolves a node. | Visible node slug. |
| `app` | `--app` | Optional. | Never. | Cwd-inferred concrete app instance when available. | Visible dotted `<app.instance>` selector. A bare logical app fails with `error.meta.reason=app_instance_required`. |
| `workspace` | `--workspace` | Optional. | Never. | Cwd-inferred workspace when available. | Visible workspace selector. Requires resolved parent app when the workspace name is ambiguous. |
| `live` | `--live` | Optional. | Never. | `false`. | Requests live image inspection on the resolved node. |
| `json` | `--json` | Optional. | Never. | `false`. | Selects the JSON renderer. |

## Behavior Contract

### Runtime Visibility Rules

- Resolves exactly one target context from an explicit dotted app-instance
  selector, concrete cwd app/workspace context, workspace-instance placement,
  local `node:default`, or gateway-local node identity. It never chooses one
  app instance to stand in for a logical app.
- When an app instance or workspace context resolves a serving node, any
  explicit `--node` selector must match that node; `--node` is not an alternate
  image inventory source for app-instance or workspace runtime facts.
- Reads the logical app's shared PHP policy and reports it alongside the
  selected app instance and serving-node inventory. Reads workspace override or
  inheritance when that scope is resolved.
- Rejects an explicit workspace scope before configuration or live inventory
  reads unless its serving node is active `app-dev` and the caller is not
  `app-prod`. When the caller or selected app is production, the app view omits
  workspace inheritance and override facts.
- Reads the Orbit-supported PHP version set from the PHP runtime catalog.
- Reads gateway-tracked image facts by default.
- Performs live image inspection through Agent push only when `--live` is
  supplied and only for the resolved node. On a supported node without a PHP
  inventory fact, the refresh registers that fact before probing. A successful
  probe records the approved image set with confirmed inventory status;
  provider or probe failure records unavailable status and returns an explicit
  error.

### Scope Boundaries

`php:list` must not install host PHP runtimes, build images, change PHP version
configuration, re-apply artifacts for runtime containers, edit project files,
read `.php-version`, mutate Composer constraints, or use SSH.

## Renderer Contracts

- [Human renderer](6.1_php-list_output-render_human.md)
- [JSON renderer](6.2_php-list_output-render_json.md)

## Failure Semantics
Standard failures defined in [Common Failures](../../../README.md#common-failures) apply; command-specific failures below.

A supplied bare logical app fails before reads with
`error.code=validation_failed`, `error.meta.field=app`, and
`error.meta.reason=app_instance_required`.

## Doctor Relationship

`php:list` does not change runtime selection or runtime artifacts; live reads
may reconcile their PHP inventory fact. [`doctor --family=tool`](../../../3_tool/tool-doctor.md)
owns PHP image capability drift. [`doctor --family=app`](../../../5_app/app-doctor.md)
and [`doctor --family=workspace`](../../../6_workspace/workspace-doctor.md)
own PHP runtime health for app and workspace artifacts.

## Test Mapping

| Path | Coverage |
| --- | --- |
| `apps/cli/tests/Feature/Commands/Php/PhpListCommandTest.php` | Concrete app-instance target resolution, filter forwarding, `--live` flag forwarding, human and JSON renderer selection, and gateway error pass-through. |
| `apps/gateway/tests/Feature/Http/Api/PhpRuntimeControllerTest.php` | Permission-specific gateway authorization, concrete app-instance/workspace placement, bare-app denial, runtime view reads, and structured success/error envelopes. |
| `apps/gateway/tests/Unit/Services/Php/PhpRuntimeManagerTest.php` | Inherited workspace view mapping and PHP runtime view DTO shape. |
