# `orbit firewall:remove <name>`

[Back to Firewall commands.](../README.md)

Remove an Orbit-owned firewall rule.

`firewall:remove` is destructive. It removes gateway firewall-rule configuration and removes the managed backend rule from the target node through the gateway.

## Usage

```bash
orbit firewall:remove <name> [--node=<node>] [--force] [--json]
```

## Examples

```bash
orbit firewall:remove local-vite --node=app-1
orbit firewall:remove local-vite --node=app-1 --force
orbit firewall:remove local-vite --node=app-1 --force --json
```

## Arguments and options

- `name`: Firewall rule name.
- `--node`: Target node. Defaults to local `node:default` when configured.
- `--force`: Confirm destructive removal in non-interactive mode or skip the interactive confirmation prompt.
- `--json`: Output JSON.

Target context is required when neither `--node` nor local `node:default` resolves a node.

## What Happens

Run this command to remove a gateway firewall rule owned by Orbit and apply the cleanup on the target node.

`firewall:remove`:

1. Resolves the target node and firewall rule.
2. Verifies the target node is firewall eligible.
3. Requires destructive confirmation.
4. Removes the managed backend rule through the gateway.
5. Removes gateway firewall-rule configuration when cleanup succeeds.
6. Reports idempotent absence when the rule is already absent from configuration.

The command does not remove node bootstrap policy.

## Output

Use `--json` to get a machine-readable result; omit it for progress.

Human output shows progress for confirmation, backend cleanup, and gateway configuration removal.

Use `--json` for the machine-readable removed or already-absent rule result.

## Requirements

- The CLI caller can reach the Orbit gateway, or the command is running on the gateway.
- The current node identity is authorized to manage firewall policy for the selected node.
- The target node is a registered active Ubuntu `gateway` or `app` node.
- The gateway can reach the target node through Orbit's node execution primitive.
- This requirement is waived when the rule is already absent from gateway configuration.

## Related Commands

Use these commands to list, add, or verify firewall rules.

- [`firewall:list`](../1_firewall-list/firewall-list.md) - list expected rules
- [`firewall:allow`](../2_firewall-allow/firewall-allow.md) - add an allow rule
- [`firewall:deny`](../3_firewall-deny/firewall-deny.md) - add a deny rule
- [`doctor --family=firewall_rule`](../firewall-doctor.md) - report leftover backend rules

## Technical Contract

See [`firewall-remove` technical contract](technical/1_firewall-remove.md).
