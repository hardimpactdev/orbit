# Technical Contract: `orbit node:default [name]`

[Back to public `node:default` documentation.](../node-default.md)

**Owner:** `node`.

**Effects:** `read`, `write`.

**Prerequisites:**
- For the `choose` or `set` sub-action: the CLI caller can reach the Orbit
  gateway, passes the `/api/me` operator-role preflight, and the target node is a
  visible development app node. Gateway and app callers are rejected before
  prompts or local default mutation.
- For the `show` and `clear` sub-actions: no gateway reachability, `/api/me`
  preflight, or role check is required; these paths read or write local CLI
  configuration only.

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
| `choose` | Interactive input mode, no `name`, and no `--clear`. | Present visible development app nodes as choices, then store the selected node as the local default. |
| `show` | Non-interactive input mode, no `name`, and no `--clear`. | Display the current local default. |
| `set` | `name` is present and `--clear` is absent. | Store the resolved target node as the local default. |
| `clear` | `--clear` is present. | Remove the local default. |

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `name` | `[name]` | Never; interactive input mode can prompt through the `choose` sub-action. | `--clear` is present. | None. | Must match a visible development app node when `set` sub-action is selected. Invalid or non-development nodes fail before side effects. |
| `clear` | `--clear` | Optional. | When `name` is present. | `false`. | Boolean flag. Mutually exclusive with `name`. |
| `json` | `--json` | Optional. | Never. | `false`. | Selects the JSON renderer and non-interactive input mode according to the shared invocation model in [`docs/commands/README.md`](../../../README.md#invocation-model). |

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
   - For `choose`, prompt from the visible development app-node list. See
     [`5.1_node-default_input-mode_interactive.md`](5.1_node-default_input-mode_interactive.md).
   - For `show` and `clear`, no node target is resolved.
3. Validate `node_default.name` immediately when the sub-action is `set` or
   `choose`.
   - Configured non-gateway callers must pass a `/api/me` preflight with
     `self.role=control` before prompts or local default writes.
   - Must resolve to a visible development app node.
   - Must not be a gateway or operator node.
   - The caller must be authorized to see the target node.
4. Resolve `node_default.json` from `--json`. Default `false`.

## Input Mode Contracts

- [Interactive input mode](5.1_node-default_input-mode_interactive.md)
- [Non-interactive input mode](5.2_node-default_input-mode_non-interactive.md)

## Behavior Contract

### Choose sub-action

1. Query the gateway for visible development app nodes.
   - Configured callers that are not gateways first call `/api/me`; if `self.role` is not
     `control`, the gateway rejects the command before any prompt is rendered.
2. Present the authorized nodes as choices.
3. Store the selected node as the local default development app node.
4. Return the stored name and the `set` action. `choose` is an interactive
   input path, not a separate persisted result action.

### Show sub-action

1. Read the locally stored default development app node name.
2. If a default is set, return the stored name.
3. If no default is set, return the empty-state result without failure.

No gateway call, `/api/me` preflight, or role check is required for the show
sub-action. If no default is stored and the caller wants to discover a default
interactively, that behavior belongs to the interactive input mode contract.

### Set sub-action

1. For configured non-gateway callers, call `/api/me`; if `self.role` is not
   `control`, reject before local default mutation.
2. Query the gateway for visible nodes.
3. Validate that the resolved `name` matches a visible development app node.
4. Validate that the caller is authorized to operate against that node.
5. Store the name as the local default development app node.
6. Return the stored name and the `set` action.

### Clear sub-action

1. Remove the locally stored default development app node, if any.
2. Return the clear result, indicating whether a default was previously set.

No gateway call, `/api/me` preflight, or role check is required for the clear
sub-action.

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
| Not a development app node | `set` sub-action and the selected node matches a node that is not a development app node. | Failure |
| Not authorized | `set` or `choose` sub-action and the caller is not authorized to see or operate on the selected node. | Failure |

The `show` and `clear` sub-actions do not perform caller-role checks and do not
fail when no default is set. The
`show` sub-action reports the empty state; the `clear` sub-action reports
success with `was_set: false`. Missing target input is not a failure in
interactive input mode because the `choose` sub-action prompts. Missing target
input is not a failure in non-interactive input mode because the `show`
sub-action is selected instead.

## Doctor Relationship

- `node:default` is local operator-node configuration, not gateway configuration.
- `doctor --self` may warn when the configured default no longer resolves or is
  no longer authorized. See `node.local_default_invalid` in
  [`node-doctor.md`](../../node-doctor.md#node-issue-codes).
- `doctor --family=node` verifies gateway node configuration and node reachability,
  not the CLI's local target preference.
- `doctor --self --restore` reports an invalid local default but does not clear or
  replace it. Setting or clearing the local default remains an explicit
  `node:default` action. Recover from `node.local_default_invalid` by running
  `orbit node:default <valid-development-app-node>` or
  `orbit node:default --clear`.

## Activity Logging

The gateway API endpoints emit activity entries for successful and failed
default-node reads and writes.

| Field | Value |
| --- | --- |
| Type | `api:GET /nodes/default` for show, `api:PUT /nodes/default` for set, and `api:DELETE /nodes/default` for clear. |
| Effect | `read` for show; `write` for set and clear. |
| Subject | Target development app `Node` for set when the node is resolved; `none` for show, clear, and failures before target resolution. |
| Properties | `action` is one of `show`, `set`, or `clear`; `default_node` is the selected/stored node name for show and set, or `null` for clear and empty show results. |
| Description | `Default node set to <name>` for set, `Default node cleared` for clear, and derived for show. |

## Test Mapping

Primary test owners:

| Path | Coverage |
| --- | --- |
| `tests/Feature/Commands/Nodes/NodeDefaultCommandTest.php` | Command contract: interactive choose from authorized development app-node choices, show with and without default in non-interactive mode, set with positional `name`, set with invalid/non-development node, clear with and without existing default, mutually exclusive input rejection, gateway-unavailable and authorization failures for choose/set, app-caller denial, gateway-caller denial, and local write guarantee (no gateway mutation, no grant creation). |
| `tests/Feature/Commands/Nodes/NodeDefaultOnControlNodeContractTest.php` | Operator-caller contract: show and clear local-only, `/api/me` preflight before configured choose/set, and gateway-local shortcut rejection. |
| `tests/Feature/Commands/Nodes/NodeDefaultOnAppNodeContractTest.php` | App-caller contract: choose/set rejection before prompts, node listing, or local writes; show and clear local-only. |
| `tests/Feature/Commands/Nodes/NodeDefaultNonInteractiveInputModeTest.php` | Non-interactive input contract, including exact JSON validation output for mutually exclusive `name` and `--clear`. |
| `tests/Feature/Commands/Nodes/NodeDefaultJsonRendererTest.php` | JSON envelope shape, show success with default, show empty state, set success payload, clear success payload with `was_set`, every error code, and enum values. |
| `tests/Feature/Commands/Nodes/NodeDefaultHumanRendererTest.php` | Human renderer selection, choose prompt result prose, show prose, set confirmation prose, clear confirmation prose, empty-state prose, and exact error messages. |

Input-mode-specific test mapping lives in:

- [`5.1_node-default_input-mode_interactive.md`](5.1_node-default_input-mode_interactive.md#test-mapping)
- [`5.2_node-default_input-mode_non-interactive.md`](5.2_node-default_input-mode_non-interactive.md#test-mapping)

Renderer-specific test mapping lives in:

- [`6.1_node-default_output-render_human.md`](6.1_node-default_output-render_human.md#test-mapping)
- [`6.2_node-default_output-render_json.md`](6.2_node-default_output-render_json.md#test-mapping)

Role-specific test mapping lives in:

- [`2_node-default_on-control-node.md`](2_node-default_on-control-node.md#test-mapping)
- [`3_node-default_on-gateway-node.md`](3_node-default_on-gateway-node.md#test-mapping)
- [`4_node-default_on-app-node.md`](4_node-default_on-app-node.md#test-mapping)
