# Technical Contract: `node:default` In Client Deployment Context

[Back to `node:default` technical contract.](1_node-default.md)

This page describes the supported non-gateway deployment context for
`node:default`. The command stores a local CLI preference; it does not grant
node access and does not require a gateway access grant.

**Effects:** `read`, `write`, `local-only`.

**Post-input path eligibility:**
- For the interactive `choose` path and direct `set` sub-action: the target
  node resolves as a visible active app-dev node from the configured
  gateway or local node registry.
- For `show` and `clear`: no gateway reachability or grant check is required.

## Allowed Paths

| Context | Behavior |
| --- | --- |
| Configured non-gateway CLI | Execute locally. `show` and `clear` use local config only. Interactive `choose` and direct `set` query the gateway for visible development app nodes. |
| Unconfigured non-gateway CLI | Execute locally against local node registry state. `show` and `clear` use local config only. Interactive `choose` and direct `set` can use local active app-dev node records. |

## Show Sub-action

1. Read the local `node_default` preference.
2. If set, return the stored name.
3. If not set, return the empty-state result.

No gateway call is required.

## Set Sub-action

1. Resolve visible active app-dev nodes from the configured gateway or
   local node registry.
2. Validate that the resolved target from `[name]` is a visible development app
   node.
3. Store the name locally as the default development node.
4. Return the stored name.

## Choose Path

1. Resolve visible active app-dev nodes from the configured gateway or
   local node registry.
2. Present those nodes as interactive choices.
3. Store the selected node locally as the default development node.
4. Return the same result as the set sub-action.

When a configured gateway is unreachable, fail before side effects after input
resolution.

## Clear Sub-action

1. Remove the locally stored default development node, if any.
2. Return success. If no default was stored, include `was_set: false` in the
   result metadata.

No gateway call is required.

## Stale Default Behavior

When a stored default does not resolve to an active, authorized development
node, the `show` sub-action still returns the stored name. The stale default
is not cleared automatically. `doctor --self` may report
`node.local_default_invalid`, but repair remains an explicit `node:default`
action.

Commands that resolve app-role targets through `node:default` should fall through
to the next resolution step (app/workspace ownership, interactive prompt, or
non-interactive failure) when the stored default is stale, rather than failing
with the stale default name.

## Error Contract

When a configured gateway is unavailable during the `choose` path or `set`
sub-action:

```text
Gateway connection is required to set a default node.
```

When no configured gateway is present and no local active app-dev node
matches the request:

```text
Node 'app-1' not found or not visible.
```

## Failure Semantics

- `show`: never fails due to gateway unavailability.
- `choose` or `set`: fails before side effects when a configured gateway is
  unreachable, the target node is not found, or the target is not a development
  app node.
- `clear`: never fails due to gateway unavailability.

## Test Mapping

| Path | Coverage |
| --- | --- |
| `apps/cli/tests/Feature/Commands/Node/NodeDefaultCommandTest.php` | Local show and clear behavior plus set validation through mocked gateway list reads. |

There is no gateway-side coverage for this command-local mapping: input handling and renderer behavior live in `apps/cli`. Gateway API behavior is mapped in the command contract file when a gateway-side surface exists.