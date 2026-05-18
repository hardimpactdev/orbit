# Technical Contract: `node:default` Rejected For App Callers

[Back to `node:default` technical contract.](1_node-default.md)

This page describes how the gateway rejects the gateway-validated
`node:default` paths for callers whose authenticated node record has role
`app`.

**Effects:** `none` for `choose` and `set` (rejected before prompts or side
effects); `local-only` for `show` and `clear`.

## Behavior

For interactive `choose` and direct `set`, the command calls `/api/me` before
listing nodes, rendering prompts, or writing the local default. If
`self.role=app`, the gateway-validated path fails with
`caller_role_not_allowed`.

The `show` and `clear` sub-actions are local-config-only operations. They do not
require gateway reachability, do not call `/api/me`, and perform no role check.

`node:default` exists to store a CLI-local preference for development app-node
targeting. CLI commands run from an app host typically infer local app or workspace context
rather than targeting remote app nodes through a local default.

## Error Contract

```text
This command may only be run from a operator node.
```

## Failure Semantics

| Failure | Condition | Outcome |
| --- | --- | --- |
| Caller role not allowed | `choose` or `set` is requested and `/api/me` reports `self.role=app`. | Failure before prompts or side effects |

## Test Mapping

Primary test owners:

| Path | Coverage |
| --- | --- |
| `tests/Feature/Commands/Nodes/NodeDefaultOnAppNodeContractTest.php` | App-role choose/set rejection before prompts, node listing, or local default writes; app-role show/clear local-only behavior. |
