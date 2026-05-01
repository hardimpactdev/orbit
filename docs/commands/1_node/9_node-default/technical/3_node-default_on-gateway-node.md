# Technical Contract: `node:default` On A Gateway Node

[Back to `node:default` technical contract.](1_node-default.md)

This page describes caller-role behavior when `orbit node:default` is invoked
from a gateway node.

**Effects:** `none` (rejected before prompts or side effects).

## Behavior

Gateway callers are rejected before prompts or side effects.

`node:default` exists to store a control-node-local preference for development
app-node targeting. Gateway nodes are not the intended audience for this local
config convenience. Gateway-local development work against app nodes is outside
the current command contract.

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
| `tests/Feature/Commands/Nodes/NodeDefaultCommandTest.php` | Gateway caller rejection before prompts or side effects. |