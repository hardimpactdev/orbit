---
title: "WireGuard endpoints"
description: "Accepted endpoint forms, how Orbit picks a Node's hub endpoint, the LAN endpoint and its proof, the hub's source address, and recovery for an invalid stored endpoint."
covers:
  - apps/gateway/app/Domain/WireGuard/{WireGuardEndpoint,HubEndpoint,LanAddress}.php
  - apps/gateway/app/Infrastructure/WireGuard/{VpnConfigurationRepository,WireGuardServerConfigRenderer,NativeWireGuardPeerConverger}.php
  - apps/gateway/app/Console/Commands/BootstrapGatewayCommand.php
  - apps/gateway/app/Domain/Nodes/{NodeNetworkConvergeInput,NodeNetworkInput,NodeNetworkParticipantInput,NodeWireGuardInput,NodeWireGuardHubInput}.php
  - apps/gateway/app/Infrastructure/Nodes/NodeVpnConfigurationSource.php
  - apps/gateway/database/migrations/2027_02_02_000000_drop_node_wireguard_endpoint_override.php
  - swamp/ansible/tasks/{node-hub-lan-proof,node-converge-hub,node-hub-source,node-converge-handshake,node-converge-hub-peers,node-converge-hub-switch,node-converge-hub-move,node-add-hub-peers,node-converge-hub-filter-absent,managed-files-check}.yml
  - apps/gateway/app/Domain/Nodes/NodeLanAddress.php
---

# WireGuard endpoints

Orbit validates the WireGuard hub endpoint during bootstrap and selects each peer's hub endpoint automatically from Gateway records, preferring a verified private LAN path when a peer shares the hub's private `/24`.

## Accepted endpoint forms

Endpoints require a host and decimal port (`1`–`65535`) with no whitespace or control characters:

| Host form | Example | Result |
| --- | --- | --- |
| IPv4 address | `192.0.2.10:51820` | Accepted. |
| Hostname | `vpn.example.com:51820` | Accepted. |
| IPv6 address | `[2001:db8::10]:51820` | Accepted (square brackets required; bare IPv6 is rejected). |

`orbit:bootstrap` defaults to `--wireguard-subnet=10.44.0.0/24`, `--wireguard-ip=10.44.0.1`, `--wireguard-port=51820`, and `--domain=orbit`. If `--wireguard-endpoint` is omitted, Orbit combines the public host with `--wireguard-port` (adding brackets for IPv6). To fix a stored invalid endpoint, re-run `orbit:bootstrap` with a valid `--wireguard-endpoint`.

## The hub endpoint of a Node

Peer configurations pick the first matching endpoint (no per-Node override exists):

1. The hub's `lan_ip` and WireGuard port on a shared private `/24` ([The LAN endpoint](#the-lan-endpoint)).
2. The stored Gateway endpoint.
3. The `vpn` Node's public SSH host and WireGuard port.

The hub lists no endpoint on its own peer sections and learns each peer's source address from incoming packets. On the hub's private `/24`, `lan_ip` must be unique across every Node. Elsewhere it must be unique within its Cluster (or across non-Cluster Nodes), returning `cluster.lan_ip_conflict` (409) on collision.

## The LAN endpoint

A Node uses the hub's LAN endpoint when both the hub and the Node hold an IPv4 `lan_ip` in the same RFC 1918 `/24`. Before writing WireGuard files in [`node:add`](/cli/node/add) or [`node:converge`](/cli/node/converge), Orbit proves the LAN path:

1. `ip route get` confirms a direct on-link route from the Node's `lan_ip` to the hub's `lan_ip` outside the `orbit` tunnel.
2. `ssh-keyscan` on port 22 confirms one of the answering host keys matches the hub's pinned SSH fingerprint.

Failures stop before mutating WireGuard (`node.hub_lan_unreachable` or `node.hub_lan_identity_mismatch`) without falling back to the public endpoint. The hub admits scans via UFW rule `orbit:vpn-lan-ssh` (`22/tcp` from its `/24`) and forwards member traffic via `orbit:vpn-peer-forwarding`. Always converge the hub first when enabling LAN endpoints:

1. `orbit node:update <hub> --lan-ip=<ip>` and `orbit node:converge <hub>`
2. `orbit node:update <node> --lan-ip=<ip>` on each peer on that `/24`
3. `orbit node:converge <node>` on each peer

### Endpoint switch and rollback

When `node:converge` detects a hub endpoint change from the peer's previous `orbit.peers`, it runs a detached move job (`async: 180`) on the peer so losing the old SSH session cannot strand the Node:

1. Pins incoming WireGuard packets to the new IPv4 endpoint with a temporary `iptables` rule (`orbit-hub-move`, failing with `node.hub_switch_unpinned`; skipped for hostnames).
2. Installs staged `orbit.conf` and `orbit.peers`, keeping the previous files for rollback.
3. Removes the old hub peer and loads the new files (`node.hub_switch_load_failed` on error).
4. Waits up to 60 seconds for a fresh handshake on the new endpoint (`node.hub_switch_no_handshake` on timeout).

If loading or the handshake fails, the job restores the previous files and live tunnel and records the outcome in `orbit.move-result`. `node:add` refuses to move a live tunnel's endpoint (`node.hub_switch_by_converge`); use `node:converge` instead.

## The hub answers from its endpoint address

When a hub holds multiple IPv4 addresses on one interface, Linux may send hub-initiated handshakes from the wrong source address. For IPv4 endpoints, the hub sets `FwMark` to its WireGuard port and runs `orbit-hub-source.service` (`/etc/orbit/wireguard/hub-source`) with a `systemd-networkd` drop-in:

- Priority `31820`: marked packets to RFC 1918 ranges (and the WireGuard subnet) use the `main` routing table.
- Priority `31821`: all other marked packets use the routing table numbered after the WireGuard port, copying the default gateway and on-link connected public prefixes with the endpoint IP as source.

The hub's network phase verifies that the endpoint IP is local (`vpn.endpoint_not_local`) and that marked public packets use that source (`vpn.source_route_failed`). For hostname or IPv6 endpoints, Orbit removes `orbit-hub-source.service` and its routing rules.

## Converge the hub

The hub's private key (`/etc/wireguard/orbit.key`, mode `0600`) never leaves the hub. Orbit renders `orbit.conf` (`Address`, `ListenPort`, `FwMark`, and `PostUp` hooks) and one `orbit-peers.d/<node-id>.conf` file per peer.

During `node:converge` on the `vpn` Node, Orbit writes only changed files and prunes peer files for removed Nodes while keeping files with IDs above the snapshot's highest Node ID. It then reloads with `wg syncconf`, verifies every recorded peer is present (`vpn.hub_peer_missing`), and waits up to 60 seconds for recently live peers to handshake again.

Node removal deletes only the removed Node's peer file and reloads `wg syncconf` without rewriting other peers. Unmanaged peer key recovery uses [`node:wireguard-key`](/cli/node/wireguard-key) with the same key-collision checks as provisioning.
