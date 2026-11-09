---
title: "ADR 0251: Reach the WireGuard hub over the recorded LAN"
sidebarTitle: "0251 Hub over the LAN"
description: "In progress. A Node on the hub's private network reaches the hub at the hub's LAN address. The recorded lan_ip is the signal and replaces the per-Node endpoint override. The hub sends from the address peers use."
---

# ADR 0251: Reach the WireGuard hub over the recorded LAN

A Node on the hub's private network reaches the WireGuard hub at the hub's LAN address. The recorded `lan_ip` of both Nodes is the signal. The per-Node WireGuard endpoint override goes away. The hub sends every WireGuard packet from the address its peers use.

## Status

In progress.

Decided by Nick on 2026-11-09, after the WireGuard incident of that day.

Principle: [One way, one name](/mission#principles).

## Context

On 2026-11-09 from 15:38 to 15:50 UTC the Gateway lost WireGuard. The hub has two public IPv4 addresses on one interface: a floating address that every peer configuration names, and the DHCP primary address, which is the source of its default route. When the hub starts a handshake, Linux picks the primary address as source. A peer that receives an authenticated packet from a new address roams to it. When inbound traffic to the primary address stopped, every peer that had roamed lost the hub. The tunnel came back when the Gateway reached the hub over the provider's private network instead.

The Nodes in the hub's data centre share a private network with it. Only `app-prod` used it, through a hand-set `wireguard_endpoint_override`. The other Nodes there already record their `lan_ip`. Home Nodes record a `lan_ip` on another private network and must keep the public endpoint.

## Decision

- **The signal.** A Node reaches the hub at `<hub lan_ip>:<WireGuard port>` when the hub and the Node both record an IPv4 `lan_ip` and both addresses are in the same IPv4 /24. This is the recorded-LAN rule that [public Process Routes](/cli/route/create) already use; one helper owns it. Otherwise the Node uses the public endpoint: the stored Gateway endpoint, or the hub's public SSH host with the WireGuard port.
- **The proof.** The record is a claim; the Node proves it before Orbit writes a WireGuard file that uses it. On the Node, the kernel must route the hub's LAN address directly, without a gateway, from the Node's recorded `lan_ip`, on an interface other than the tunnel. The hub's LAN address must then answer ICMP from that address. A failed proof stops the run with `node.hub_lan_unreachable` before any WireGuard change. There is no fallback to the public endpoint: the operator fixes the network or the record.
- **The override goes.** `wireguard_endpoint_override` is dropped from the database, the API, the CLI, the SDK and the docs. `app-prod` gets the same endpoint from the rule.
- **Setting `lan_ip`.** `node:update --lan-ip` sets or clears the address on an existing Node as a record. Set the hub's address first, then each Node's, then converge each Node.
- **The hub's source address.** The hub marks its WireGuard packets and routes them through its own table. That table sends to the private network as the main table does and sends everything else from the hub's public endpoint address. A hub-started handshake to a public peer therefore always leaves from the address the peer uses. Orbit pins the source only when the public endpoint is an IPv4 address.
- **Doctor.** A Doctor check for a Node whose live hub endpoint differs from the rendered one follows in a later change. The agent cannot read WireGuard state today.

## Rejected alternatives

- **Keep the override as an escape hatch.** Two ways to say one thing; the incident showed that hand-set endpoints drift from the records.
- **Use Cluster membership as the signal.** Cluster membership says where Routes are served, not which network a Node shares with the hub. The `services` Node shares the hub's private network and belongs to no Cluster.
- **Fall back to the public endpoint when the LAN proof fails.** It would hide a wrong record and leave the Node on the path the operator meant to leave.
- **Rewrite the source with SNAT.** Connection tracking can change the source port when a translated flow meets an existing one, and the peer then roams to that port.
- **Change the source of the hub's default route.** It moves all of the hub's outgoing traffic, not only WireGuard, and DHCP renewals rewrite it.

## Consequences

- A converge of a Node on the hub's private network moves its tunnel to the LAN. Its traffic to the hub stays inside the data centre.
- The hub keeps answering public peers from the floating address after any route change.
- A Node that records a `lan_ip` in the hub's /24 on a different network fails its converge with `node.hub_lan_unreachable`, or with a missing handshake when a foreign machine answers at the hub's LAN address. Record a `lan_ip` only for the network the Node is really on.
