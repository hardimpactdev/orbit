# Technical Contract: `orbit php:list [--app=<app>] [--workspace=<workspace>] [--node=<node>] [--live] [--json]`

[Back to public `php:list` documentation.](../php-list.md)

**Owner:** `php`.

**Effects:** `read`.

**Prerequisites:**
- The CLI caller can reach the Orbit gateway, or the command is running on the gateway.
- The current node identity has `php:read` granted on the resolved target,
  app-instance serving node, or workspace-instance serving node. Gateway
  identity remains implicit.

## Signature

```bash
orbit php:list [--app=<app>] [--workspace=<workspace>] [--node=<node>] [--live] [--json]
```

## Input Contract

This command follows the shared [Invocation Model](../../../README.md#invocation-model).

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `node` | `--node` | Optional. | Never. | Local `node:default` when no app or workspace context resolves a node. | Visible node slug. |
| `app` | `--app` | Optional. | Never. | Cwd-inferred app when available. | Visible app selector. |
| `workspace` | `--workspace` | Optional. | Never. | Cwd-inferred workspace when available. | Visible workspace selector. Requires resolved parent app when the workspace name is ambiguous. |
| `live` | `--live` | Optional. | Never. | `false`. | Requests live image inspection on the resolved node. |
| `json` | `--json` | Optional. | Never. | `false`. | Selects the JSON renderer. |

## Behavior Contract

### Runtime Visibility Rules

- Resolves target context from explicit options, cwd app/workspace context,
  concrete app-instance placement, workspace-instance placement, local
  `node:default`, or gateway-local node identity.
- When an app or workspace context resolves an owning node, any explicit
  `--node` selector must match that owner; `--node` is not an alternate image
  inventory source for app or workspace runtime facts.
- Reads gateway configuration for app PHP version and workspace override or
  inheritance when those scopes are resolved.
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

## Doctor Relationship

`php:list` does not change runtime selection or runtime artifacts; live reads
may reconcile their PHP inventory fact. [`doctor --family=tool`](../../../3_tool/tool-doctor.md)
owns PHP image capability drift. [`doctor --family=app`](../../../5_app/app-doctor.md)
and [`doctor --family=workspace`](../../../6_workspace/workspace-doctor.md)
own PHP runtime health for app and workspace artifacts.

## Test Mapping

| Path | Coverage |
| --- | --- |
| `apps/cli/tests/Feature/Commands/Php/PhpListCommandTest.php` | CLI target resolution, filter forwarding, `--live` flag forwarding, human and JSON renderer selection, and gateway error pass-through. |
| `apps/gateway/tests/Feature/Http/Api/PhpRuntimeControllerTest.php` | Permission-specific gateway authorization, app-instance/workspace placement, unresolved-target denial, runtime view reads, and structured success/error envelopes. |
| `apps/gateway/tests/Unit/Services/Php/PhpRuntimeManagerTest.php` | Inherited workspace view mapping and PHP runtime view DTO shape. |
