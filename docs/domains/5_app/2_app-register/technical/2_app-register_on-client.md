# Technical Contract: `orbit app:register` From A Node

This contract defines gateway-side behavior when `app:register` is invoked from
a peer the gateway identifies as a **client**.

[Back to the canonical contract.](1_app-register.md)

## Behavior

- **Allowed:** The gateway authorizes the request based on the authenticated
  WireGuard peer identity.
- **Apply:** The gateway runs the full registration and apply pipeline. App
  configuration is written to the gateway database, and app-role artifacts are
  applied over SSH to the target node via `RemoteShell`.
- **No CLI shortcut:** The CLI forwards the request to the gateway API. There
  is no client-side branch that performs SSH directly from the operator peer.

## Test Mapping

| Path | Coverage |
| --- | --- |
| `tests/Feature/Commands/Apps/AppRegisterCommandTest.php` | Verification of gateway client request and identity propagation. |
