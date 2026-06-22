# Client Context: `orbit node:manage`

[Back to `node:manage` technical contract.](1_node-manage.md)

`node:manage` is a client-context command. It runs on the operator machine
represented by the current gateway identity.

The CLI performs the local authorized-key write before it asks the gateway to
pin and verify SSH. The gateway then connects back to the same node by
`node.wireguard_address`.

Running this command on a gateway host is not a special management shortcut.
Gateway nodes are role-bearing nodes and are rejected by the roleless operator
eligibility rule.

## Allowed Paths

| Context | Behavior |
| --- | --- |
| Configured CLI authenticated as an active roleless operator node | Resolve the local user, install the gateway key locally, then send management metadata to the gateway. |
| Configured CLI authenticated as an inactive, gateway, or role-bearing node | Gateway rejects before management metadata, host-key pinning, or SSH verification. |
| No configured gateway | CLI fails before prompts and local key writes. |

## Test Mapping

| Path | Coverage |
| --- | --- |
| `apps/cli/tests/Feature/Commands/Node/NodeManageCommandTest.php` | Client request order, no-gateway rejection, local key write timing, and gateway pass-through failures. |
| `apps/gateway/tests/Feature/Http/Api/NodeManageControllerTest.php` | Gateway rejection for inactive, gateway, and role-bearing callers. |
