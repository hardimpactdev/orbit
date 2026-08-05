# Technical Contract: `orbit instance:register` From A Gateway Node

This contract defines gateway-side behavior when `instance:register` is invoked from
a peer the gateway identifies as a **gateway node**.

[Back to the canonical contract.](1_instance-register.md)

## Behavior

- **Allowed:** The gateway authorizes the request based on the authenticated
  WireGuard peer identity.
- **Target eligibility:** The resolved target must be an active node. The
  gateway is never a valid instance target. `--node=<gateway-slug>` is rejected with
  `app.ineligible_node`.
- **Path resolution:** `--path` is resolved on the target node through
  gateway-owned Agent-push inspection and application, not on the gateway filesystem.
- **Apply:** The gateway writes selected-instance configuration locally (plus
  the parent app atomically for first adoption) and applies app-role artifacts
  to the instance's serving node through authenticated Agent push over WireGuard,
  even when the CLI invocation originated on the gateway host. The CLI still
  calls the gateway API; there is no client-side bypass.

## Test Mapping

| Path | Coverage |
| --- | --- |
| `apps/gateway/tests/Feature/Http/Api/AppRegisterControllerTest.php` | Gateway-peer selected-instance registration, ineligible-node rejection, and app-role artifact application. |
