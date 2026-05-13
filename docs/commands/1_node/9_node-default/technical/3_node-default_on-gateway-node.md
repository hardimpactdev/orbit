# Technical Contract: `node:default` Rejected For Gateway Callers

[Back to `node:default` technical contract.](1_node-default.md)

This page describes how the gateway rejects `node:default` for callers whose
authenticated node record has role `gateway`.

**Effects:** `none` (rejected before prompts or side effects).

## Behavior

The gateway rejects gateway-role callers before prompts or side effects.

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
| Caller role not allowed | The caller role is `gateway`. | Failure |

## Test Mapping

Primary test owners:

| Path | Coverage |
| --- | --- |
| `tests/Feature/Commands/Nodes/NodeDefaultCommandTest.php` | Gateway-role caller rejection before prompts or side effects. |