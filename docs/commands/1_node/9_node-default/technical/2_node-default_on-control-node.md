# Technical Contract: `node:default` On A Control Node

[Back to `node:default` technical contract.](1_node-default.md)

This page describes caller-role behavior when `orbit node:default` is invoked
from a control node.

**Effects:** `read`, `write`, `local-only`.

**Prerequisites:**
- `general.local_node_role` is unset, `null`, or explicitly `control`.

**Post-input path eligibility:**
- For the interactive `choose` path and direct `set` sub-action: the control
  node can reach the gateway API over HTTPS through WireGuard, and the target
  node is a visible development app node.

## Allowed Paths

| Context | Behavior |
| --- | --- |
| Configured control node | Execute locally. `show` and `clear` use local config only. Interactive `choose` and direct `set` query the gateway for node choices or validation. |
| Unconfigured control node | `show` and `clear` work with local state if any. Interactive `choose` and direct `set` fail before side effects because gateway reachability is required. |

## Show Sub-action

1. Read the local `node_default` preference.
2. If set, return the stored name.
3. If not set, return the empty-state result.

No gateway call is required.

## Set Sub-action

1. Query the gateway for visible nodes.
2. Validate that the resolved target from `[name]` is a visible development app
   node.
3. Validate caller authorization for the target node.
4. Store the name locally as the default development app node.
5. Return the stored name.

## Choose Path

1. Query the gateway for visible development app nodes.
2. Present those nodes as interactive choices.
3. Store the selected node locally as the default development app node.
4. Return the same result as the set sub-action.

When the gateway is unreachable, fail before side effects after input
resolution.

## Clear Sub-action

1. Remove the locally stored default development app node, if any.
2. Return success. If no default was stored, include `was_set: false` in the
   result metadata.

No gateway call is required.

## Stale Default Behavior

When a stored default no longer resolves to an active, authorized development
app node, the `show` sub-action still returns the stored name. The stale default
is not cleared automatically. `doctor --self` may report
`node.local_default_invalid`, but repair remains an explicit `node:default`
action.

Commands that resolve app-node targets through `node:default` should fall through
to the next resolution step (app/workspace ownership, interactive prompt, or
non-interactive failure) when the stored default is stale, rather than failing
with the stale default name.

## Error Contract

When no gateway is configured locally and the `choose` path or `set` sub-action
is requested:

```text
Gateway connection is required to set a default node.
```

When the gateway is unreachable during `choose` or `set`:

```text
Gateway is unreachable. Cannot validate node 'app-1'.
```

## Failure Semantics

- `show`: never fails due to gateway unavailability.
- `choose` or `set`: fails before side effects when no gateway is configured,
  the gateway is unreachable, the target node is not found, the target is not a
  development app node, or the caller is not authorized.
- `clear`: never fails due to gateway unavailability.

## Test Mapping

Primary test owners:

| Path | Coverage |
| --- | --- |
| `tests/Feature/Commands/Nodes/NodeDefaultCommandTest.php` | Control caller choose, show, set, and clear behavior; configured vs unconfigured gateway for choose/set; stale default handling. |
| `tests/Feature/Commands/Nodes/NodeDefaultOnControlNodeContractTest.php` | Control-caller local config read/write, gateway choices for choose, gateway validation for set, unconfigured control node choose/set failure, stale default behavior, and no gateway intent mutation. |