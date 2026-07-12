# Client Context: `orbit node:manage`

[Back to `node:manage` technical contract.](1_node-manage.md)

`node:manage` is a client-context command. It runs on the operator machine
represented by the current gateway identity.

The CLI resolves the local Agent user and platform, then asks the gateway to
verify roleless eligibility and Agent-push reachability by
`node.wireguard_address`. The gateway retains `managed=true` only after the
probe succeeds.

Running this command on a gateway host is not a special management shortcut.
Gateway nodes are role-bearing nodes and are rejected by the roleless operator
eligibility rule.

## Allowed Paths

| Context | Behavior |
| --- | --- |
| Configured CLI authenticated as an active roleless operator node | Resolve the local user, send management metadata, verify Agent push, and opt into managed Agent intent. |
| Configured CLI authenticated as an inactive, gateway, or role-bearing node | Gateway rejects before management metadata or Agent verification. |
| No configured gateway | CLI fails before prompts or gateway writes. |

## Test Mapping

| Path | Coverage |
| --- | --- |
| `apps/cli/tests/Feature/Commands/Node/NodeManageCommandTest.php` | Client request order, no-gateway rejection, and gateway pass-through failures. |
| `apps/gateway/tests/Feature/Http/Api/NodeManageControllerTest.php` | Gateway rejection for inactive, gateway, and role-bearing callers. |
