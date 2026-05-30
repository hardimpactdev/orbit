# Technical Contract: `node:default` On Gateway Hosts

[Back to `node:default` technical contract.](1_node-default.md)

This page documents the retired gateway-host rejection path and the current
target behavior.

**Effects:** `read`, `write`.

## Behavior

Gateway hosts run `node:default` like any other operator host. The command edits
the invoking OS user's local CLI configuration at
`~/.config/orbit/config.json`; it does not read or write any gateway-side
default-node table or `/api/nodes/default` endpoint.

The gateway runtime may still set `ORBIT_IS_GATEWAY=true` for gateway-runtime
services and maintenance commands. That runtime flag is not a reason for the
public Orbit CLI binary command to reject local default-node configuration.

## Error Contract

There is no gateway-host-specific error contract. Gateway hosts use the same
validation and gateway-unavailable failures as other operator hosts.

## Failure Semantics

| Failure | Condition | Outcome |
| --- | --- | --- |
| Gateway-host rejection | Historical behavior only. | Retired; do not implement. |

## Test Mapping

Primary test owners:

| Path | Coverage |
| --- | --- |
| `apps/cli/tests/Feature/Commands/Node/NodeDefaultOnGatewayHostTest.php` | Gateway-host execution edits local CLI config and does not call gateway-side default-node routes. |
