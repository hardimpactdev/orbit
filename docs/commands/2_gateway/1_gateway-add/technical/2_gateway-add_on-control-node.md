# Technical Contract: `gateway:add` On A Control Node

[Back to `gateway:add` technical contract.](1_gateway-add.md)

This page describes caller-role behavior when `orbit gateway:add` is invoked from
a control node.

**Prerequisites:**
- The caller role has resolved to `control` per the node-family
  [Local Caller Role](../../../1_node/README.md#local-caller-role) contract.

**Post-input path eligibility:**
- The control node has an active gateway-issued WireGuard identity.
- The control node has joined the active Orbit WireGuard network.
- The target gateway is reachable over the Orbit network.
- The local platform is supported for control nodes (macOS or Ubuntu).

Evaluate each path eligibility rule as soon as the fields needed for that rule
are known. For example, a control caller with no active WireGuard identity
shows a validation message before side effects begin. Non-interactive input
mode fails before side effects for the same blocker.

## Allowed Paths

| Path | Behavior |
| --- | --- |
| First add | Local gateway onboarding state is absent. Run the onboarding flow: fetch CA, trust CA, verify `/api/me`, verify identity, store local config. |
| Converged | Local gateway trust and settings already match the target gateway and `/api/me` verifies the local identity. Return success with a converged indicator. |
| Local onboarding refresh | Local gateway trust or settings are missing or stale, but the target gateway and local identity remain valid. Re-apply the local onboarding flow and return success. |

## Behavior

1. Derive or resolve `gateway_add.gateway_ip`.
2. Check local gateway onboarding state.
3. Fetch the gateway root CA through the bootstrap-safe trust path.
4. Install or refresh local CA trust.
5. Verify HTTPS reachability to `/api/me` using the trusted CA.
6. Verify the local WireGuard identity is known and accepted by the gateway.
7. Store the gateway WireGuard IP and trust material in local settings.
8. Persist caller-local gateway settings and trust metadata.

The control path must not create or update a local Orbit database as a registry
mirror of the gateway or current control node. It stores local gateway endpoint
and trust configuration only.

## Failure Semantics

- Fails when the gateway is unreachable over the Orbit network.
- Fails when gateway trust material cannot be fetched or installed.
- Fails when `/api/me` returns 403 (unregistered peer).
- Fails as `gateway_unavailable` when `/api/me` returns a non-success status
  other than 403.
- Fails when local config cannot be written despite successful gateway
  verification.
- Fails when the local platform is not supported for control nodes.

## Test Mapping

| Path | Coverage |
| --- | --- |
| `tests/Feature/Commands/Gateway/GatewayAddCallerRoleContractTest.php` | Control caller behavior: first add flow, idempotent convergence, local onboarding refresh, CA fetch, `/api/me` verification, local settings write, no local node registry mirror creation, and context flush. |
| `tests/E2E/GatewayAddTest.php` | Real-node control node join flow via `gateway:add`, including omitted-argument gateway IP derivation and idempotent convergence. |
