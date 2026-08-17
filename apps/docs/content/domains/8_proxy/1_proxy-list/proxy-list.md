# `orbit proxy:list`

[Back to Proxy commands.](../README.md)

## Purpose

List proxy route configuration across the Orbit fleet.

## Usage

```bash
orbit proxy:list [--node=<node>] [--filter=<filter>] [--json]
```

## Description

`proxy:list` shows the unified HTTP ingress registry. By default it includes
instance routes, WebSocket routes, workspace routes, gateway/internal routes,
websocket service routes, S3 service and public host routes, tool-owned routes,
custom upstream routes, and redirects.

Use `--filter` to narrow the list:

| Filter | Meaning |
| --- | --- |
| `all` | All visible proxy routes. |
| `instance` | Instance-owned hostnames. |
| `workspace` | Workspace-owned hostnames. |
| `gateway` | Gateway-owned internal routes such as the gateway API ingress. |
| `websocket` | Instance-owned public WebSocket hosts and router-owned private service routes such as `websocket.orbit`. |
| `s3` | S3 public host routes and router-owned private S3 service routes such as `s3.orbit`. |
| `analytics` | Public instance analytics host routes and router-owned private analytics service routes such as `analytics.orbit`. |
| `tool` | Tool-owned proxy routes for node tools or services. |
| `custom` | User-authored upstream routes created by `proxy:add --upstream`. |
| `redirect` | User-authored redirect routes created by `proxy:add --redirect`. |

`proxy:list` reads gateway configuration only. It does not probe node proxy backends or verify TLS files. Use `doctor --family=proxy` for drift verification.

Every instance-owned primary route reports a concrete instance target. A route
such as `happie.nmbp` reports `owner.type=instance`,
`owner.name=happie.nmbp`, `target.type=instance`, and
`target.value=happie.nmbp`. Apps are not route targets or proxy-list filters.

## Examples

```bash
orbit proxy:list
orbit proxy:list --filter=redirect
orbit proxy:list --filter=custom --node=app-1
orbit proxy:list --json
```

## Output

Run with `--json` to get machine-readable output. Human output renders a table
of route domain, kind, owner, node, target, TLS summary, and status.

## Requirements

- The caller can reach the Orbit gateway.
- The caller is authorized to inspect the selected serving node or route owner.

## Related

- [`orbit proxy:add`](../2_proxy-add/proxy-add.md)
- [`orbit proxy:remove`](../3_proxy-remove/proxy-remove.md)
- [`doctor --family=proxy`](../proxy-doctor.md)
- [Technical contract](technical/1_proxy-list.md)
