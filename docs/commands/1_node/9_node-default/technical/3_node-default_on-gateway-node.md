# Technical Contract: `node:default` Rejected For Gateway Callers

[Back to `node:default` technical contract.](1_node-default.md)

This page describes how the gateway-local shortcut rejects the
gateway-validated `node:default` paths.

**Effects:** `none` for `choose` and `set` (rejected before prompts or side
effects); `local-only` for `show` and `clear`.

## Behavior

When `ORBIT_IS_GATEWAY` is set, interactive `choose` and direct `set` fail
before prompts or local default mutation. This is the documented gateway-local
self-call shortcut.

The `show` and `clear` sub-actions are local-config-only operations. They do not
require gateway reachability, do not call `/api/me`, and perform no role check.

`node:default` exists to store a CLI-local preference for development app-node
targeting. Gateway hosts are not the intended audience for this local config
convenience. Gateway-local development work against app nodes is outside the
current command contract.

## Error Contract

```text
This command may only be run from a control node.
```

## Failure Semantics

| Failure | Condition | Outcome |
| --- | --- | --- |
| Caller role not allowed | `choose` or `set` is requested with `ORBIT_IS_GATEWAY` set. | Failure before prompts or side effects |

## Test Mapping

Primary test owners:

| Path | Coverage |
| --- | --- |
| `tests/Feature/Commands/Nodes/NodeDefaultOnControlNodeContractTest.php` | Gateway-local shortcut rejection before local default mutation. |
