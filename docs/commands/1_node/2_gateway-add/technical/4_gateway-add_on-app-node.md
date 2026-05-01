# Technical Contract: `gateway:add` On An App Node

[Back to `gateway:add` technical contract.](1_gateway-add.md)

This page describes caller-role behavior when `orbit gateway:add` is invoked from
an app node.

**Effects:** `none`. The command is rejected before prompts or side effects when
the caller role is `app`.

**Prerequisites:**
- The caller role has resolved to `app` per the node-family
  [Local Caller Role](../../README.md#local-caller-role) contract.

## Behavior

App-node callers are rejected before prompts or side effects.

App nodes run the Orbit CLI as a stateless gateway client. Their gateway-client
endpoint and trust artifacts are installed and repaired by the gateway through
node bootstrap, app-node readiness repair, and node-family doctor behavior.
`gateway:add` is not the app-node path for managing those artifacts.

When an app-node CLI command needs to reach the gateway, it uses the
gateway-managed endpoint and trust available on the app node, then calls the
gateway over HTTPS through WireGuard. App-node gateway-client configuration is a
node bootstrap artifact, not a separate state family and not a control-node local
onboarding flow.

## Failure Semantics

Fail before prompts or side effects with:

```
This command may only be run from a control node.
```

The JSON renderer returns the same message with `error.code:
"caller_role_not_allowed"` and `error.meta.caller_role: "app"`.

## Test Mapping

| Path | Coverage |
| --- | --- |
| `tests/Feature/Commands/Nodes/GatewayAddCallerRoleContractTest.php` | App caller denial before input resolution, prompts, local writes, forwarding, or side effects. Renderer tests own human and JSON formatting. |
