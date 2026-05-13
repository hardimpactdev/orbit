# Technical Contract: `node:default` Rejected For App Callers

[Back to `node:default` technical contract.](1_node-default.md)

This page describes how the gateway rejects `node:default` for callers whose
authenticated node record has role `app`.

**Effects:** `none` (rejected before prompts or side effects).

## Behavior

The gateway rejects app-role callers before prompts or side effects.

`node:default` exists to store a CLI-local preference for development app-node
targeting. App-host CLI commands typically infer local app or workspace context
rather than targeting remote app nodes through a local default.

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
| `tests/Feature/Commands/Nodes/NodeDefaultCommandTest.php` | App-role caller rejection before prompts or side effects. |