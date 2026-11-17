---
title: "ADR 0226: Split private DNS on managed peers"
sidebarTitle: "0226 Split private DNS"
description: "Managed Linux peers route Orbit names to private DNS and keep public queries on their normal resolver."
---

# ADR 0226: Split private DNS on managed peers

Managed Linux peers send Orbit names to Orbit VPN DNS. All other names use their normal provider or DHCP resolver.

## Status

In progress.

Principle: [One way, one name](/mission#principles). One rendered private-name policy serves provisioning, repair, reconciliation, and Doctor. No principle exception.

The split policy is built. Provisioning and repair apply it; explicit `orbit:peer-dns-reconcile` admits changed policies. There is no scheduled pass or propagation deadline. [Private DNS](/reference/private-dns#resolver-selection) owns the delivered behavior; the DNS replacement in ADR 0248 remains unfinished.

## Context

The former `~.` default makes public resolution depend on VPN DNS. app-prod also retains a DNS override of `10.0.0.2`, where no DNS service listens since 2026-09-13. Its tunnel and HTTPS work, but it cannot resolve `ai-cli-proxy.test`. Doctor has no peer resolver check.

## Decision

The Gateway renders the private VPN domain, every Cluster TLD, every Node TLD, and private Route names outside those suffixes. Names are canonical, unique, and sorted. A suffix already covered by another suffix needs no second entry. systemd-resolved routes an outside-TLD exact Route name and its descendants to private DNS; only the exact Route has a private answer. Descendants retain VPN DNS forwarding behavior. Resolver routing cannot express exact-name-only selection.

### Routing scope

The managed link uses route-only domains and never `~.`. Existing uplink resolver settings stay in place. Apply sets the live default-route flag to false. The PostUp hook sets DNS servers and route-only domains. After a tunnel restart, systemd 259 infers `no` from those non-root route-only domains. Both yield the required effective `DefaultRoute=no`.

### Propagation and limits

Provision, footprint convergence, and repair apply the policy. Run `orbit:peer-dns-reconcile` explicitly after namespace changes. It admits eligible peers whose policy differs from their last successful apply and skips busy peers. Run it again after failures or skipped admissions. It does not inspect live state for unchanged policies; use peer repair for host drift. Record mutations do not add synchronous fleet SSH work or block publication. Doctor reports remaining drift.

Writes of private Route domains, Cluster TLDs, and Node TLDs validate names and the resulting namespace in a serialized database transaction. They refuse growth above 512 domains without saving the change. Nonincreasing changes, including teardown and rollback, remain allowed when stored intent already exceeds the cap. The renderer also refuses more than 512 domains before peer mutation, reserving half the 1,024-domain per-link and per-delegate bound in [systemd 259](https://github.com/systemd/systemd/blob/v259/src/resolve/resolved-link.h). This leaves room for a complete replacement while resolved still holds the old domains. It never truncates or widens to `~.`. Invalid stored names also refuse rendering. Namespace growth beyond that bound needs a separate design.

### Overrides and Cluster DNS

`--dns-server` now selects a server for the same complete private-name set; it does not select a reduced policy. The server must answer Orbit names. Data migrations clear all existing per-Node overrides and any global VPN DNS server outside the usable WireGuard subnet to adopt the default server. It does no SSH work. When repair moves an underlay selection to WireGuard, a networkd-managed uplink is required. Repair adds the missing managed hook, reverts the uplink’s transient DNS selection, preserves unrelated resolver protocol settings, and asks networkd to restore provider or DHCP DNS. Reconfiguration alone retains networkd’s transient DNS overrides on systemd 259. A durable uplink snapshot supports rollback after interruption; success leaves the uplink default-route decision with networkd. An unusable restored uplink or a failed apply restores the old resolver state, including the orbit default-route flag. New explicit overrides and the global VPN DNS server must be usable addresses in the WireGuard subnet, so they cannot replace the uplink resolver. Every managed split apply requires at least one usable non-`orbit` per-link or global DNS server before switching; otherwise it restores previous state and exits 47 (`vpn.peer_dns_uplink_missing`). Doctor checks private and public resolution. WireGuard endpoint overrides are independent and remain unchanged. Peers without roles, macOS, the VPN holder, and the Gateway's separate bootstrap resolver keep their existing ownership boundaries.

[ADR 0248](/decisions/0248-render-private-dns-as-desired-state) replaces the former Cluster DNS delegate design. It keeps this private routing-domain set but removes the LAN-first delegate, member step and fallback. That DNS cutover remains pending.

Peer programs use Bash and base tools, with no peer-side PHP dependency. Native repair explicitly refuses unmanaged targets, including Gateway and VPN holders.

Peer inspection reads persisted and live settings under the peer lock in shared mode. Doctor additionally checks the expected `gateway.orbit` answer and an uncached public lookup through a non-Orbit link or global DNS. It reports `node.private_dns_policy_mismatch`, `node.private_dns_unreachable`, or `node.public_dns_unreachable` as drift. A failed SSH inspection is `node.inspection_failed` and unverifiable. Namespace changes remain unapplied until admission and execution succeed. Doctor never repairs.

## Rejected alternatives

- `~.` default: a VPN DNS outage breaks public lookups and public sites depend on the VPN Node.
- VPN-first, public-second fallback chain: resolved stays on the second server after failover, leaving private names broken. It also sends private queries to public resolvers.
- Static hosts: records and Router placement change. Host files cannot model TLDs or provide one centrally rendered resolver policy.

## Consequences

Public lookups survive a VPN DNS outage when the normal resolver and network remain healthy. Private lookups still need private DNS. The Gateway must propagate namespace changes, and peers can have temporary drift until reconciliation succeeds. An outside-TLD routing domain also captures descendants, but does not create private wildcard answers.

## Affects

- Components: apps/gateway, apps/docs
- ADRs: [0248](/decisions/0248-render-private-dns-as-desired-state)
- Detail: [Private DNS](/reference/private-dns#resolver-selection), [Node provisioning](/reference/node-provisioning), [Doctor](/cli/doctor)
- Verify: Gateway affected tests and checks, documentation checks, disposable peer command harnesses. Incus acceptance on systemd 259 is a hard gate before merge. It must verify root-to-split migration, no-uplink refusal, global and per-link public DNS, private answers, restart persistence, namespace propagation, and public resolution during private DNS outage. Passing unit harnesses does not waive this gate.
