---
title: "ADR 0251: Reach the WireGuard hub over the recorded LAN"
sidebarTitle: "0251 Hub over the LAN"
description: "In progress. A Node on the hub's private network reaches the hub at the hub's LAN address. The recorded lan_ip is the signal and replaces the per-Node endpoint override. The hub sends from the address peers use."
---

# ADR 0251: Reach the WireGuard hub over the recorded LAN

A Node on the hub's private network reaches the WireGuard hub at the hub's LAN address. The recorded `lan_ip` of both Nodes is the signal. Each Node's WireGuard endpoint override goes away. The hub sends every WireGuard packet from the address its peers use.

## Status

In progress.

Decided by Nick on 2026-11-09, after the WireGuard incident of that day.

Principle: [One way, one name](/mission#principles).

## Context

On 2026-11-09 from 15:38 to 15:50 UTC the Gateway lost WireGuard. The hub has two public IPv4 addresses on one interface: a floating address that every peer configuration names, and the DHCP primary address, which is the source of its default route. When the hub starts a handshake, Linux picks the primary address as source. A peer that receives an authenticated packet from a new address roams to it. When inbound traffic to the primary address stopped, every peer that had roamed lost the hub. The tunnel came back when the Gateway reached the hub over the provider's private network instead.

The Nodes in the hub's data centre share a private network with it. Only `app-prod` used it, through a hand-set `wireguard_endpoint_override`. The other Nodes there already record their `lan_ip`. Home Nodes record a `lan_ip` on another private network and must keep the public endpoint.

## Decision

- **The signal.** A Node reaches the hub at `<hub lan_ip>:<WireGuard port>` when the hub and the Node both record an IPv4 `lan_ip`, both addresses are in the same IPv4 /24, and that /24 is RFC 1918. The /24 part is the recorded-LAN rule that [public Process Routes](/cli/route/create) already use; one helper owns it. Otherwise the Node uses the public endpoint: the stored Gateway endpoint, or the hub's public SSH host with the WireGuard port.
- **The proof.** The record is a claim; the Node proves it before Orbit writes a WireGuard file that uses it. On the Node, the kernel must route the hub's LAN address directly, without a gateway, from the Node's recorded `lan_ip`, on an interface other than the tunnel. An SSH host key at that address must then have the hub's recorded fingerprint, so the address is the hub and not any machine that answers. The hub opens SSH on its LAN address to its /24 for this. A failed proof stops the run before any WireGuard change. Its failed assertion is `node.hub_lan_unreachable`, or `node.hub_lan_identity_mismatch` when another machine answers. There is no fallback to the public endpoint: the operator fixes the network or the record. After a switch of the endpoint, the converge ends the old session and accepts only a handshake from after the switch.
- **The override goes.** `wireguard_endpoint_override` is dropped from the database, the API, the CLI, the SDK and the docs. `app-prod` gets the same endpoint from the rule.
- **Setting `lan_ip`.** `node:update --lan-ip` sets or clears the address on an existing Node as a record. Set the hub's address and converge the hub first, then set each Node's address and converge each Node. No two Nodes of one network share an address. Each Cluster counts as one network, and so do all Nodes outside every Cluster, which share the hub's network.
- **The hub's source address.** The hub marks its WireGuard packets. Marked packets to the private ranges (RFC 1918 and the WireGuard subnet) keep the main table. Every other marked packet, the hub's own public prefix included, takes the hub's own table, whose default route leaves from the public endpoint address. A hub-started handshake to a public peer therefore always leaves from the address that the peer uses. One Orbit-owned unit owns the rules and the table, and `orbit.conf` holds only the mark. No writer of `orbit.conf` can drop them, and a failing rule cannot stop the tunnel. Orbit pins the source only when the public endpoint is an IPv4 address.
- **Doctor.** A Doctor check for a Node whose live hub endpoint differs from the rendered one is not part of this decision. The agent cannot read WireGuard state, and Nick chose a separate change for it.

## Rejected alternatives

- **Keep the override as an escape hatch.** Two ways to say one thing; the incident showed that hand-set endpoints drift from the records.
- **Use Cluster membership as the signal.** Cluster membership says where Routes are served, not which network a Node shares with the hub. The `services` Node shares the hub's private network and belongs to no Cluster.
- **Fall back to the public endpoint when the LAN proof fails.** It would hide a wrong record and leave the Node on the path the operator meant to leave.
- **Rewrite the source with SNAT.** Connection tracking can change the source port when a translated flow meets an existing one, and the peer then roams to that port.
- **Put the rules in `orbit.conf` `PostUp`.** The older native hub projection of `node:wireguard-key` rewrites that file and may not write `PostUp`, so a restart after it loses the rules. A failing `PostUp` line also tears the tunnel down.
- **Keep every specific main route with `suppress_prefixlength 0`.** It also keeps the hub's connected public prefix, so a peer there would still be reached from the DHCP primary.
- **Change the source of the hub's default route.** It moves all of the hub's outgoing traffic, not only WireGuard, and DHCP renewals rewrite it.

## Consequences

- A converge of a Node on the hub's private network moves its tunnel to the LAN. Its traffic to the hub stays inside the data centre.
- The hub keeps answering public peers from the floating address after any route change.
- A Node that records a `lan_ip` in the hub's /24 on a different network fails its converge at the proof, before any change: `node.hub_lan_unreachable` when nothing answers SSH there, `node.hub_lan_identity_mismatch` when a foreign machine answers at the hub's LAN address. Record a `lan_ip` only for the network the Node is really on.

## Affects

- Components: apps/gateway, apps/cli, packages/php-sdk
- ADRs: uses the recorded-LAN rule of 0233, [public Process Routes](/reference/routes#public-process-routes-select-an-edge-separately-from-the-workload)
- Detail: the `swamp/` Ansible phases of `node:add` and `node:converge`, [WireGuard endpoints](/reference/wireguard-endpoints), [`node:add`](/cli/node/add), [`node:update`](/cli/node/update), [`node:converge`](/cli/node/converge) and their charts
- Verify: Gateway tests of the rendered hub and peer configuration; on the live fleet, converge the hub, then each Node on its private network, and read `wg show orbit endpoints` on every peer, the public ones included, for an endpoint that roamed to the hub's primary address
