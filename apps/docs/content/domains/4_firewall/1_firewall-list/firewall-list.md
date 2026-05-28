# `orbit firewall:list`

[Back to Firewall commands.](../README.md)

List expected firewall rules from gateway configuration.

`firewall:list` displays active Orbit firewall policy recorded on the gateway. It does not inspect live node firewall state.

## Usage

```bash
orbit firewall:list [--node=<node>] [--json]
```

## Examples

```bash
orbit firewall:list
orbit firewall:list --node=app-1
orbit firewall:list --json
```

## Arguments and options

- `--node`: Optional target node filter.
- `--json`: Output JSON.

## What Happens

Run this command to read Orbit firewall policy recorded on the gateway for the selected node scope.

`firewall:list`:

1. Resolves the caller's gateway connection and visibility.
2. Applies the optional node filter at the gateway.
3. Reads visible gateway firewall-rule rows.
4. Groups rules by node for human output.

The command does not probe node firewall reality and does not mutate configuration.

## Output

Use `--json` to get machine-readable rule entities; omit it for a table grouped by node.

Human output is a table grouped by node.

Use `--json` for machine-readable firewall rules and the applied node filter.

## Requirements

- The CLI caller can reach the Orbit gateway, or the command is running on the gateway.
- The current node identity is authorized to inspect firewall policy for the selected node scope.
- Filtered target nodes must be registered active Ubuntu nodes carrying at least one of the `gateway`, `app-dev`, `app-prod`, `database`, or `agent` roles.

## Related Commands

Use these commands to add, remove, or verify firewall rules.

- [`firewall:allow`](../2_firewall-allow/firewall-allow.md) - add an allow rule
- [`firewall:deny`](../3_firewall-deny/firewall-deny.md) - add a deny rule
- [`doctor --family=firewall_rule`](../firewall-doctor.md) - verify firewall drift

## Technical Contract

See [`firewall-list` technical contract](technical/1_firewall-list.md).
