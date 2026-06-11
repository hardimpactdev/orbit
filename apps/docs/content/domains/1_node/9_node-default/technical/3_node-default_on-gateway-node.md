# Technical Contract: `node:default` On Gateway Hosts

[Back to `node:default` technical contract.](1_node-default.md)

This page documents the gateway-host behavior for `node:default`.

**Effects:** `read`, `write`.

## Behavior

Gateway hosts run `node:default` like any other operator host. The command edits
the invoking OS user's local CLI configuration at
`~/.config/orbit/config.json`; it does not read or write any gateway-side
default-node table or `/api/nodes/default` endpoint.

Gateway service code assumes it is running as the gateway and does not use a
gateway role flag. Production installs still use the native CLI binary artifact;
source-mounted Docker and Incus development/E2E topologies point
`/usr/local/bin/orbit` directly at `<source>/apps/cli/orbit`.

## Error Contract

There is no gateway-host-specific error contract. Gateway hosts use the same
validation and gateway-unavailable failures as other operator hosts.

## Failure Semantics

| Failure | Condition | Outcome |
| --- | --- | --- |
| Gateway-host rejection | Not applicable. | Not implemented. |

## Test Mapping

Primary test owners:

| Path | Coverage |
| --- | --- |
| `apps/gateway/tests/Feature/Commands/Nodes/NodeDefaultCommandTest.php` | Gateway-host deployment context: no gateway-side default-node route, local-only config write guarantee. |
| `apps/cli/tests/Feature/Commands/Node/NodeDefaultOnGatewayHostTest.php` | Gateway-host execution edits local CLI config and does not call gateway-side default-node routes. |
