# `orbit firewall:deny [name]`

[Back to Firewall commands.](../README.md)

Deny traffic matching a firewall policy.

`firewall:deny` creates or updates an Orbit-owned firewall rule with action
`deny`, stores the rule as gateway intent, and enacts it on the target node
through the gateway.

## Usage

```bash
orbit firewall:deny --port=<port> [name] [--node=<node>] [--direction=<incoming|outgoing>] [--from=<cidr>] [--to=<cidr>] [--protocol=<tcp|udp>] [--reason=<text>] [--json]
```

## Examples

```bash
orbit firewall:deny block-vite --node=app-1 --port=5173 --from=0.0.0.0/0
orbit firewall:deny redis-public --node=app-1 --port=6379 --from=0.0.0.0/0 --reason="internal only"
orbit firewall:deny old-admin --node=gateway --port=8080 --json
```

## Arguments And Options

- `name`: Rule name, unique on the target node. In interactive mode, Orbit
  prompts when omitted.
- `--node`: Target node. Defaults to local `node:default` when configured.
- `--direction`: Traffic direction. Defaults to `incoming`.
- `--from`: Source CIDR. Defaults to `any`.
- `--to`: Destination CIDR when supported.
- `--port`: Destination port or supported port range.
- `--protocol`: Protocol. Defaults to `tcp`.
- `--reason`: Operator note.
- `--json`: Output JSON.

## What Happens

`firewall:deny`:

1. Resolves the target node and firewall-rule fields.
2. Verifies the target node is firewall eligible.
3. Rejects rules that conflict with node bootstrap policy.
4. Writes gateway firewall-rule intent with action `deny`.
5. Enacts the backend firewall rule through the gateway.
6. Reports the expected rule and enactment outcome.

## Output

Human output is a progress tree for validation, gateway intent, and backend
enactment.

JSON output returns the rule entity under `success.data.rule`.

## Requirements

- The CLI caller can reach the Orbit gateway, or the command is running on the
  gateway.
- The current node identity is authorized to manage firewall policy for the
  selected node.
- The target node is a registered active Ubuntu `gateway` or `app` node.
- The rule does not attempt to edit node bootstrap policy.
- The gateway can reach the target node through Orbit's node execution
  primitive.

## Related Commands

- [`firewall:list`](../1_firewall-list/firewall-list.md) - list expected rules
- [`firewall:remove`](../4_firewall-remove/firewall-remove.md) - remove an Orbit-owned rule
- [`doctor --family=firewall_rule`](../firewall-doctor.md) - verify firewall drift

## Technical Contract

See [`firewall-deny` technical contract](technical/1_firewall-deny.md).
