# Technical Contract: `gateway:add` On A Gateway Node

[Back to `gateway:add` technical contract.](1_gateway-add.md)

This page describes caller-role behavior when `orbit gateway:add` is invoked from
a gateway node.

**Effects:** `none`. The command is rejected before prompts or side effects when
the caller role is `gateway`.

**Prerequisites:**
- The caller role has resolved to `gateway` per the node-family
  [Local Caller Role](../../README.md#local-caller-role) contract.

## Behavior

Gateway callers are rejected before prompts or side effects.

The gateway is the control plane and the source of its own CA. It does not need
to fetch, trust, or store its own CA through the `gateway:add` flow. Gateway
runtime readiness and local trust material belong to the `node` family and are
verified through `doctor --family=node`.

## Failure Semantics

Fail before prompts or side effects with:

```
This command may only be run from a control node.
```

The JSON renderer returns the same message with `error.code:
"caller_role_not_allowed"` and `error.meta.caller_role: "gateway"`.

## Test Mapping

| Path | Coverage |
| --- | --- |
| `tests/Feature/Commands/Nodes/GatewayAddCallerRoleContractTest.php` | Gateway caller denial before input resolution, prompts, local writes, forwarding, or side effects. Renderer tests own human and JSON formatting. |