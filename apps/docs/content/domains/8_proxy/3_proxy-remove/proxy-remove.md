# `orbit proxy:remove <domain>`

[Back to Proxy commands.](../README.md)

## Purpose

Remove a custom proxy route.

## Usage

```bash
orbit proxy:remove <domain> [--force] [--json]
```

## Description

`proxy:remove` removes user-authored custom proxy routes and custom redirect
routes. With destructive consent it also removes a structurally complete
tool-owned route when no matching installed tool remains on its serving node.
Missing or invalid app, instance, WebSocket, workspace, gateway, S3, or other
direct ownership does not prove removable ownership; those routes stay stored
and fail closed.

Redirects are removed through the same command as custom upstream routes.

## Examples

```bash
orbit proxy:remove vite.docs.test
orbit proxy:remove redirect.docs.test --force
orbit proxy:remove redirect.docs.test --force --json
orbit proxy:remove hermes.agent --force
```

## Output

Pass `--json` to receive machine-readable output. Human output renders a
destructive confirmation in interactive mode, then progress and a summary
naming the removed domain and serving node. When the removed route was an
missing-tool-owner repair, the summary states that the recorded tool was
missing and why the force removal was safe.

## Requirements

- The caller can reach the Orbit gateway.
- The caller is authorized to manage custom proxy routes on the route's serving node.
- The selected domain resolves to a custom upstream or custom redirect route,
  or to a structurally complete tool-owned route whose matching installed tool
  is absent on the serving node.
- Destructive consent is provided before side effects.

## Related

- [`orbit proxy:list`](../1_proxy-list/proxy-list.md)
- [`orbit proxy:add`](../2_proxy-add/proxy-add.md)
- [`doctor --family=proxy`](../proxy-doctor.md)
- [Technical contract](technical/1_proxy-remove.md)
