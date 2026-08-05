# Technical Contract: `orbit instance:log [instance]`

[Back to public `instance:log` documentation.](../instance-log.md)

**Owner:** `app` / `instance`.

**Effects:** `read, stream`.

**Prerequisites:**
- The CLI caller can reach the Orbit gateway.
- The gateway authorizes the authenticated peer for `instance:read` on the
  resolved instance serving node.

## Signature

```bash
orbit instance:log [instance|instance-url] [--lines=<n>] [--follow] [--json] [--node=<node>]
```

## Input Contract

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `instance` | `[instance]` or interactive cwd | Non-interactive always; interactive when cwd is ambiguous or absent | Never | Unambiguous interactive cwd instance | Dotted `app.instance` or strict instance URL/hostname. No numeric IDs. |
| `lines` | `--lines` | Optional | Never | `100` | Positive integer. |
| `follow` | `--follow` | Optional | With `--json` | off | Human streaming only. |
| `json` | `--json` | Optional | With `--follow` | off | Bounded-read envelope only. |
| `node` | `--node` | Optional | Never | Serving node | Must equal the instance serving node. |

## Behavior Contract

1. Resolve the instance and serving node.
2. Authorize `instance:read`.
3. Apply `--node` as a placement constraint only.
4. Resolve application root consistent with
   `AppRuntimeContainerRenderer::applicationRootInContainer()` on the host path.
5. Read or follow only `storage/logs/laravel.log` under that root.
6. Gateway: `GET /api/instances/{instance}/log` and
   `POST /api/instances/{instance}/log-stream`.

## Doctor Relationship

`instance:log` does not diagnose or repair instance placement. Use
[`instance-doctor.md`](../../instance-doctor.md) for live instance drift and
repair. This command only reads the fixed application log after resolution and
authorization succeed.

## Activity Logging

| Field | Value |
| --- | --- |
| Type | `api:GET /instances/{instance}/log` or `api:POST /instances/{instance}/log-stream` |
| Effect | `read` |
| Properties | target identity, selector, node constraint, mode, lines, outcome—never log contents or absolute host paths |

## Test Mapping

| Path | Coverage |
| --- | --- |
| `apps/cli/tests/Feature/Commands/App/InstanceLogCommandTest.php` | CLI selectors, flags, JSON envelope |
| `apps/gateway/tests/Feature/Http/Api/InstanceApplicationLogControllerTest.php` | Gateway routes, auth, node constraint, missing file |
