# Technical Contract: `node:default` Authorized For Control Callers

[Back to `node:default` technical contract.](1_node-default.md)

This page describes what the gateway authorizes for callers whose
authenticated node record has role `control`. The `show` and `clear`
sub-actions are purely local CLI configuration and do not require gateway
authentication.

**Effects:** `read`, `write`, `local-only`.

**Post-input path eligibility:**
- For the interactive `choose` path and direct `set` sub-action: the CLI can
  reach the gateway API over HTTPS through WireGuard, `/api/me` reports
  `self.role=control`, and the target node is a visible development app node.

## Allowed Paths

| Context | Behavior |
| --- | --- |
| Configured CLI authenticated as a operator caller | Execute locally. `show` and `clear` use local config only. Interactive `choose` and direct `set` call `/api/me` before querying the gateway for node choices or validation. |
| Unconfigured CLI | `show` and `clear` work with local state if any. Interactive `choose` and direct `set` fail before side effects because gateway reachability is required. |

## Show Sub-action

1. Read the local `node_default` preference.
2. If set, return the stored name.
3. If not set, return the empty-state result.

No gateway call is required.

## Set Sub-action

1. Call `/api/me` and require `self.role=control`.
2. Query the gateway for visible nodes.
3. Validate that the resolved target from `[name]` is a visible development app
   node.
4. Validate caller authorization for the target node.
5. Store the name locally as the default development app node.
6. Return the stored name.

## Choose Path

1. Call `/api/me` and require `self.role=control`.
2. Query the gateway for visible development app nodes.
3. Present those nodes as interactive choices.
4. Store the selected node locally as the default development app node.
5. Return the same result as the set sub-action.

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
| `tests/Feature/Commands/Nodes/NodeDefaultCommandTest.php` | Operator-caller choose, show, set, and clear behavior; configured vs unconfigured CLI for choose/set; stale default handling. |
| `tests/Feature/Commands/Nodes/NodeDefaultOnControlNodeContractTest.php` | Operator-caller local-only show/clear behavior, `/api/me` preflight before configured choose/set, gateway choices for choose, gateway validation for set, gateway-local shortcut rejection, and no gateway configuration mutation. |
