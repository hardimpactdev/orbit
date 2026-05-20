# Technical Contract: `gateway:add` On A Node Carrying An App Role

[Back to `gateway:add` technical contract.](1_gateway-add.md)

This page describes caller-role behavior when `orbit gateway:add` is invoked from
a node with an app role.

**Effects:** `read`, `write` until a gateway check backed by authority fails.
`gateway:add` has no local app-role rejection point because this command has no
local app-role source backed by authority.

**Prerequisites:**
- The node already has gateway-managed endpoint and trust artifacts from
  node bootstrap or repair.

## Behavior

The local CLI does not infer an app caller role for `gateway:add`. If a node with an app role
runs `gateway:add`, the command follows the normal local onboarding path until a
gateway request reaches a role or identity check backed by authority.

Nodes run the Orbit CLI as a stateless gateway client. Their gateway-client
endpoint and trust artifacts are installed and repaired by the gateway through
node bootstrap, app-role readiness repair, and node-family doctor behavior.
`gateway:add` is not the app-role path for managing those artifacts.

When a CLI command on a node with an app role needs to reach the gateway, it uses the
gateway-managed endpoint and trust available on the node, then calls the
gateway over HTTPS through WireGuard. App-role gateway-client configuration is a
node bootstrap artifact, not a separate state family and not a client local
onboarding flow.

## Failure Semantics

There is no app-role-specific pre-prompt failure for `gateway:add`. If an app
node reaches a gateway role check backed by authority, that gateway response is
surfaced by the selected renderer.

Gateway-local denial still uses:

```
This command may only be run from a client.
```

The JSON renderer returns the same message with `error.code:
"caller_role_not_allowed"` and `error.meta.caller_role: "gateway"` when
`ORBIT_IS_GATEWAY` marks the local process as gateway context.

## Test Mapping

| Path | Coverage |
| --- | --- |
| `tests/Feature/Commands/Gateway/GatewayAddCallerRoleContractTest.php` | Gateway-local denial before input resolution, prompts, local writes, forwarding, or side effects. No local app-role guessing is introduced for `gateway:add`. Renderer tests own human and JSON formatting. |
