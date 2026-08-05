# Technical Contract: `orbit workspace:log [target]`

[Back to public `workspace:log` documentation.](../workspace-log.md)

**Owner:** `workspace`.

**Effects:** `read, stream`.

**Prerequisites:**
- The CLI caller can reach the Orbit gateway.
- The gateway authorizes the authenticated peer for `workspace:read` on the
  resolved workspace instance serving node.

## Signature

```bash
orbit workspace:log [target] [--instance=<app.instance>] [--node=<node>] [--lines=<n>] [--follow] [--json]
```

## Input Contract

This command follows the shared [Invocation Model](../../../README.md#invocation-model).

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `target` | `[target]` or interactive cwd | Non-interactive always; interactive when cwd is ambiguous or absent. | Never. | Unambiguous interactive cwd workspace. | Public workspace slug or strict URL/hostname for a workspace proxy route. No numeric IDs. |
| `instance` | `--instance` | When the visible exact workspace slug is ambiguous across parents. | Never. | Derived from `GET /api/workspaces` when the slug matches exactly one visible row; explicit `--instance` remains direct. | Dotted `app.instance`. API always requires this field. |
| `node` | `--node` | Optional. | Never. | Serving node. | Must equal the workspace serving node (placement constraint only). |
| `lines` | `--lines` | Optional. | Never. | `100`. | Positive integer. How many prior log lines to read before streaming or returning. |
| `follow` | `--follow` | Optional. | When `json=true`. | `false`. | Boolean flag. Keeps the human log stream open when true. |
| `json` | `--json` | Optional. | When `follow=true`. | `false`. | Selects the JSON renderer and non-interactive input mode. JSON output is only defined for bounded, non-follow log reads. |

## Behavior Contract

### Application Log Rules

1. Resolve the workspace and parent `app.instance` from `[target]` (name or
   workspace URL/hostname) plus optional `--instance`.
2. Authorize `workspace:read` on the serving node.
3. Apply `--node` as a placement constraint only; reject mismatches.
4. Read or follow only `storage/logs/laravel.log` under the authorized workspace
   root. The public JSON `path` is always `storage/logs/laravel.log`.
5. For bounded reads, a missing file is success with empty `lines` and
   `file_exists=false`.
6. Gateway surfaces: `GET /api/workspaces/{workspace}/log?instance=...`
   (bounded) and `POST /api/workspaces/{workspace}/log-stream` (follow).
7. Render the selected output.

`workspace:log` does not mutate workspace configuration, placement, or durable
lifecycle events. Lifecycle run history remains
[`orbit workspace:run:log`](../../7_workspace-run-log/workspace-run-log.md).

## Renderer Contracts

- [Human renderer](6.1_workspace-log_output-render_human.md)
- [JSON renderer](6.2_workspace-log_output-render_json.md)

## Failure Semantics
Standard failures defined in [Common Failures](../../../README.md#common-failures) apply; command-specific failures below.

| Failure | Condition | Outcome |
| --- | --- | --- |
| Target required | Non-interactive or `--json` invocation omits `[target]` and cwd cannot supply one. | Failure (`error.code=validation_failed`; `error.meta.field=target`). |
| Invalid target | Selector is not a workspace slug or strict workspace URL/hostname, or a host resolves to an instance instead of a workspace. | Failure (`error.code=validation_failed`; `error.meta.field=target`). |
| Instance required | Visible exact workspace slug is ambiguous (multiple parents) and `--instance` is omitted. | Failure (`error.code=validation_failed`; `error.meta.field=instance`; `error.meta.reason=workspace_slug_ambiguous`). |
| Invalid lines | `--lines` is not a strict positive integer. | Failure (`error.code=validation_failed`; `error.meta.field=lines`). |
| JSON with follow | `--json` is combined with `--follow`. | Failure (`error.code=validation_failed`; `error.meta.field=json`). |
| Node mismatch | `--node` does not equal the workspace serving node. | Failure (`error.code=validation_failed`). |
| Workspace not found | The resolved workspace does not exist or is not visible. | Failure (`error.code=workspace.not_found` or equivalent not-found code). |
| Log read failed | The gateway cannot read the fixed application log from the serving node. | Failure (`error.code=application_log.read_failed`). |

A missing application log file is not a failure for bounded reads: the command
exits zero with empty `lines` and `file_exists=false`.

## Doctor Relationship

`workspace:log` does not diagnose or repair workspace placement. Use
[`workspace-doctor.md`](../../workspace-doctor.md) for live workspace drift and
repair. This command only reads the fixed application log after resolution and
authorization succeed.

## Activity Logging

The gateway API endpoint emits an activity entry for successful and failed
workspace application log reads.

| Field | Value |
| --- | --- |
| Type | `api:GET /workspaces/{workspace}/log` for bounded reads; `api:POST /workspaces/{workspace}/log-stream` for follow operation creation |
| Effect | `read` |
| Subject | Resolved `Workspace` when identity is known; `none` for validation or authorization failures before the owner can be logged. |
| Properties | Target identity, route workspace/`selector`, optional CLI `requested_target` from header `X-Orbit-Application-Log-Requested-Target` (safe host/selector only; falls back to route workspace slug), node constraint, mode, lines, outcome—never log contents or absolute host paths. |
| Description | derived |

## Test Mapping

Primary test owners:

| Path | Coverage |
| --- | --- |
| `apps/cli/tests/Feature/Commands/Workspace/WorkspaceApplicationLogCommandTest.php` | CLI selector, flags, JSON envelope, cwd inference, and old lifecycle command absence. |
| `apps/gateway/tests/Feature/Http/Api/WorkspaceApplicationLogControllerTest.php` | Gateway routes, auth, required instance, node constraint, and log read failures. |

Renderer-specific test mapping lives in the split companion files.
