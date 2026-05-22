# Technical Contract: `orbit php:list [--app=<app>] [--workspace=<workspace>] [--node=<node>] [--live] [--json]`

[Back to public `php:list` documentation.](../php-list.md)

**Owner:** `php`.

**Effects:** `read`.

**Prerequisites:**
- The CLI caller can reach the Orbit gateway, or the command is running on the gateway.
- The current node identity is authorized to inspect the resolved app, workspace, or node.

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
  app ownership, workspace ownership, local `node:default`, or gateway-local
  node identity.
- Reads gateway configuration for app PHP version and workspace override or
  inheritance when those scopes are resolved.
- Reads the Orbit-supported PHP version set from the PHP runtime catalog.
- Reads gateway-tracked image facts by default.
- Performs live image inspection only when `--live` is supplied and
  only for the resolved node.

### Scope Boundaries

`php:list` must not install host PHP runtimes, build images, change PHP version
configuration, re-apply artifacts for runtime containers, edit project files,
read `.php-version`, mutate Composer constraints, or SSH to a node unless
`--live` is supplied.

## Renderer Contracts

- [Human renderer](6.1_php-list_output-render_human.md)
- [JSON renderer](6.2_php-list_output-render_json.md)

## Failure Semantics
Standard failures defined in [Common Failures](../../../README.md#common-failures) apply; command-specific failures below.

## Doctor Relationship

`php:list` is read-only. [`doctor --family=tool`](../../../3_tool/tool-doctor.md)
owns PHP image capability drift. [`doctor --family=app`](../../../5_app/app-doctor.md)
and [`doctor --family=workspace`](../../../6_workspace/workspace-doctor.md)
own PHP runtime health for app and workspace artifacts.

## Test Mapping

| Path | Coverage |
| --- | --- |
| `tests/Feature/Commands/Php/PhpListCommandTest.php` | Target resolution, caller authorization, gateway read behavior, `--live` image inspection boundary, and failure codes. |
| `tests/Unit/Services/Php/PhpRuntimeViewTest.php` | PHP runtime view DTO shape, supported and available-image version mapping, and app/workspace inheritance reporting. |
