# Technical Contract: `orbit app:log [url]`

[Back to public `app:log` documentation.](../app-log.md)

**Owner:** `app`.

**Effects:** `read, stream`.

**Prerequisites:**
- The CLI caller can reach the Orbit gateway.
- A registered proxy route exists for the host.
- The caller holds `instance:read` or `workspace:read` for the resolved target.

## Signature

```bash
orbit app:log [instance-url|workspace-url] [--lines=<n>] [--follow] [--json] [--node=<node>]
```

## Input Contract

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `url` | `[url]` or interactive cwd | Non-interactive always; interactive when cwd is ambiguous or absent | Dotted `app.instance` or bare workspace name | Unambiguous interactive cwd Instance or Workspace (prefer Workspace when cwd is inside a workspace tree) | Strict http(s) URL or bare hostname only—no credentials, query, fragment, non-root path, or non-default port. |

Shared application-log flags match `instance:log` / `workspace:log`.

## Behavior Contract

1. Parse and validate the URL/hostname shape.
2. Resolve the registered proxy route to one Instance or Workspace.
3. Authorize with the permission for the resolved target type.
4. Delegate to the same fixed-path application-log read/stream path as
   `instance:log` / `workspace:log`.

## Doctor Relationship

`app:log` does not diagnose proxy or placement drift. Use the instance and
workspace doctor families for live repair. This command only resolves a proxy
host and reads the fixed application log for the resolved target.

## Test Mapping

| Path | Coverage |
| --- | --- |
| `apps/cli/tests/Feature/Commands/App/AppLogCommandTest.php` | URL shape validation and selector rejection |
| `apps/gateway/tests/Feature/Http/Api/InstanceApplicationLogControllerTest.php` | Instance-side application log routes used after host resolution |
| `apps/gateway/tests/Feature/Http/Api/WorkspaceApplicationLogControllerTest.php` | Workspace-side application log routes used after host resolution |
