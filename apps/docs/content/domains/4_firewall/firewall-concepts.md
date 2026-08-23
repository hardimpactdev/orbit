# Firewall Concepts

This document defines firewall-family vocabulary and invariants. It supports the firewall command contracts and the [firewall doctor](firewall-doctor.md); it does not override the [Architecture](../../architecture.md).

## Identity

These terms define the core vocabulary used across firewall command contracts and the firewall doctor.

- **Firewall rule:** Gateway-owned record of one network policy entry on one node, expressed in Orbit terms rather than backend syntax.
- **Rule name:** Identity of the rule on the target node. Unique per node. Reapplying the same name with the same policy shape is idempotent; reusing the name for a different policy fails before mutation.

## Rule Shape

Each firewall rule is defined by the following fields.

- **Direction:** Traffic direction. One of `incoming` or `outgoing`.
- **Action:** Firewall policy action. One of `allow` or `deny`.
- **Source:** Source CIDR or `any`.
- **Destination:** Destination CIDR when the backend supports it, otherwise `null`.
- **Port:** Destination port or documented port range.
- **Protocol:** Traffic protocol. One of `tcp` or `udp`.
- **Address family:** `v4`, `v6`, or `both`. Existing rows default to `both`.
  A concrete host or CIDR narrows `both` to its actual IP family. An
  unrestricted `both` rule owns one IPv4 and one IPv6 backend entry.
- **Interface scope:** Optional symbolic interface scope. `public` and
  `wireguard` are resolved to live interfaces by the apply path.
- **Owner:** Rule owner. `user` means editable through firewall commands;
  `node-bootstrap` and `node-security` are managed by node-owned baseline
  flows.
- **Protected:** Boolean derived from owner. User-owned rules are not
  protected; non-user owners are protected.
- **Reason:** Optional operator note attached to the rule.

## Targets

This term defines which nodes firewall commands may target.

- **Eligible firewall target:** Registered active Ubuntu managed node with at
  least one active role assignment. Ubuntu means exact `ubuntu` or a literal
  `ubuntu_` platform prefix; hyphenated values such as `ubuntu-24-04` are
  ineligible. Clients, unsupported platforms, inactive nodes, and role-less
  identities are not firewall-rule targets.
- **Database-only ingress:** A node carrying only `database` has an empty ingress baseline. Operator-managed firewall rules may still be configured on it, e.g. to allow inbound TCP from specific app-role nodes.

## Policy Boundaries

These terms define what firewall commands may and may not change.

- **Bootstrap policy:** Role-baseline firewall policy applied during node
  provisioning, including Orbit/WireGuard management access and ingress
  decisions defined by the node role. Only nodes with active `ingress` expose
  public production HTTP/HTTPS. HTTPS listener policy includes TCP/443 and
  UDP/443 for HTTP/3/QUIC-capable clients. `app-prod` backend port `80` is
  private backend traffic and must be reachable only through the
  Orbit/WireGuard network. App and workspace runtime containers are
  Docker-network backends behind `orbit-caddy`; firewall policy targets node
  listeners rather than individual FrankenPHP containers. Owned by the node
  domain.
- **Operator preset firewall boundary:** Authorization rule that the `operator` permission preset includes `firewall_rule:read` (firewall list/show plus `doctor --family=firewall_rule` findings) but excludes every `firewall_rule:write` permission. Firewall writes require an `admin`-class preset or an explicit `firewall_rule:write` permission on the grant.
- **Firewall-family boundaries:** Firewall commands own editable rule configuration on eligible nodes.
  - They do not edit bootstrap policy.
  - They do not create public SSH exceptions for nodes.
  - They do not mutate protected rows with `owner != 'user'`; those are
    repaired through the owning family doctor path.
  - They do not import observed node reality outside explicit `doctor --family=firewall_rule --adopt` semantics.
