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
| `live` | `--live` | Optional. | Never. | `false`. | Requests live installed-version inspection on the resolved node. |
| `json` | `--json` | Optional. | Never. | `false`. | Selects the JSON renderer. |

## Authorization By Caller Role

The CLI is a thin gateway client. The gateway authenticates the caller's WireGuard peer identity and applies authorization. Callers may read only PHP runtime selections visible to their authenticated identity. Local cwd app or workspace context is used as input hint only and never grants read authority.

## Input Mode Contracts

No input-mode-specific contracts are required. The command does not prompt;
missing required target context fails according to the shared invocation model.

## Behavior Contract

### Runtime Visibility Rules

- Resolves target context from explicit options, cwd app/workspace context,
  app ownership, workspace ownership, local `node:default`, or gateway-local
  node identity.
- Reads gateway configuration for app PHP version, workspace override or inheritance,
  and node CLI PHP default when those scopes are resolved.
- Reads the Orbit-supported PHP version set from the PHP runtime catalog.
- Reads gateway-tracked installed-version facts by default.
- Performs live installed-version inspection only when `--live` is supplied and
  only for the resolved node.

### Scope Boundaries

`php:list` must not install PHP runtimes, change PHP version configuration, re-apply
PHP-FPM artifacts, change node CLI defaults, edit project files, read
`.php-version`, mutate Composer constraints, or SSH to a node unless `--live`
is supplied.

## Renderer Contracts

- [Human renderer](6.1_php-list_output-render_human.md)
- [JSON renderer](6.2_php-list_output-render_json.md)

## Failure Semantics

| Failure | Condition | Outcome |
| --- | --- | --- |
| Validation failed | Supplied input is missing, invalid, or ambiguous. | `error.code=validation_failed` |
| Gateway unavailable | The CLI cannot reach the gateway API. | `error.code=gateway_unavailable` |
| Authorization failed | The caller is not authorized to inspect the resolved target. | `error.code=authorization_failed` |

## Doctor Relationship

`php:list` is read-only. [`doctor --family=tool`](../../../3_tool/tool-doctor.md)
owns installed PHP runtime drift. [`doctor --family=app`](../../../5_app/app-doctor.md)
and [`doctor --family=workspace`](../../../6_workspace/workspace-doctor.md)
own PHP-FPM runtime health for app and workspace artifacts. [`doctor --family=node`](../../../1_node/node-doctor.md)
owns node CLI PHP default drift.

## Test Mapping

| Path | Coverage |
| --- | --- |
| `tests/Feature/Commands/Php/PhpListCommandTest.php` | Target resolution, caller authorization, gateway read behavior, `--live` installed-version inspection boundary, and failure codes. |
| `tests/Unit/Services/Php/PhpRuntimeViewTest.php` | PHP runtime view DTO shape, supported and installed version mapping, app/workspace inheritance reporting, and node CLI default reporting. |
