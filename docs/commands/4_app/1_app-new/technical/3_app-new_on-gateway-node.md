# `app:new` on Gateway Node

[Back to technical contract](1_app-new.md)

Execution on a gateway node (gateway-local CLI) skips the HTTPS API hop and
executes the domain actions directly.

## Behavior

- **Bypasses API:** The CLI calls the underlying PHP action/service classes
  directly.
- **Enactment:** Same as the control-node path: the gateway orchestrates
  remote work over SSH to the target app node and writes to the local SQLite
  database.
- **Identity:** Uses the gateway's own node identity for authorization.

## Constraints

- Only valid if the local node role is `gateway`.

## Test Mapping

Primary test owners:

| Path | Coverage |
| --- | --- |
| `tests/Feature/Commands/Apps/AppNewOnGatewayNodeContractTest.php` | Gateway-local execution of `app:new`: direct invocation of the underlying actions/services, SQLite intent write, SSH enactment to the target app node via `RemoteShell`, and identical observable result to the forwarded path. |
| `tests/E2E/Ephemeral/AppNewGatewayLocalTest.php` | Real-environment smoke coverage of `app:new` invoked on a gateway node, asserting the API hop is bypassed and the resulting app intent and node artifacts match the control-caller path. |
