# Technical Contract: `orbit app:log [target]`

[Back to public `app:log` documentation.](../app-log.md)

**Owner:** `app`.

**Effects:** `read, stream`.

**Prerequisites:**
- The CLI caller can reach the Orbit gateway.
- A registered proxy route exists for the host.
- The caller holds `instance:read` or `workspace:read` for the resolved target.

## Signature

```bash
orbit app:log [target] [--node=<node>] [--lines=<n>] [--follow] [--json]
```

## Input Contract

This command follows the shared [Invocation Model](../../../README.md#invocation-model).

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `target` | `[target]` or interactive cwd | Non-interactive always; interactive when cwd is ambiguous or absent. | Dotted `app.instance` or bare workspace name as a selector. | Unambiguous interactive cwd Instance or Workspace (prefer Workspace when cwd is inside a workspace tree). | Strict http(s) URL or bare hostname only—no credentials, query, fragment, non-root path, or non-default port. |
| `node` | `--node` | Optional. | Never. | Serving node. | Must equal the resolved target serving node (placement constraint only). |
| `lines` | `--lines` | Optional. | Never. | `100`. | Positive integer. How many prior log lines to read before streaming or returning. |
| `follow` | `--follow` | Optional. | When `json=true`. | `false`. | Boolean flag. Keeps the human log stream open when true. |
| `json` | `--json` | Optional. | When `follow=true`. | `false`. | Selects the JSON renderer and non-interactive input mode. JSON output is only defined for bounded, non-follow log reads. |

Shared application-log flags match `instance:log` / `workspace:log`.

## Behavior Contract

1. Parse and validate the URL/hostname shape of `[target]`.
2. Resolve the registered proxy route to exactly one Instance or Workspace.
3. Authorize with the permission for the resolved target type
   (`instance:read` or `workspace:read`).
4. Apply `--node` as a placement constraint only.
5. Delegate to the same fixed-path application-log read/stream path as
   `instance:log` / `workspace:log` (`storage/logs/laravel.log`).
6. Render the selected output.

`app:log` does not accept dotted `app.instance` or bare workspace name
selectors—use `instance:log` or `workspace:log` for those. It does not mutate
configuration or placement.

## Renderer Contracts

- [Human renderer](6.1_app-log_output-render_human.md)
- [JSON renderer](6.2_app-log_output-render_json.md)

## Failure Semantics
Standard failures defined in [Common Failures](../../../README.md#common-failures) apply; command-specific failures below.

| Failure | Condition | Outcome |
| --- | --- | --- |
| Target required | Non-interactive or `--json` invocation omits `[target]` and cwd cannot supply one. | Failure (`error.code=validation_failed`; `error.meta.field=target`). |
| Invalid target shape | Target is not a strict URL/hostname, or is a dotted `app.instance` / bare workspace name. | Failure (`error.code=validation_failed`; `error.meta.field=target`). |
| Unregistered host | No registered proxy route matches the host. | Failure (`error.code=validation_failed`; `error.meta.field=target`). |
| Invalid lines | `--lines` is not a strict positive integer. | Failure (`error.code=validation_failed`; `error.meta.field=lines`). |
| JSON with follow | `--json` is combined with `--follow`. | Failure (`error.code=validation_failed`; `error.meta.field=json`). |
| Node mismatch | `--node` does not equal the resolved target serving node. | Failure (`error.code=validation_failed`). |
| Log read failed | The gateway cannot read the fixed application log from the serving node. | Failure (`error.code=application_log.read_failed`). |

A missing application log file is not a failure for bounded reads: the command
exits zero with empty `lines` and `file_exists=false`.

## Doctor Relationship

`app:log` does not diagnose proxy or placement drift. Use
[`instance-doctor.md`](../../instance-doctor.md) for live instance verification
and repair, and the workspace doctor family for workspace placement. This
command only resolves a proxy host and reads the fixed application log for the
resolved target.

## Activity Logging

The gateway API endpoint emits an activity entry for successful and failed
application log reads on the resolved Instance or Workspace surface.

| Field | Value |
| --- | --- |
| Type | `api:GET /instances/{instance}/log` or `api:GET /workspaces/{workspace}/log` for bounded reads; matching `POST .../log-stream` for follow |
| Effect | `read` |
| Subject | Resolved `Instance` or `Workspace` when identity is known; `none` for validation or authorization failures before the owner can be logged. |
| Properties | Target identity, selector, node constraint, mode, lines, outcome—never log contents or absolute host paths. |
| Description | derived |

## Test Mapping

Primary test owners:

| Path | Coverage |
| --- | --- |
| `apps/cli/tests/Feature/Commands/App/AppLogCommandTest.php` | URL shape validation, selector rejection, JSON envelope, and non-interactive target requirement. |
| `apps/gateway/tests/Feature/Http/Api/InstanceApplicationLogControllerTest.php` | Instance-side application log routes used after host resolution. |
| `apps/gateway/tests/Feature/Http/Api/WorkspaceApplicationLogControllerTest.php` | Workspace-side application log routes used after host resolution. |

Renderer-specific test mapping lives in the split companion files.
