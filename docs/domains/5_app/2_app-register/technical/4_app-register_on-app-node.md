# Technical Contract: `orbit app:register` From An App Node

This contract defines gateway-side behavior when `app:register` is invoked from
a peer the gateway identifies as an **app node**.

[Back to the canonical contract.](1_app-register.md)

## Validity

- **Rejected by the gateway:** App-node peers are not authorized to drive
  registration. Registration is a management action reserved for control and
  gateway peers. The CLI does not detect this locally; it forwards the request
  and surfaces the gateway's rejection.

## Failure Semantics

- **Error Code:** `caller_role_not_allowed`
- **Human Message:** "App nodes cannot register applications. Please run this command from a control or gateway node."

## Test Mapping

| Path | Coverage |
| --- | --- |
| `tests/Feature/Commands/Apps/AppRegisterCommandTest.php` | Assertion that app-role peers receive an immediate `caller_role_not_allowed` failure from the gateway. |
