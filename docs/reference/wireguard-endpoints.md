---
title: "WireGuard endpoints"
description: "Accepted endpoint forms, generated defaults, and recovery for an invalid stored WireGuard endpoint."
covers:
  - apps/gateway/app/Domain/WireGuard/WireGuardEndpoint.php
  - apps/gateway/app/Infrastructure/WireGuard/VpnConfigurationRepository.php
  - apps/gateway/app/Console/Commands/BootstrapGatewayCommand.php
  - apps/gateway/app/Domain/Nodes/{NodeNetworkConvergeInput,NodeNetworkInput,NodeNetworkParticipantInput,NodeNetworkProjection,NodeWireGuardInput}.php
  - apps/gateway/app/Infrastructure/Nodes/NodeVpnConfigurationSource.php
---

# WireGuard endpoints

This page tells an operator which WireGuard endpoint forms the Gateway accepts during bootstrap and Node provisioning, how it builds an omitted endpoint, and how to recover from an invalid stored value.

## Accepted endpoint forms

The Gateway checks the endpoint before bootstrap changes anything and before it renders a peer configuration.

| Host form | Example | Result |
| --- | --- | --- |
| IPv4 address | `192.0.2.10:51820` | Accepted with the same bytes. |
| Hostname | `vpn.example.com:51820` | Accepted with the same bytes. |
| IPv6 address | `[2001:db8::10]:51820` | Accepted. The square brackets are required. |

The port is a decimal integer from 1 through 65535. The Gateway rejects a bare IPv6 address, a missing or invalid port, whitespace, and control characters. An invalid explicit bootstrap endpoint stops before the Gateway writes settings, records a Node, creates files, or changes the host.

## Generated defaults

When `orbit:bootstrap` gets no `--wireguard-endpoint`, the Gateway combines the public host with `--wireguard-port`, which defaults to 51820. It adds square brackets only when the host is an IPv6 address.

`orbit:bootstrap --operator-ip` records the operator Node at an address of the same subnet. The address must differ from the Gateway's.

A peer configuration takes the first endpoint that exists:

1. The Node's endpoint override.
2. The stored Gateway endpoint.
3. The public SSH host of the `vpn` Node with the configured WireGuard port, with the same bracket rule.

The selected value must pass the same check before the Gateway renders the peer configuration.

## Peer key changes

[`node:wireguard-key`](/cli/node/wireguard-key) changes an unmanaged peer's desired public key and reconverges the hub. It preserves the peer's WireGuard address and endpoint override, and does not replace the stored Gateway endpoint. Use it for peer key recovery, not to repair an invalid endpoint. Peer key assignments share collision protection with native provisioning, so no two Nodes can claim the same key through those paths.

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

- `orbit.conf` is the wg-quick file: `Address`, `ListenPort`, `PostUp = wg set %i private-key /etc/wireguard/orbit.key`, and a `PostUp` that loads every file in `/etc/wireguard/orbit-peers.d`. It holds no peer.
- `orbit-peers.d/<node id>.conf` holds one `[Peer]` section.

One file per peer lets a run that adds one Node, such as task VM enrollment, write only its own file. Runs that overlap then never drop each other's peers.

The converge's Ansible `network` phase reaches the hub over SSH as root:

1. It writes `orbit.conf` and each peer file (0600, root).
2. The prune deletes the peer files of Nodes that are gone or being removed. Every other Node keeps its file.
3. It runs `wg syncconf` on input it composes on the Node: `PrivateKey` read from `orbit.key`, the port, then every peer file.

A Node keeps its peer file even before the Gateway records its key, because its own run may have written it.

The prune decides when it runs, not when the Gateway records the intent. It keeps the file of every Node in the snapshot and every file above the snapshot's newest Node id, and deletes every other `*.conf` file. The intent can be minutes old by then. A Node created meanwhile, such as a task VM claimed while the run waits, has a higher id, because Node ids only grow, so it keeps its peer. A keep-list alone deleted those peers and cut the Swamp worker off the new VM (proof runs 14547 and 14552, 2026-11-09).

A gone Node whose id is above every remaining Node keeps its leftover file until a converge after a newer Node exists.

`node:add` loads the peer files the hub has when it applies, not the peers of its admitted snapshot, for the same reason. The load adds new peers and removes stale ones. Unchanged peers keep their sessions, and the interface keeps its key.

Node removal runs one Swamp run on 209 that reaches the hub over SSH as root, with no script on the hub. `@swamp/cfgmgmt/exec` moves the running key into `/etc/wireguard/orbit.key` (0600) once, and skips this when the file exists. `@swamp/cfgmgmt/file` writes `orbit.conf`. One `rm -f` deletes the removed Node's file, and one `bash -c` command runs `wg syncconf` on the files that remain, composed on the Node as above.

`wg syncconf` removes the interface key when its input has none, which the topology proof showed. Composing the key in on the Node keeps it out of the Gateway, the vault and the run history.

The run ends with the official `@swamp/cfgmgmt/systemd` check that `wg-quick@orbit` runs and is enabled, and a native assert that every admitted peer is live.

When a step fails, the run stops and the hub stays as it is; there is no rollback, and a rerun writes and loads the same files again. The command changes no peer key, address, role or credential. The hub must already run its own `wg-quick@orbit`; the role's bootstrap creates it.

Use `POST /api/v1/nodes/{node}/converge` or [`node:converge`](/cli/node/converge). Its network part runs last. The recorded digest and live public WireGuard state guard the apply; a matching run touches no WireGuard file or service. The Gateway reserves the Node and shared hub against another converge, and records the network digest only when the repair and handshake check pass.
