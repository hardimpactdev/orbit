# Technical Contract: `app:new` From An App Node

[Back to technical contract](1_app-new.md)

This page describes the gateway-side authorization decision when `orbit app:new`
is invoked from a peer the gateway identifies as an app node.

`app:new` is **rejected** by the gateway when the authenticated WireGuard peer
is an app node. The CLI does not detect this locally; it forwards the request,
receives the structured failure, and renders it.

## Behavior

- **Gateway-side rejection:** The gateway identifies the caller through its
  WireGuard peer identity on every API call. When the peer's gateway-owned node
  role is `app`, the gateway rejects `app:new` before prompts, side effects,
  registry reads, or SSH application.
- **Reason:** `app:new` manages app configuration and node application, which
  are gateway-owned responsibilities. App-node identities are not authorized to
  initiate cross-node creation workflows that involve SSH application or
  registry writes.

## Failure Mode

- **Exit status:** standard command failure status (`1`).
- **Error code:** `caller_role_not_allowed` (returned by the gateway).
- **Human message:** "Command 'app:new' cannot be initiated from an app node."

## Test Mapping

Primary test owners:

| Path | Coverage |
| --- | --- |
| `tests/Feature/Commands/Apps/AppNewOnAppNodeRejectionTest.php` | App-node caller rejection for `app:new` (see breakdown below). |

`AppNewOnAppNodeRejectionTest` verifies that `app:new` exits before prompts,
side effects, or registry reads when the gateway identifies the caller as an
app node. It asserts `error.code=caller_role_not_allowed` in JSON output and
the documented human message in TTY output.
