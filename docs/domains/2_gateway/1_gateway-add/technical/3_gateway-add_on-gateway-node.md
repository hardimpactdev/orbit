# Technical Contract: `gateway:add` On A Gateway Node

[Back to `gateway:add` technical contract.](1_gateway-add.md)

This page describes behavior when `orbit gateway:add` is invoked from a gateway
host.

**Effects:** `none`. The command rejects gateway-local execution before any
local prompts or side effects.

**Prerequisites:**
- The local process is running in gateway context.

## Behavior

The CLI rejects gateway-host execution before running any local prompts or side
effects.

The gateway is the operator capability layer and the source of its own CA. It does not need
to fetch, trust, or store its own CA through the `gateway:add` flow. Gateway
runtime readiness and local trust material belong to the `node` family and are
verified through `doctor --family=node`.

## Failure Semantics

Fail before prompts or side effects with:

```
This command is not supported on gateway nodes.
```

The JSON renderer returns the same message with `error.code:
"validation_failed"` and `error.meta.reason: "not_supported_on_gateway"`.

## Test Mapping

| Path | Coverage |
| --- | --- |
| `apps/gateway/tests/Feature/Commands/Gateway/GatewayAddCallerRoleContractTest.php` | Gateway-host denial before input resolution, prompts, local writes, forwarding, or side effects. Renderer tests own human and JSON formatting. |
