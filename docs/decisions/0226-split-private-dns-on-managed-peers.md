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

Nick approved the policy on 2026-10-19. This record stays in this feature PR at his explicit request. Merge starts the fleet rollout: automatic release applies the data migrations, footprint convergence applies the peer policy, and the scheduled pass repairs remaining peers every five minutes. No real-node command is part of the implementation or acceptance work.

## Context

The former `~.` default makes public resolution depend on VPN DNS. app-prod also retains a DNS override of `10.0.0.2`, where no DNS service listens since 2026-09-13. Its tunnel and HTTPS work, but it cannot resolve `ai-cli-proxy.test`. Doctor has no peer resolver check.

## Decision

The Gateway renders the private VPN domain, every Cluster TLD, every Node TLD, and private Route names outside those suffixes. Names are canonical, unique, and sorted. A suffix already covered by another suffix needs no second entry. systemd-resolved routes an outside-TLD exact Route name and its descendants to private DNS; only the exact Route has a private answer. Descendants retain VPN DNS forwarding behavior. Resolver routing cannot express exact-name-only selection.

### Routing scope

The managed link uses route-only domains and never `~.`. Existing uplink resolver settings stay in place. Apply sets the live default-route flag to false. The PostUp hook sets DNS servers and route-only domains. After a tunnel restart, systemd 259 infers `no` from those non-root route-only domains. Both yield the required effective `DefaultRoute=no`.

### Propagation and limits

Provision, footprint convergence, and repair apply the policy immediately. A Gateway scheduled pass every five minutes compares persisted and live policy on active managed Linux peers with roles, excluding the VPN holder and the Gateway. It repairs only mismatches. Failed peers do not stop other peers; the command reports failure and retries on the next pass. The delay is five minutes plus queue and fleet traversal time, with no availability promise for an unreachable peer. Record mutations do not add synchronous fleet SSH work or block publication. Doctor reports remaining drift.

Writes of private Route domains, Cluster TLDs, and Node TLDs validate names and the resulting namespace in a serialized database transaction. They refuse more than 512 domains without saving the change. The renderer also refuses more than 512 domains before peer mutation, reserving half the 1,024-domain per-link and per-delegate bound in [systemd 259](https://github.com/systemd/systemd/blob/v259/src/resolve/resolved-link.h). This leaves room for a complete replacement while resolved still holds the old domains. It never truncates or widens to `~.`. Invalid stored names also refuse rendering. Namespace growth beyond that bound needs a separate design.

### Overrides and Cluster DNS

`--dns-server` now selects a server for the same complete private-name set; it does not select a reduced policy. The server must answer Orbit names. Data migrations clear all existing per-Node overrides and any global VPN DNS server outside the usable WireGuard subnet to adopt the default server. It does no SSH work. When repair moves an underlay selection to WireGuard, a networkd-managed uplink is required. Repair adds the missing managed hook, reverts the uplink’s transient DNS selection, preserves unrelated resolver protocol settings, and asks networkd to restore provider or DHCP DNS. Reconfiguration alone retains networkd’s transient DNS overrides on systemd 259. A durable uplink snapshot supports rollback after interruption; success leaves the uplink default-route decision with networkd. An unusable restored uplink or a failed apply restores the old resolver state, including the orbit default-route flag. New explicit overrides and the global VPN DNS server must be usable addresses in the WireGuard subnet, so they cannot replace the uplink resolver. Every managed split apply requires at least one usable non-`orbit` per-link or global DNS server before switching; otherwise it restores previous state and exits 43. Doctor checks private and public resolution. WireGuard endpoint overrides are independent and remain unchanged. Peers without roles, macOS, the VPN holder, and the Gateway's separate bootstrap resolver keep their existing ownership boundaries.

[ADR 0219](/decisions/0219-answer-cluster-names-from-a-dns-on-the-router) retains its planned LAN-first delegate and automatic return after recovery. Its delegate must use this private routing-domain set instead of `~.`. It replaces the peer link's private scope while selected, so equally specific scopes cannot race. Both delegate servers answer Orbit names; public queries stay on the uplink. Its member policy must reconcile when this set changes, including outside-TLD Route changes. The delegate is not built by this PR.

Reconciliation inspects all persisted and live settings in one read-only SSH script under the peer lock in shared mode. It sends no DNS queries. Doctor additionally checks the expected `gateway.orbit` answer and an uncached public lookup through a non-Orbit link or global DNS. It reports `node.private_dns_policy_mismatch`, `node.private_dns_unreachable`, or `node.public_dns_unreachable` as drift. A failed SSH inspection is `node.inspection_failed` and unverifiable. Namespace changes can report temporary mismatch for up to five minutes plus queue and traversal time. Doctor never repairs.

## Rejected alternatives

- `~.` default: a VPN DNS outage breaks public lookups and public sites depend on the VPN Node.
- VPN-first, public-second fallback chain: resolved stays on the second server after failover, leaving private names broken. It also sends private queries to public resolvers.
- Static hosts: records and Router placement change. Host files cannot model TLDs or provide one centrally rendered resolver policy.

## Consequences

Public lookups survive a VPN DNS outage when the normal resolver and network remain healthy. Private lookups still need private DNS. The Gateway must propagate namespace changes, and peers can have temporary drift until reconciliation succeeds. An outside-TLD routing domain also captures descendants, but does not create private wildcard answers.

## Affects

- Components: apps/gateway, apps/docs
- ADRs: [0219](/decisions/0219-answer-cluster-names-from-a-dns-on-the-router)
- Detail: [Private DNS](/reference/private-dns#resolver-selection), [Node provisioning](/reference/node-provisioning), [Doctor](/cli/doctor)
- Verify: Gateway affected tests and checks, documentation checks, disposable peer command harnesses. Incus acceptance on systemd 259 is a hard gate before merge. It must verify root-to-split migration, no-uplink refusal, global and per-link public DNS, private answers, restart persistence, namespace propagation, and public resolution during private DNS outage. Passing unit harnesses does not waive this gate.
