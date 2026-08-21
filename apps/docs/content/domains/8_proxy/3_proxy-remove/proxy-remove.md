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
routes. With destructive consent it also removes two proven orphan forms: a
direct-instance route (app, analytics, or WebSocket) whose owning instance is
gone, and a structurally complete tool-owned route when no matching installed
tool remains on its serving node. It refuses routes whose owner still exists.
A conflicting tuple whose instance still lives stays denied as repairable
divergence. Missing or invalid workspace, gateway, S3, or other ownership does
not prove removable ownership; those routes stay stored and fail closed.

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
orphan-owner repair, the summary states which recorded owner was missing and
why the force removal was safe.

## Requirements

- The caller can reach the Orbit gateway.
- The caller is authorized to manage custom proxy routes on the route's serving node.
- The selected domain resolves to a custom upstream or custom redirect route,
  a direct-instance route whose instance is gone, or a structurally complete
  tool-owned route whose matching installed tool is absent on the serving node.
- Destructive consent is provided before side effects.

## Related

- [`orbit proxy:list`](../1_proxy-list/proxy-list.md)
- [`orbit proxy:add`](../2_proxy-add/proxy-add.md)
- [`doctor --family=proxy`](../proxy-doctor.md)
- [Technical contract](technical/1_proxy-remove.md)
