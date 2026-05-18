# Technical Contract: `orbit app:register` From A Gateway Node

This contract defines gateway-side behavior when `app:register` is invoked from
a peer the gateway identifies as a **gateway node**.

[Back to the canonical contract.](1_app-register.md)

## Behavior

- **Allowed:** The gateway authorizes the request based on the authenticated
  WireGuard peer identity.
- **Target eligibility:** The resolved target must be an active app node. The
  gateway is never a valid app target. `--node=<gateway-slug>` is rejected with
  `app.ineligible_node`.
- **Path resolution:** `--path` is resolved on the target app node through
  gateway-owned SSH inspection and application, not on the gateway filesystem.
- **Apply:** The gateway writes app configuration locally and applies app-node
  artifacts over SSH to the target app node via `RemoteShell` — even when the
  CLI invocation originated on the gateway host. The CLI still calls the
  gateway API; there is no client-side bypass.

## Test Mapping

| Path | Coverage |
| --- | --- |
| `tests/Feature/Commands/Apps/AppRegisterCommandTest.php` | Gateway-peer authorization without a client-side shortcut, active app-node target requirement, `--node=gateway-1` rejection with `app.ineligible_node`, app-node path resolution over gateway-owned SSH, and app-node artifact application through `RemoteShell`. |
