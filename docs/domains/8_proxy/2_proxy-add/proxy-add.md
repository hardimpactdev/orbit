# `orbit proxy:add <domain>`

[Back to Proxy commands.](../README.md)

## Purpose

Create or update a custom proxy route.

## Usage

```bash
orbit proxy:add <domain> --upstream=<url> [--node=<node>] [--force] [--json]
orbit proxy:add <domain> --redirect=<url> [--node=<node>] [--code=<code>] [--force] [--json]
```

## Description

`proxy:add` creates user-authored proxy route configuration. It supports two custom route shapes:

- an upstream route from `<domain>` to a local service URL through `--upstream=<url>`;
- a redirect route from `<domain>` to another URL through `--redirect=<url>`.

Redirects are part of the proxy command family and are created with `proxy:add --redirect=<url>`.

`proxy:add` does not edit routes owned by apps, app WebSocket bindings,
workspaces, gateways, websocket services, or tools. Those routes are listed by
`proxy:list`, but their lifecycle belongs to their owning domain command.

## Examples

```bash
orbit proxy:add vite.docs.test --upstream=http://127.0.0.1:5173 --node=app-1
orbit proxy:add old.test --redirect=https://docs.test --code=302 --node=app-1
orbit proxy:add redis.app-1.test --upstream=http://127.0.0.1:6379 --node=app-1
orbit proxy:add old.test --redirect=https://docs.test --force --json
```

## Output

Pass `--json` to receive machine-readable output. Human output renders progress
and a summary naming the domain, route kind, serving node, and target.

## Requirements

- The caller can reach the Orbit gateway.
- The caller is authorized to manage custom proxy routes on the selected serving node.
- The selected domain is not owned by an app, app WebSocket binding, workspace,
  gateway, websocket service, or tool route.
- Exactly one of `--upstream` or `--redirect` is supplied before side effects.

## Related

- [`orbit proxy:list`](../1_proxy-list/proxy-list.md)
- [`orbit proxy:remove`](../3_proxy-remove/proxy-remove.md)
- [`doctor --family=proxy`](../proxy-doctor.md)
- [Technical contract](technical/1_proxy-add.md)
