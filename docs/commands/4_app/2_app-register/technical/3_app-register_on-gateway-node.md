# Technical Contract: `orbit app:register` (Gateway Node)

This contract defines behavior when `app:register` is invoked from a **gateway node**.

[Back to the canonical contract.](1_app-register.md)

## Behavior

- **Direct orchestration:** The gateway caller executes the app registration
  flow locally without forwarding through the gateway API.
- **Target eligibility:** The resolved target must be an active app node. The
  gateway is never a valid app target.
- **Path resolution:** `--path` is resolved on the target app node through
  gateway-owned SSH inspection and enactment, not on the gateway filesystem.
- **Enactment:** The gateway writes app intent locally and enacts app-node
  artifacts over SSH via `RemoteShell`.

## Test Mapping

| Path | Coverage |
| --- | --- |
| `tests/Feature/Commands/Apps/RegisterAppOnGatewayNodeTest.php` | Gateway caller local orchestration without HTTPS forwarding, active app-node target requirement, `--node=gateway-1` rejection with `app.ineligible_node`, app-node path resolution over gateway-owned SSH, and app-node artifact enactment through `RemoteShell`. |
