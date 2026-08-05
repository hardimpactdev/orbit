# Technical Contract: `orbit workspace:log [workspace]`

[Back to public `workspace:log` documentation.](../workspace-log.md)

**Owner:** `workspace`.

**Effects:** `read, stream`.

**Prerequisites:**
- The CLI caller can reach the Orbit gateway.
- The gateway authorizes the authenticated peer for `workspace:read` on the
  resolved workspace instance serving node.

## Signature

```bash
orbit workspace:log [workspace|workspace-url] [--instance=<app.instance>] [--lines=<n>] [--follow] [--json] [--node=<node>]
```

## Input Contract

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `workspace` | `[workspace]` or interactive cwd | Non-interactive always; interactive when cwd is ambiguous or absent | Never | Unambiguous interactive cwd workspace | Public workspace slug or strict URL/hostname for a workspace proxy route. No numeric IDs. |
| `instance` | `--instance` | When the workspace name is ambiguous | Never | Parent instance after unique resolution | Dotted `app.instance`. API always requires this field. |
| `lines` | `--lines` | Optional | Never | `100` | Positive integer. |
| `follow` | `--follow` | Optional | With `--json` | off | Human streaming only. |
| `json` | `--json` | Optional | With `--follow` | off | Bounded-read envelope only. |
| `node` | `--node` | Optional | Never | Serving node | Must equal the workspace serving node. |

## Behavior Contract

### Application Log Rules

1. Resolve the workspace and parent `app.instance`.
2. Authorize `workspace:read` on the serving node.
3. Apply `--node` as a placement constraint only.
4. Read or follow only `storage/logs/laravel.log` under the authorized workspace root.
5. Public JSON `path` is always `storage/logs/laravel.log`.
6. Missing file: success, empty `lines`, `file_exists=false`.
7. Gateway: `GET /api/workspaces/{workspace}/log?instance=...` and
   `POST /api/workspaces/{workspace}/log-stream`.

## Doctor Relationship

`workspace:log` does not diagnose or repair workspace placement. Use
[`workspace-doctor.md`](../../workspace-doctor.md) for live workspace drift and
repair. This command only reads the fixed application log after resolution and
authorization succeed.

## Activity Logging

| Field | Value |
| --- | --- |
| Type | `api:GET /workspaces/{workspace}/log` or `api:POST /workspaces/{workspace}/log-stream` |
| Effect | `read` |
| Properties | target identity, selector, node constraint, mode, lines, outcome—never log contents or absolute host paths |

## Test Mapping

| Path | Coverage |
| --- | --- |
| `apps/cli/tests/Feature/Commands/Workspace/WorkspaceApplicationLogCommandTest.php` | CLI selector, flags, JSON envelope, old lifecycle command absence |
| `apps/gateway/tests/Feature/Http/Api/WorkspaceApplicationLogControllerTest.php` | Gateway routes, auth, required instance, node constraint |
