# Technical Contract: `orbit node:default [name]`

[Back to public `node:default` documentation.](../node-default.md)

**Owner:** `node`.

**Effects:** `read`, `write`.

**Prerequisites:**
- `node:default` is a per-operator-host local-only command. It mutates only
  `~/.config/orbit/config.json` on the invoking machine. There is no
  gateway-side default-node store and no gateway-side `/api/nodes/default`
  endpoint; the default is per-operator-host, not per-gateway.
- Gateway hosts run `node:default` like any other operator host: it edits the
  operator user's local CLI config.
- For the `choose` or `set` sub-action: the target node resolves as a visible
  active app-dev node, validated against the configured gateway when
  one is reachable; the stored default remains local CLI state regardless.
- For the `show` and `clear` sub-actions: no gateway reachability or grant
  check is required; these paths read or write local CLI configuration only.

## Signature

```bash
orbit node:default [name] [--clear] [--json]
```

## Input Contract

This command follows the shared
[Invocation Model](../../../README.md#invocation-model).

The command has four mutually exclusive sub-actions determined by the resolved
input:

| Sub-action | Trigger | Description |
| --- | --- | --- |
| `choose` | Interactive input mode, no `name`, and no `--clear`. | Present visible development nodes as choices, then store the selected node as the local default. |
| `show` | Non-interactive input mode, no `name`, and no `--clear`. | Display the current local default. |
| `set` | `name` is present and `--clear` is absent. | Store the resolved target node as the local default. |
| `clear` | `--clear` is present. | Remove the local default. |

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `name` | `[name]` | Never; interactive input mode can prompt through the `choose` sub-action. | `--clear` is present. | None. | Must match a visible development node when `set` sub-action is selected. Invalid or non-development nodes fail before side effects. |
| `clear` | `--clear` | Optional. | When `name` is present. | `false`. | Boolean flag. Mutually exclusive with `name`. |
| `json` | `--json` | Optional. | Never. | `false`. | Selects the JSON renderer and non-interactive input mode according to the shared invocation model in [`docs/domains/README.md`](../../../README.md#invocation-model). |

## Input Resolution

1. Resolve sub-action from input.
   - If `--clear` is present, select `clear`. If `[name]` is also
     present, fail before side effects (mutually exclusive).
   - If `[name]` is present and `--clear` is absent, select `set`.
   - If no target and no `--clear` is present in interactive input mode, select
     `choose`.
   - If no target and no `--clear` is present in non-interactive input mode,
     select `show`.
2. Resolve `node_default.name`.
   - For `set`, resolve from `[name]`.
   - For `choose`, prompt from the visible development app-role list. See
     [`5.1_node-default_input-mode_interactive.md`](5.1_node-default_input-mode_interactive.md).
   - For `show` and `clear`, no node target is resolved.
3. Validate `node_default.name` immediately when the sub-action is `set` or
   `choose`.
   - Must resolve to a visible active app-dev node.
   - Must not be a gateway, operator, or production app node.
4. Resolve `node_default.json` from `--json`. Default `false`.

## Input Mode Contracts

- [Interactive input mode](5.1_node-default_input-mode_interactive.md)
- [Non-interactive input mode](5.2_node-default_input-mode_non-interactive.md)

## Behavior Contract

### Choose sub-action

1. Query the configured gateway for visible development app nodes, or the local
   node registry when no gateway is configured.
2. Present the visible development app nodes as choices.
3. Store the selected node as the local default development node.
4. Return the stored name and the `set` action. `choose` is an interactive
   input path, not a separate persisted result action.

### Show sub-action

1. Read the locally stored default development node name.
2. If a default is set, return the stored name.
3. If no default is set, return the empty-state result without failure.

No gateway call or grant check is required. If no default is stored and the
caller wants to discover a default interactively, that behavior belongs to the
interactive input mode contract.

### Set sub-action

1. Query the configured gateway for visible development app nodes, or the local
   node registry when no gateway is configured.
2. Validate that the resolved `name` matches a visible active app-dev
   node.
3. Store the name as the local default development node.
4. Return the stored name and the `set` action.

### Clear sub-action

1. Remove the locally stored default development node, if any.
2. Return the clear result, indicating whether a default existed.

No gateway call or grant check is required for the clear sub-action.

### Scope Boundaries

`node:default` must not:
- Mutate gateway node configuration or node records.
- Grant access to the default node.
- Change the gateway endpoint configured by `gateway:add`.
- SSH into nodes.
- Mint identity, write peer material, or modify WireGuard state.
- Touch downstream family state.

## Renderer Contracts

- [Human renderer](6.1_node-default_output-render_human.md)
- [JSON renderer](6.2_node-default_output-render_json.md)

## Failure Semantics
Standard failures defined in [Common Failures](../../../README.md#common-failures) apply; command-specific failures below.

| Failure | Condition | Outcome |
| --- | --- | --- |
| Mutually exclusive input | `--clear` is combined with `name`. | Failure |
| Node not found | `set` or `choose` sub-action and the selected node does not match a visible node. | Failure |
| Not a development node | `set` sub-action and the selected node matches a node that is not a development node. | Failure |

The `show` and `clear` sub-actions do not perform grant checks and do not fail
when no default is set. The `show` sub-action reports the empty state; the
`clear` sub-action reports success with `was_set: false`. Missing target input
is not a failure in interactive input mode because the `choose` sub-action
prompts. Missing target input is not a failure in non-interactive input mode
because the `show` sub-action is selected instead.

## Doctor Relationship

- `node:default` is local client configuration, not gateway configuration.
- `doctor --self` may warn when the configured default does not resolve or is
  not authorized. See `node.local_default_invalid` in
  [`node-doctor.md`](../../node-doctor.md#node-issue-codes).
- `doctor --family=node` verifies gateway node configuration and node reachability,
  not the CLI's local target preference.
- `doctor --self --restore` reports an invalid local default but does not clear or
  replace it. Setting or clearing the local default remains an explicit
  `node:default` action. Recover from `node.local_default_invalid` by running
  `orbit node:default <valid-development-app-role>` or
  `orbit node:default --clear`.

## Activity Logging

`node:default` does not emit gateway activity log entries. It is local CLI
configuration that mutates only `~/.config/orbit/config.json` on the invoking
machine. Gateway validation calls used by the `choose` or `set` sub-actions
may produce their own API request telemetry, but the default-node mutation
itself is local state.

## Test Mapping

Primary test owners:

| Path | Coverage |
| --- | --- |
| `apps/cli/tests/Feature/Commands/Node/NodeDefaultCommandTest.php` | Interactive choose, show/set/clear sub-actions, mutually exclusive input rejection, gateway-unavailable failures, local-only write guarantee, human renderer prose, and JSON envelope shape. |

There is no gateway-side coverage for this command-local mapping: `node:default` mutates local CLI configuration only and does not call gateway mutation endpoints.

Input-mode-specific test mapping lives in:

- [`5.1_node-default_input-mode_interactive.md`](5.1_node-default_input-mode_interactive.md#test-mapping)
- [`5.2_node-default_input-mode_non-interactive.md`](5.2_node-default_input-mode_non-interactive.md#test-mapping)

Renderer-specific test mapping lives in:

- [`6.1_node-default_output-render_human.md`](6.1_node-default_output-render_human.md#test-mapping)
- [`6.2_node-default_output-render_json.md`](6.2_node-default_output-render_json.md#test-mapping)

Deployment-context-specific test mapping lives in:

- [`2_node-default_on-client.md`](2_node-default_on-client.md#test-mapping)
- [`3_node-default_on-gateway-node.md`](3_node-default_on-gateway-node.md#test-mapping)