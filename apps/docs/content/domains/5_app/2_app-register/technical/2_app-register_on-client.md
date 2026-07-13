# Technical Contract: `orbit app:register` From An Operator Node

This contract defines gateway-side behavior when `app:register` is invoked from
an operator identity.

[Back to the canonical contract.](1_app-register.md)

## Behavior

- **Allowed:** The gateway authorizes the request based on the authenticated
  WireGuard peer identity.
- **Apply:** The gateway runs the full registration and apply pipeline. App
  configuration is written to the gateway database, and app-role artifacts are
  applied to the target node through authenticated Agent push over WireGuard.
- **No CLI shortcut:** The CLI forwards the request to the gateway API. There
  is no operator-node branch that performs SSH directly from the invoking host.

## Test Mapping

| Path | Coverage |
| --- | --- |
| `apps/cli/tests/Feature/Commands/App/AppWriteCommandTest.php` | CLI register POST payload and gateway client request forwarding. |

There is no gateway-side coverage for this on-client mapping: operator-caller behavior lives in `apps/cli`. Gateway API behavior is mapped in the command contract file.
