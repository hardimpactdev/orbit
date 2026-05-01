# Technical Contract: `node:update` On An App Node

[Back to `node:update` technical contract.](1_node-update.md)

This page describes caller-role behavior when `orbit node:update` is invoked
from an app node.

**Effects:** `none`. The command is rejected before prompts or side effects when
the caller role is `app`.

**Prerequisites:**
- `general.local_node_role` is explicitly set to `app`.
- The caller role has been resolved before command arguments are read or
  interactive prompts are rendered.
- No command argument, option, gateway execution context, SSH target, or
  WireGuard identity prerequisite can make `node:update` valid from an app node.

## Allowed Paths

None. `node:update` may not run from an app node.

## Error Contract

When invoked from an app node, the command must stop immediately and show this
exact human error:

```text
This command may only be run from a control or gateway node.
```

JSON mode returns a structured error with the same message.

## App-Node Rules

- Do not prompt for missing command input.
- Do not forward the command to the gateway.
- Do not write durable node state locally.
- Do not SSH to another node.
- Do not read or mutate gateway node records.

## Failure Semantics

- Fail before side effects for every app-node invocation.
- Exit with the standard command failure status.
- The failure is a caller-role violation, not a validation retry.

## Test Mapping

Primary test owners:

| Path | Coverage |
| --- | --- |
| `tests/Feature/Commands/Nodes/NodeUpdateOnAppNodeContractTest.php` | Primary owner for app-caller rejection: explicit `general.local_node_role=app` callers fail before prompts, forwarding, local writes, SSH, gateway node-record reads, or other side effects. Renderer tests own the human and JSON formatting of that error. |
