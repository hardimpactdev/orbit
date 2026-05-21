# `orbit firewall:deny [name]`

[Back to Firewall commands.](../README.md)

Deny traffic matching a firewall policy.

`firewall:deny` creates or updates an Orbit-owned firewall rule with action `deny`, stores the rule as gateway configuration, and applies it on the target node through the gateway.

## Usage

```bash
orbit firewall:deny [name] --port=<port> [--node=<node>] [--direction=<incoming|outgoing>] [--from=<cidr>] [--to=<cidr>] [--protocol=<tcp|udp>] [--reason=<text>] [--json]
```

## Examples

```bash
orbit firewall:deny block-vite --node=app-1 --port=5173 --from=0.0.0.0/0
orbit firewall:deny redis-public --node=app-1 --port=6379 --from=0.0.0.0/0 --reason="internal only"
orbit firewall:deny old-admin --node=gateway --port=8080 --json
```

## Arguments and options

- `name`: Rule name, unique on the target node. In interactive mode, Orbit prompts when omitted.
- `--node`: Target node. Defaults to local `node:default` when configured.
- `--json`: Output JSON.

**Policy fields:**

- `--direction`: Traffic direction. Defaults to `incoming`.
- `--from`: Source CIDR. Defaults to `any`.
- `--to`: Destination CIDR when supported.
- `--port`: Destination port or supported port range.
- `--protocol`: Protocol. Defaults to `tcp`.
- `--reason`: Operator note.

## What Happens

Run this command to create or update an Orbit-owned deny rule and apply it on the target node.

`firewall:deny`:

1. Resolves the target node and firewall-rule fields.
2. Verifies the target node is firewall eligible.
3. Rejects rules that conflict with node bootstrap policy.
4. Writes gateway firewall-rule configuration with action `deny`.
5. Applies the backend firewall rule through the gateway.
6. Reports the expected rule and apply outcome.

## Output

Use `--json` to get a machine-readable result; omit it for progress.

Human output shows progress for validation, gateway configuration, and backend apply.

Use `--json` for the machine-readable firewall rule result.

## Requirements

- The CLI caller can reach the Orbit gateway, or the command is running on the gateway.
- The current node identity is authorized to manage firewall policy for the selected node.
- The target node is a registered active Ubuntu node carrying at least one of the `gateway`, `app-development`, `app-production`, `database`, or `agent` roles.
- The rule does not attempt to edit node bootstrap policy.
- The gateway can reach the target node through Orbit's node execution primitive.

## Related Commands

Use these commands to list, remove, or verify firewall rules.

- [`firewall:list`](../1_firewall-list/firewall-list.md) - list expected rules
- [`firewall:remove`](../4_firewall-remove/firewall-remove.md) - remove an Orbit-owned rule
- [`doctor --family=firewall_rule`](../firewall-doctor.md) - verify firewall drift

## Technical Contract

See [`firewall-deny` technical contract](technical/1_firewall-deny.md).
