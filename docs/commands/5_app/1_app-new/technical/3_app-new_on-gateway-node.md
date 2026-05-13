# `app:new` From A Gateway Node

[Back to technical contract](1_app-new.md)

This page describes the gateway-side authorization decision when `orbit app:new`
is invoked from a peer the gateway identifies as a gateway node.

## Behavior

- **Allowed:** The gateway identifies the caller through its WireGuard peer
  identity. When the peer's gateway-owned node role is `gateway`, the gateway
  authorizes the request just like a control caller.
- **Apply:** The gateway writes app configuration to its local SQLite database
  and applies app-node artifacts over SSH to the target app node via
  `RemoteShell`. The observable result is identical to the control-caller path.
- **No CLI shortcut:** The CLI still calls the gateway API. There is no
  client-side bypass that skips the API just because the operator happens to be
  running the command on the gateway host.

## Test Mapping

Primary test owners:

| Path | Coverage |
| --- | --- |
| `tests/Feature/Commands/Apps/AppNewOnGatewayNodeContractTest.php` | Gateway-peer authorization of `app:new`: gateway accepts the request, writes app configuration, applies app-node artifacts via `RemoteShell`, and produces the same observable result as the control-caller path. |
| `tests/E2E/Ephemeral/AppNewGatewayLocalTest.php` | Real-environment smoke coverage of `app:new` invoked from a gateway-role peer, asserting the resulting app configuration and node artifacts match the control-caller path. |
