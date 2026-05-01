# Technical Contract: `orbit app:register` (App Node)

This contract defines behavior when `app:register` is invoked from an **app node**.

[Back to the canonical contract.](1_app-register.md)

## Validity

- **Rejected**: App nodes are not permitted to register applications. Registration is a management action reserved for control and gateway nodes.

## Failure Semantics

- **Error Code**: `caller_role_not_allowed`
- **Human Message**: "App nodes cannot register applications. Please run this command from a control or gateway node."

## Test Mapping

| Path | Coverage |
| --- | --- |
| `tests/Feature/Commands/Apps/RegisterAppCommandTest.php` | Assertion that app-role callers receive an immediate caller-role failure. |
