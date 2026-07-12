# Client Context: `orbit node:manage`

[Back to `node:manage` technical contract.](1_node-manage.md)

`node:manage` is a client-context command. It runs on the operator machine
represented by the current gateway identity.

The CLI asks the gateway to preflight roleless eligibility and the exact
`transitional-ssh-fallback` marker before the local authorized-key write. The
gateway then pins and verifies the transitional SSH path by
`node.wireguard_address` and sets `managed=true` after verification succeeds.

Running this command on a gateway host is not a special management shortcut.
Gateway nodes are role-bearing nodes and are rejected by the roleless operator
eligibility rule.

## Allowed Paths

| Context | Behavior |
| --- | --- |
| Configured CLI authenticated as an active roleless operator node with the exact transitional marker | Resolve the local user, pass gateway preflight, install the gateway key locally, send management metadata, and opt into managed Agent intent. |
| Configured CLI without the exact transitional marker | Gateway preflight rejects before the local authorized-key write. |
| Configured CLI authenticated as an inactive, gateway, or role-bearing node | Gateway rejects before management metadata, host-key pinning, or SSH verification. |
| No configured gateway | CLI fails before prompts and local key writes. |

## Test Mapping

| Path | Coverage |
| --- | --- |
| `apps/cli/tests/Feature/Commands/Node/NodeManageCommandTest.php` | Client request order, no-gateway rejection, local key write timing, and gateway pass-through failures. |
| `apps/gateway/tests/Feature/Http/Api/NodeManageControllerTest.php` | Gateway rejection for inactive, gateway, and role-bearing callers. |
