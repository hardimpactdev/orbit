# Technical Contract: `node:new` On An App Node

[Back to `node:new` technical contract.](1_node-new.md)

This page describes caller-role behavior when `orbit node:new` is invoked from
an app node.

**Effects:** `none`. The command is rejected before prompts or side effects.

**Prerequisites:**
- `general.local_node_role` is explicitly set to `app`.
- The caller role has been resolved before command inputs are read or
  interactive prompts are rendered.
- No requested role, command argument, option, gateway connection, SSH target, or
  WireGuard identity prerequisite can make `node:new` valid from an app node.

## Allowed Paths

None. `node:new` may not run from an app node for any requested role.

| Requested role | Behavior |
| --- | --- |
| `gateway` | Fail before prompts or side effects. |
| `app` | Fail before prompts or side effects. |
| `control` | Fail before prompts or side effects. |

## Error Contract

When invoked from an app node, the command must stop immediately and show this
exact human error:

```text
This command may only be run from a control or gateway node.
```

JSON mode returns a structured error with the same message.

## App-Node Rules

- Do not prompt for missing command input.
- Do not show a node choice prompt.
- Do not forward the command to the gateway.
- Do not write durable node state locally.
- Do not SSH to another node.
- Do not mint WireGuard identity material.

## Failure Semantics

- Fail before side effects for every requested role.
- Exit with the standard command failure status.
- The failure is a caller-role violation, not a validation retry.

## Test Mapping

Primary test owners:

| Path | Coverage |
| --- | --- |
| `tests/Feature/Commands/Nodes/NodeNewOnAppNodeContractTest.php` | Primary owner for app-caller rejection: explicit `general.local_node_role=app` callers with `--role=gateway`, `--role=app`, `--role=control`, and no `--role` fail before prompts, forwarding, local writes, SSH, WireGuard minting, or other side effects. Renderer tests own the human and JSON formatting of that error. |
| `tests/E2E/Ephemeral/NodeNewAppNodeRejectionTest.php` | Real-node smoke coverage proving caller-role rejection happens on an app node before gateway forwarding or node-state mutation. |
