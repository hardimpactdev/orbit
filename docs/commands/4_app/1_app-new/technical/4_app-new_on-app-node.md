# `app:new` on App Node

[Back to technical contract](1_app-new.md)

`app:new` is **rejected** when initiated from an app node.

## Behavior

- **Pre-flight Rejection:** The CLI must reject the command before prompts or
  side effects if the local node role is `app`.
- **Reason:** `app:new` manages app intent and node enactment, which are
  gateway-only responsibilities. App nodes are not permitted to initiate
  creation workflows that involve cross-node SSH enactment or registry writes.

## Failure Mode

- **Exit status:** standard command failure status (`1`).
- **Message:** "Command 'app:new' cannot be initiated from an app node."

## Test Mapping

Primary test owners:

| Path | Coverage |
| --- | --- |
| `tests/Feature/Commands/Apps/AppNewOnAppNodeRejectionTest.php` | App-node caller rejection: `app:new` exits before prompts, side effects, or registry reads when `general.local_node_role=app`, with `error.code=caller_role_not_allowed` in JSON output and the documented human message in TTY output. |
