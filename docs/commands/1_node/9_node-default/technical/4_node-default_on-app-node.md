# Technical Contract: `node:default` On An App Node

[Back to `node:default` technical contract.](1_node-default.md)

This page describes caller-role behavior when `orbit node:default` is invoked
from an app node.

**Effects:** `none` (rejected before prompts or side effects).

## Behavior

App-node callers are rejected before prompts or side effects.

`node:default` exists to store a control-node-local preference for development
app-node targeting. App-node CLI commands typically infer local app or workspace
context rather than targeting remote app nodes through a local default.

## Error Contract

```text
This command may only be run from a control node.
```

## Failure Semantics

| Failure | Condition | Outcome |
| --- | --- | --- |
| Caller role not allowed | The caller role is `app`. | Failure |

## Test Mapping

Primary test owners:

| Path | Coverage |
| --- | --- |
| `tests/Feature/Commands/Nodes/NodeDefaultCommandTest.php` | App-node caller rejection before prompts or side effects. |