# Technical Contract: `node:default` Rejected On Gateway Hosts

[Back to `node:default` technical contract.](1_node-default.md)

This page describes how gateway hosts reject `node:default`.

**Effects:** `none` (rejected before prompts or side effects).

## Behavior

When `ORBIT_IS_GATEWAY` is set, every sub-action fails before prompts or local
default mutation.

`node:default` exists to store a CLI-local preference for development app-role
targeting. Gateway hosts are not the intended audience for this local config
convenience. Gateway-local development work against nodes is outside the
current command contract.

## Error Contract

```text
node:default is not supported on gateway nodes.
```

## Failure Semantics

| Failure | Condition | Outcome |
| --- | --- | --- |
| Gateway host unsupported | Any sub-action is requested with `ORBIT_IS_GATEWAY` set. | Failure before prompts or side effects |

## Test Mapping

Primary test owners:

| Path | Coverage |
| --- | --- |
| `tests/Feature/Commands/Nodes/NodeDefaultOnControlNodeContractTest.php` | Gateway-host rejection before local default mutation. |
