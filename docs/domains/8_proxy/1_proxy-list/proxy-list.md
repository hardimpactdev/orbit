# `orbit proxy:list`

[Back to Proxy commands.](../README.md)

## Purpose

List proxy route configuration across the Orbit fleet.

## Usage

```bash
orbit proxy:list [--node=<node>] [--filter=<filter>] [--json]
```

## Description

`proxy:list` shows the unified HTTP ingress registry. By default it includes app routes, workspace routes, gateway/internal routes, tool-owned routes, custom upstream routes, and redirects.

Use `--filter` to narrow the list:

| Filter | Meaning |
| --- | --- |
| `all` | All visible proxy routes. |
| `app` | App-owned hostnames. |
| `workspace` | Workspace-owned hostnames. |
| `gateway` | Gateway-owned internal routes such as the gateway API ingress. |
| `tool` | Tool-owned proxy routes for node tools or services. |
| `custom` | User-authored upstream routes created by `proxy:add --upstream`. |
| `redirect` | User-authored redirect routes created by `proxy:add --redirect`. |

`proxy:list` reads gateway configuration only. It does not probe node proxy backends or verify TLS files. Use `doctor --family=proxy` for drift verification.

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
