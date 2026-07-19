# `app:new` From A Gateway Node

[Back to technical contract](1_app-new.md)

This page describes the gateway-side authorization decision when `orbit app:new`
is invoked from a peer the gateway identifies as a gateway node.

## Behavior

- **Allowed:** The gateway identifies the caller through its WireGuard peer
  identity. When the peer's gateway-owned node role is `gateway`, gateway
  implicit authority authorizes the request.
- **Apply:** The gateway writes app configuration to its local SQLite database
  and applies `app-dev` or `app-prod` artifacts through the selected node
  execution transport.
  The observable result is identical to the operator-caller path.
- **No CLI shortcut:** The CLI still calls the gateway API. There is no
  client-side bypass that skips the API just because the operator happens to be
  running the command on the gateway host.

## Test Mapping

Primary test owners:

| Path | Coverage |
| --- | --- |
| `apps/gateway/tests/Feature/Http/Api/AppStoreControllerTest.php` | Gateway API path writes registry and applies through the mocked node-execution/Agent-push boundary. |
| `apps/gateway/tests/Feature/Http/Api/AppStoreStreamControllerTest.php` | Gateway stream tree and source-command forwarding for clone and template branches. |
