---
title: "WireGuard endpoints"
description: "Accepted endpoint forms, how Orbit picks a Node's hub endpoint, the LAN endpoint and its proof, the hub's source address, and recovery for an invalid stored endpoint."
covers:
  - apps/gateway/app/Domain/WireGuard/WireGuardEndpoint.php
  - apps/gateway/app/Infrastructure/WireGuard/VpnConfigurationRepository.php
  - apps/gateway/app/Console/Commands/BootstrapGatewayCommand.php
  - apps/gateway/app/Domain/Nodes/{NodeNetworkConvergeInput,NodeNetworkInput,NodeNetworkParticipantInput,NodeNetworkProjection,NodeWireGuardInput}.php
  - apps/gateway/app/Infrastructure/Nodes/NodeVpnConfigurationSource.php
  - apps/gateway/app/Domain/WireGuard/{HubEndpoint,LanAddress}.php
  - apps/gateway/app/Domain/Nodes/NodeWireGuardHubInput.php
  - apps/gateway/database/migrations/2027_02_02_000000_drop_node_wireguard_endpoint_override.php
  - swamp/ansible/tasks/{node-hub-lan-proof,node-converge-hub,node-hub-source}.yml
---

# WireGuard endpoints

This page tells an operator which WireGuard endpoint forms the Gateway accepts during bootstrap and which hub endpoint each Node gets. It also covers the hub's LAN endpoint, the address the hub sends from, and recovery from an invalid stored value. [ADR 0251](/decisions/0251-reach-the-wireguard-hub-over-the-recorded-lan) records the decision.

## Accepted endpoint forms

The Gateway checks the stored endpoint before bootstrap changes anything and before it renders a peer configuration.

| Host form | Example | Result |
| --- | --- | --- |
| IPv4 address | `192.0.2.10:51820` | Accepted with the same bytes. |
| Hostname | `vpn.example.com:51820` | Accepted with the same bytes. |
| IPv6 address | `[2001:db8::10]:51820` | Accepted. The square brackets are required. |

The port is a decimal integer from 1 through 65535. The Gateway rejects a bare IPv6 address, a missing or invalid port, whitespace, and control characters. An invalid explicit bootstrap endpoint stops before the Gateway writes settings, records a Node, creates files, or changes the host.

## Generated defaults

When `orbit:bootstrap` gets no `--wireguard-endpoint`, the Gateway combines the public host with `--wireguard-port`, which defaults to 51820. It adds square brackets only when the host is an IPv6 address.

`orbit:bootstrap --operator-ip` records the operator Node at an address of the same subnet. The address must differ from the Gateway's.

## The hub endpoint of a Node

A peer configuration takes the first endpoint that applies:

1. For a Node on the hub's private network: the hub's LAN address with the WireGuard port. See [The LAN endpoint](#the-lan-endpoint).
2. The stored Gateway endpoint.
3. The public SSH host of the `vpn` Node with the configured WireGuard port, with the same bracket rule.

The second and third form the hub's public endpoint. The selected value must pass the same check before the Gateway renders the peer configuration. There is no per-Node override.

The hub's own peer sections name no endpoint. The hub learns each peer's address from the packets it receives, so a peer that uses the LAN endpoint is reached at its LAN address.

## The LAN endpoint

A Node is on the hub's private network when the hub and the Node both record an IPv4 `lan_ip` and both addresses are in the same IPv4 /24. [Public Process Routes](/cli/route/create) use the same rule. A Node without a `lan_ip`, or with one in another /24, such as a home Node on `192.168.6.0/24` with the hub on `10.0.0.0/24`, uses the public endpoint.

The record is a claim. Before Orbit writes a WireGuard file that names the LAN endpoint, in [`node:add`](/cli/node/add) and in the network phase of [`node:converge`](/cli/node/converge), the Node proves it:

1. The kernel routes the hub's LAN address directly. `ip route get` shows no gateway, the Node's recorded `lan_ip` as source, and an interface other than the `orbit` tunnel.
2. The hub's LAN address answers ICMP from the Node's `lan_ip`.

A failed check stops the run before any WireGuard file or peer changes. The phase fails; the failed assertion in its log reads `node.hub_lan_unreachable`. Orbit does not fall back to the public endpoint. Fix the network, or clear the wrong `lan_ip` with [`node:update --lan-ip=`](/cli/node/update), and run the command again. The handshake check after the change proves that the address is the hub.

Set the addresses in this order:

1. `node:update <hub> --lan-ip=<address>` on the `vpn` Node.
2. `node:update <node> --lan-ip=<address>` on each Node on that network.
3. `node:converge <node>` on each of those Nodes. The hub needs no converge for its peers to move; it needs one for [its source address](#the-hub-answers-from-its-endpoint-address).

## The hub answers from its endpoint address

A hub can hold several addresses on one interface, such as a floating address that peers use and a DHCP primary address that its default route prefers. Linux picks the source of a WireGuard packet from the route. When the hub starts a handshake and the route prefers another address, the peer roams to that address and stays there.

The hub therefore marks its WireGuard packets with `FwMark`, set to the WireGuard port, and routes marked packets with two rules:

1. Priority 31820: the main table without its default route. Packets to the private network and to the WireGuard subnet keep their normal route and source.
2. Priority 31821: table number equal to the WireGuard port. Its default route copies the main default route and sets the source to the public endpoint address.

The hub's `orbit.conf` holds the mark and adds the rules and the route in `PostUp`, so they return after a restart. A failed route never stops the tunnel from starting.

The hub converge applies the same commands to the running hub and checks that a marked packet to a public address leaves from the endpoint address; a failed check fails the converge. A hub whose public endpoint is a hostname or an IPv6 address gets no source rule. An IPv4 endpoint address that the hub does not hold fails the hub's network phase; the failed assertion reads `vpn.endpoint_not_local`. A source that is still wrong after the commands reads `vpn.source_route_failed`.

Every `wg syncconf` on the hub carries the mark. `syncconf` resets an interface setting that its input leaves out, as it does with the private key.

## Peer key changes

[`node:wireguard-key`](/cli/node/wireguard-key) changes an unmanaged peer's desired public key and reconverges the hub. It preserves the peer's WireGuard address and does not replace the stored Gateway endpoint. Use it for peer key recovery, not to repair an invalid endpoint. Peer key assignments share collision protection with native provisioning, so no two Nodes can claim the same key through those paths.

## Native peer convergence

The Gateway records the full public `node.converge` network intent, including pinned SSH connections for the peer and the VPN owner. It renders every WireGuard file, and none holds a private key:

- the peer's `orbit.conf`, the wg-quick file. Its `PostUp` lines load the key file and set split DNS;
- the peer's `orbit-peers.conf`: only that `[Peer]`;
- this Node's peer file on the hub, `orbit-peers.d/<node id>.conf`. The run puts the prepared public key in it.

One Swamp run on 209 reaches both Nodes over official SSH, with no script on either:

1. `@swamp/cfgmgmt/exec` creates `/etc/wireguard/orbit.key` (0600) on the peer once. One `wg pubkey` command reads its public key, and a typed step refuses a key that differs from the stored one or collides with another Node's key.
2. `@swamp/cfgmgmt/file` writes this Node's peer file on the hub, and `wg addconf` loads it. No other peer file changes.
3. When the stored key changed, `wg set ... remove` drops the old key from the hub.
4. `@swamp/cfgmgmt/file` writes the peer's two files, and `@swamp/cfgmgmt/systemd` keeps `wg-quick@orbit` running and enabled.
5. One `wg syncconf` command loads the peer list. It composes the key from the key file on the Node, and a running tunnel is not restarted.
6. The firewall trust rule and the [resolver policy](/reference/private-dns) follow.

The typed terminal result records the public key only after these steps succeed. The key and Operation result commit together in one short database transaction. When a step fails, the run stops; there is no rollback, and a rerun writes the same files again. There are no effect-time Gateway reads, callbacks, or child Operations.

`fleet.peer_dns` also runs on 209 with the stored WireGuard host pin. `@swamp/cfgmgmt/line` keeps the Gateway-rendered hook line in `orbit.conf` and leaves the rest of the file as it is, and `@swamp/cfgmgmt/file` writes the marker `/etc/wireguard/orbit.dns-link`. `@swamp/cfgmgmt/exec` sets DNS, domains and default-route; its `notIf` guard skips the command when the live `orbit` link already holds them. The end check resolves the Gateway's private name through `orbit` and `example.com` outside it. It never calls an agent action or restarts the tunnel.

## Recover an invalid stored endpoint

The Gateway does not guess where the host ends and the port starts in a stored bare IPv6 endpoint. Correct the value to the bracketed form, such as `[2001:db8::10]:51820`, and retry bootstrap or Node provisioning. Running `orbit:bootstrap` again with a valid `--wireguard-endpoint` replaces the Gateway setting.

### Hub restoration in the same native root

A removal parent admits `_wireguard` and `_wireguard_restore` through `NodeWireGuardInput::restorationSnapshot()`. The restore snapshot preserves the same hub, executor, context and pin and clears only the excluded Node. In its running result transaction, the parent restores the original Node status and calls `NodeWireGuardInput::beginRestoration($operation)` before restoration steps. The canonical bound public snapshot and existing private `configuration` field then select the admitted restore view and fresh peer data. Keep the same `wireguard-hub-ID` native model lock. No private endpoint selector or configuration renderer belongs in the parent.

Before that restoration checkpoint, an exact `node.add` parent renders its context peer with the immutable enrollment candidate's WireGuard address. The peer key and all other peers come from current rows. This does not commit the candidate account or address to the Node. After the checkpoint, fresh rendering uses the restored database address and key and ignores the candidate override.

## Converge the hub

`node:converge <node>` publishes the full peer list when its target is the active VPN owner. The Gateway records the full intent: one peer file per Node that has a key and is not being removed, the Nodes whose files to keep, the newest Node id, and the hub's SSH target. The hub's private key never leaves the hub; the run carries no secret.

A removal does not rewrite the list. It deletes the file of the removed Node, whether or not the Gateway recorded its key, and loads the files that remain, so the live peer goes at once. It does not prune the files of Nodes that are already gone; the next converge of the hub does. It writes no other peer and keeps none, because the intent is a snapshot and a removal admitted at the same time as another would otherwise put the other's peer back.

The hub's private key never leaves the Node (Nick, 2026-11-02). The Gateway renders public files only:

- `orbit.conf` is the wg-quick file. It holds no peer.
- `orbit-peers.d/<node id>.conf` holds one `[Peer]` section.

`orbit.conf` holds `Address`, `ListenPort`, `FwMark`, `PostUp = wg set %i private-key /etc/wireguard/orbit.key` and a `PostUp` that loads every file in `/etc/wireguard/orbit-peers.d`. For an IPv4 public endpoint, it also holds the `PostUp` lines of [the source rule](#the-hub-answers-from-its-endpoint-address).

One file per peer lets a run that adds one Node, such as task VM enrollment, write only its own file. Runs that overlap then never drop each other's peers.

The converge's Ansible `network` phase reaches the hub over SSH as root:

1. It writes `orbit.conf` and each peer file (0600, root).
2. The prune deletes the peer files of Nodes that are gone or being removed. Every other Node keeps its file.
3. It runs `wg syncconf` on input it composes on the Node: `PrivateKey` read from `orbit.key`, the port and the mark, then every peer file.
4. The source rule is applied to the running hub and checked.

A Node keeps its peer file even before the Gateway records its key, because its own run may have written it.

The prune decides when it runs, not when the Gateway records the intent. It keeps the file of every Node in the snapshot and every file above the snapshot's newest Node id, and deletes every other `*.conf` file. The intent can be minutes old by then. A Node created meanwhile, such as a task VM claimed while the run waits, has a higher id, because Node ids only grow, so it keeps its peer. A keep-list alone deleted those peers and cut the Swamp worker off the new VM (proof runs 14547 and 14552, 2026-11-09).

A gone Node whose id is above every remaining Node keeps its leftover file until a converge after a newer Node exists.

`node:add` loads the peer files the hub has when it applies, not the peers of its admitted snapshot, for the same reason. The load adds new peers and removes stale ones. Unchanged peers keep their sessions, and the interface keeps its key.

Node removal runs one Swamp run on 209 that reaches the hub over SSH as root, with no script on the hub. `@swamp/cfgmgmt/exec` moves the running key into `/etc/wireguard/orbit.key` (0600) once, and skips this when the file exists. `@swamp/cfgmgmt/file` writes `orbit.conf`. One `rm -f` deletes the removed Node's file, and one `bash -c` command runs `wg syncconf` on the files that remain, composed on the Node as above.

`wg syncconf` removes the interface key when its input has none, which the topology proof showed. Composing the key in on the Node keeps it out of the Gateway, the vault and the run history.

The run ends with the official `@swamp/cfgmgmt/systemd` check that `wg-quick@orbit` runs and is enabled, and a native assert that every admitted peer is live.

When a step fails, the run stops and the hub stays as it is; there is no rollback, and a rerun writes and loads the same files again. The command changes no peer key, address, role or credential. The hub must already run its own `wg-quick@orbit`; the role's bootstrap creates it.

Use `POST /api/v1/nodes/{node}/converge` or [`node:converge`](/cli/node/converge). Its network part runs last. The recorded digest and live public WireGuard state guard the apply; a matching run touches no WireGuard file or service. The Gateway reserves the Node and shared hub against another converge, and records the network digest only when the repair and handshake check pass.
