# Firewall Commands

UFW rule intent on each node. Spec: [`apps/docs/content/domains/4_firewall/`](../../../apps/docs/content/domains/4_firewall/).

Bootstrap baselines are created by node provisioning. Only nodes carrying
`ingress` should expose public HTTP/HTTPS; SSH stays reachable over WireGuard
only. App-role backend nodes without `ingress` should not expose public app
traffic. Use `firewall:*` to add, override, or audit policy on top of that
baseline.

## `orbit firewall:allow [name]`

Create or update an allow rule.

```bash
orbit firewall:allow [<name>] [--node=<name>] [--port=<spec>]
                     [--direction=incoming|outgoing] [--from=<cidr|any>]
                     [--to=<cidr>] [--protocol=tcp|udp] [--reason='<text>']
                     [--json]
```

| Option | Default | Notes |
|---|---|---|
| `name` |  -  | Rule slug. |
| `--node` |  -  | Target node. |
| `--port` |  -  | Destination port or range (e.g. `22`, `8000:8100`). |
| `--direction` | `incoming` | `incoming` or `outgoing`. |
| `--from` |  -  | Source CIDR or `any`. |
| `--to` |  -  | Destination CIDR. |
| `--protocol` | `tcp` | `tcp` or `udp`. |
| `--reason` |  -  | Operator note saved with the rule. |

Examples:

```bash
orbit firewall:allow office-vpn --node=prod-1 --port=22 --from=203.0.113.0/24 \
  --reason='operator office VPN bastion'

orbit firewall:allow staging-pgsql --node=staging-1 --port=5432 --protocol=tcp \
  --from=10.6.0.0/24 --reason='staging DB access from Orbit network'
```

## `orbit firewall:deny [name]`

Same shape as `allow` but creates a deny rule.

```bash
orbit firewall:deny [<name>] [--node=<name>] [--port=<spec>]
                    [--direction=incoming|outgoing] [--from=<cidr|any>]
                    [--to=<cidr>] [--protocol=tcp|udp] [--reason='<text>']
                    [--json]
```

## `orbit firewall:list`

```bash
orbit firewall:list [--node=<name>] [--json]
```

## `orbit firewall:remove [name]`

```bash
orbit firewall:remove [<name>] [--node=<name>] [--force] [--json]
```
