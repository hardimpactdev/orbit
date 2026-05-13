# Firewall Concepts

This document defines firewall-family vocabulary and invariants. It supports the firewall command contracts and the [firewall doctor](firewall-doctor.md); it does not override the [Architecture](../../ARCHITECTURE.md).

## Identity

- **Firewall rule:** Gateway-owned record of one network policy entry on one node, expressed in Orbit terms rather than backend syntax.
- **Rule name:** Identity of the rule on the target node. Unique per node. Reapplying the same name with the same policy shape is idempotent; reusing the name for a different policy fails before mutation.

## Rule Shape

- **Direction:** Traffic direction. One of `incoming` or `outgoing`.
- **Action:** Firewall policy action. One of `allow` or `deny`.
- **Source:** Source CIDR or `any`.
- **Destination:** Destination CIDR when the backend supports it, otherwise `null`.
- **Port:** Destination port or documented port range.
- **Protocol:** Traffic protocol. One of `tcp` or `udp`.
- **Reason:** Optional operator note attached to the rule.

## Targets

- **Eligible firewall target:** Registered active Ubuntu managed node with role `gateway` or `app`. Control nodes, unsupported platforms, inactive nodes, and unmanaged roles are not firewall-rule targets.

## Policy Boundaries

- **Bootstrap policy:** Role-baseline firewall policy applied during node provisioning, including Orbit/WireGuard management access and role-specific public ingress decisions. Owned by the node domain.
- **Firewall-family boundaries:** Firewall commands own editable rule configuration on eligible nodes. They do not edit bootstrap policy, do not create public SSH exceptions for app nodes, and do not import observed node reality outside explicit `doctor --fix --family=firewall_rule --adopt` semantics.
