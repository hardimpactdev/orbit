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
routes. With destructive consent it also removes a route whose recorded owner
is proven missing (orphan owner). Example: a route still marked workspace-owned
after the workspace row is gone. It still refuses routes whose project,
instance, WebSocket, workspace, gateway, S3, or tool owner still exists; those
remain owned by their domain commands.

Redirects are removed through the same command as custom upstream routes.

## Examples

```bash
orbit proxy:remove vite.docs.test
orbit proxy:remove redirect.docs.test --force
orbit proxy:remove redirect.docs.test --force --json
orbit proxy:remove auth.craft-starterkit-react.test --force
```

## Output

Pass `--json` to receive machine-readable output. Human output renders a
destructive confirmation in interactive mode, then progress and a summary
naming the removed domain and serving node. When the removed route was an
orphan-owner repair, the summary states that the recorded owner was missing
and why the force removal was safe.

## Requirements

- The caller can reach the Orbit gateway.
- The caller is authorized to manage custom proxy routes on the route's serving node.
- The selected domain resolves to a custom upstream or custom redirect route, or
  to an owned route whose owner reference is proven missing.
- Destructive consent is provided before side effects.

## Related

- [`orbit proxy:list`](../1_proxy-list/proxy-list.md)
- [`orbit proxy:add`](../2_proxy-add/proxy-add.md)
- [`doctor --family=proxy`](../proxy-doctor.md)
- [Technical contract](technical/1_proxy-remove.md)
