# Firewall Commands

Firewall commands manage network policy that Orbit owns on each node. The command family is `firewall:*`; the durable state family and doctor key is `firewall_rule`.

UFW is the current backend for supported Ubuntu nodes. It is not the product model.

## Domain Rules

These rules govern what the firewall command family owns and what it may not touch.

- The firewall command family owns the `firewall:*` command prefix.
- `firewall_rule` is a state family. The gateway is the source of truth for firewall rule configuration.
- Firewall rules target registered active Ubuntu managed nodes with role `gateway`, `app-development`, `app-production`, or `agent`.
- Clients, unsupported platforms, inactive nodes, and unmanaged roles are not firewall-rule targets.
- Rules are expressed in Orbit terms: name, node, direction, action, source, destination, protocol, port, and reason.
- Rule names are unique on the target node. Reapplying the same named rule with the same policy shape is idempotent; reusing the name for a different policy fails before mutation.
- Firewall commands resolve input locally, then the gateway writes configuration and applies host policy through the current firewall backend.
- Role bootstrap policy remains part of the node domain and is not edited through firewall commands.

The `operator` permission preset includes `firewall_rule:read`. That preset
can read firewall rules and surface firewall-doctor findings, but it cannot
create, update, or remove firewall rules. Firewall writes require an
`admin`-class preset or an explicit `firewall_rule:write` permission on the
grant.

**Read and adopt rules:**

- Bootstrap policy includes Orbit/WireGuard management access and public ingress decisions specific to each node role. Firewall commands do not create public SSH policy exceptions for nodes.
- Firewall reads use gateway configuration by default. Live firewall reality belongs to `doctor --family=firewall_rule`.
- Node reality import is not part of the firewall command surface. Adoption of observed firewall reality must use explicit `doctor --fix --family=firewall_rule --adopt` semantics.

## Firewall Rule JSON Entity

Firewall JSON renderers that return one rule entity embed this shape under `success.data.rule`, or directly under `success.data.rules[]` for list items.

```json
{
  "name": "local-vite",
  "node": "app-1",
  "direction": "incoming",
  "action": "allow",
  "source": "10.6.0.0/24",
  "destination": null,
  "port": 5173,
  "protocol": "tcp",
  "reason": "local development server",
  "status": "enacted"
}
```

| Field | Type | Meaning |
| --- | --- | --- |
| `name` | string | Rule identity, unique on the target node. |
| `node` | string | Node slug where the rule is expected. |
| `direction` | `incoming` or `outgoing` | Traffic direction. |
| `action` | `allow` or `deny` | Firewall policy action. |
| `source` | string | Source CIDR or `any`. |
| `destination` | string \| null | Destination CIDR when the backend supports it. |
| `port` | integer \| string | Destination port or documented port range. |
| `protocol` | `tcp` or `udp` | Traffic protocol. |
| `reason` | string \| null | Operator note. |
| `status` | string | Command-specific outcome, such as `expected`, `enacted`, `removed`, or `already_absent`. |

## Commands

The `firewall:*` family covers listing, creating, and removing Orbit-owned firewall rules.

1. [`orbit firewall:list`](1_firewall-list/firewall-list.md)
2. [`orbit firewall:allow [name]`](2_firewall-allow/firewall-allow.md)
3. [`orbit firewall:deny [name]`](3_firewall-deny/firewall-deny.md)
4. [`orbit firewall:remove <name>`](4_firewall-remove/firewall-remove.md)

## Related

- [`doctor --family=firewall_rule`](firewall-doctor.md)
- [`doctor --family=node`](../1_node/node-doctor.md)
- [`orbit node:new`](../1_node/1_node-new/node-new.md)
