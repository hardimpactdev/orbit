# Technical Contract: `orbit instance:log [target]`

[Back to public `instance:log` documentation.](../instance-log.md)

**Owner:** `app` / `instance`.

**Effects:** `read, stream`.

**Prerequisites:**
- The CLI caller can reach the Orbit gateway.
- The gateway authorizes the authenticated peer for `instance:read` on the
  resolved instance serving node.

## Signature

```bash
orbit instance:log [target] [--node=<node>] [--lines=<n>] [--follow] [--json]
```

## Input Contract

This command follows the shared [Invocation Model](../../../README.md#invocation-model).

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `target` | `[target]` or interactive cwd | Non-interactive always; interactive when cwd is ambiguous or absent. | Never. | Exactly one visible instance path that is an ancestor of the interactive cwd (from `GET /api/instances` owned paths). | Dotted `app.instance` or strict instance URL/hostname. No numeric IDs. |
| `node` | `--node` | Optional. | Never. | Serving node. | Must equal the instance serving node (placement constraint only). |
| `lines` | `--lines` | Optional. | Never. | `100`. | Positive integer. How many prior log lines to read before streaming or returning. |
| `follow` | `--follow` | Optional. | When `json=true`. | `false`. | Boolean flag. Keeps the human log stream open when true. |
| `json` | `--json` | Optional. | When `follow=true`. | `false`. | Selects the JSON renderer and non-interactive input mode. JSON output is only defined for bounded, non-follow log reads. |

## Behavior Contract

1. Resolve the instance from `[target]` or interactive cwd. Interactive cwd uses
   `GET /api/instances` path inventory: a candidate matches when cwd equals the
   owned path or is a descendant (`cwd === path` or starts with `path/`).
   Exactly one match is required; zero or multiple matches fail closed without
   `.orbit` marker fallback.
   A hostname route requires `owner.type=instance` and a non-empty
   `owner.name`. A target-only route projection is invalid. It rejects dotted
   names on router, S3, tool, gateway, custom,
   and other non-Instance owners.
2. Authorize `instance:read` on the resolved instance serving node.
3. Apply `--node` as a placement constraint only; reject mismatches.
4. Resolve the application root consistent with
   `AppRuntimeContainerRenderer::applicationRootInContainer()` on the host path.
5. Read or follow only `storage/logs/laravel.log` under that root. The public
   logical path is always `storage/logs/laravel.log`.
6. For bounded reads, a missing file is success with empty `lines` and
   `file_exists=false`.
7. Gateway surfaces: `GET /api/instances/{instance}/log` (bounded) and
   `POST /api/instances/{instance}/log-stream` (follow).
8. Render the selected output.

`instance:log` does not mutate instance configuration, placement, or durable
lifecycle events. It does not accept arbitrary `--path` values.

## Renderer Contracts

- [Human renderer](6.1_instance-log_output-render_human.md)
- [JSON renderer](6.2_instance-log_output-render_json.md)

## Failure Semantics
Standard failures defined in [Common Failures](../../../README.md#common-failures) apply; command-specific failures below.

| Failure | Condition | Outcome |
| --- | --- | --- |
| Target required | Non-interactive or `--json` invocation omits `[target]` and cwd cannot supply one. | Failure (`error.code=validation_failed`; `error.meta.field=target`). |
| Invalid target | Selector is not a dotted `app.instance` or a strict instance URL/hostname, or a host does not resolve to an instance proxy route. | Failure (`error.code=validation_failed`; `error.meta.field=target`). |
| Invalid lines | `--lines` is not a strict positive integer. | Failure (`error.code=validation_failed`; `error.meta.field=lines`). |
| JSON with follow | `--json` is combined with `--follow`. | Failure (`error.code=validation_failed`; `error.meta.field=json`). |
| Node mismatch | `--node` does not equal the instance serving node. | Failure (`error.code=validation_failed`). |
| Instance not found | The resolved instance does not exist or is not visible. | Failure (`error.code=instance.not_found` or equivalent not-found code). |
| Log read failed | The gateway cannot read the fixed application log from the serving node. | Failure (`error.code=application_log.read_failed`). |

A missing application log file is not a failure for bounded reads: the command
exits zero with empty `lines` and `file_exists=false`.

## Doctor Relationship

`instance:log` does not diagnose or repair instance placement. Use
[`instance-doctor.md`](../../instance-doctor.md) for live instance drift and
repair. This command only reads the fixed application log after resolution and
authorization succeed.

## Activity Logging

The gateway API endpoint emits an activity entry for successful and failed
instance application log reads.

| Field | Value |
| --- | --- |
| Type | `api:GET /instances/{instance}/log` for bounded reads; `api:POST /instances/{instance}/log-stream` for follow operation creation |
| Effect | `read` |
| Subject | Resolved `Instance` when identity is known; `none` for validation or authorization failures before the owner can be logged. |
| Properties | Target identity, route `selector`, optional CLI `requested_target` from header `X-Orbit-Application-Log-Requested-Target` (safe host/selector only; falls back to route selector), node constraint, mode, lines, outcome—never log contents or absolute host paths. |
| Description | derived |

## Test Mapping

Primary test owners:

| Path | Coverage |
| --- | --- |
| `apps/cli/tests/Feature/Commands/App/InstanceLogCommandTest.php` | CLI selectors, flags, JSON envelope, non-interactive target requirement, cwd inference, and `--json` plus `--follow` rejection. |
| `apps/gateway/tests/Feature/Http/Api/InstanceApplicationLogControllerTest.php` | Gateway routes, `instance:read` authorization, node constraint, missing file success, activity properties, and log read failures. |

Renderer-specific test mapping lives in the split companion files.
