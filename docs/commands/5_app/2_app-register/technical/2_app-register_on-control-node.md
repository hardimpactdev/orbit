# Technical Contract: `orbit app:register` (Control Node)

This contract defines behavior when `app:register` is invoked from a **control node**.

[Back to the canonical contract.](1_app-register.md)

## Behavior

- **Gateway Proxy**: The command acts as a client to the Orbit gateway over HTTPS.
- **Identity**: Uses the control node's authorized identity to authenticate with the gateway.
- **Visibility**: Can register any app on any app node authorized for the control node's identity.
- **Enactment**: The gateway performs the actual SSH enactment to the target app node.

## Test Mapping

| Path | Coverage |
| --- | --- |
| `tests/Feature/Commands/Apps/AppRegisterCommandTest.php` | Verification of gateway client request and identity propagation. |
