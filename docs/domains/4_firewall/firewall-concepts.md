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
- **Reason:** Optional operator note attached to the rule.

## Targets

This term defines which nodes firewall commands may target.

- **Eligible firewall target:** Registered active Ubuntu managed node with role `gateway`, `app-development`, `app-production`, `database`, or `agent`. Clients, unsupported platforms, inactive nodes, and unmanaged roles are not firewall-rule targets.
- **Database-only ingress:** A node carrying only `database` has an empty public ingress baseline. Operator-managed firewall rules may still be configured on it, e.g. to allow inbound TCP from specific app-role nodes.

## Policy Boundaries

These terms define what firewall commands may and may not change.

- **Bootstrap policy:** Role-baseline firewall policy applied during node provisioning, including Orbit/WireGuard management access and public ingress decisions specific to each node role. Owned by the node domain.
- **Operator preset firewall boundary:** Authorization rule that the `operator` permission preset includes `firewall_rule:read` (firewall list/show plus `doctor --family=firewall_rule` findings) but excludes every `firewall_rule:write` permission. Firewall writes require an `admin`-class preset or an explicit `firewall_rule:write` permission on the grant.
- **Firewall-family boundaries:** Firewall commands own editable rule configuration on eligible nodes.
  - They do not edit bootstrap policy.
  - They do not create public SSH exceptions for nodes.
  - They do not import observed node reality outside explicit `doctor --fix --family=firewall_rule --adopt` semantics.
