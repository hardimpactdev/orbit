---
title: "WireGuard endpoints"
description: "Accepted endpoint forms, generated defaults, and recovery for an invalid stored WireGuard endpoint."
covers:
  - apps/gateway/app/Domain/WireGuard/WireGuardEndpoint.php
  - apps/gateway/app/Infrastructure/WireGuard/VpnConfigurationRepository.php
  - apps/gateway/app/Console/Commands/BootstrapGatewayCommand.php
  - apps/gateway/app/Actions/Gateway/GatewayBootstrapIdentityValidator.php
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

A peer configuration takes the first endpoint that exists:

1. The Node's endpoint override.
2. The stored Gateway endpoint.
3. The public SSH host of the `vpn` Node with the configured WireGuard port, with the same bracket rule.

The selected value must pass the same check before the Gateway renders the peer configuration.

## Peer key changes

[`node:wireguard-key`](/cli/node#orbit-nodewireguard-key) changes an unmanaged peer's desired public key and reconverges the hub. It preserves the peer's WireGuard address and endpoint override, and does not replace the stored Gateway endpoint. Use it for peer key recovery, not to repair an invalid endpoint. Peer key assignments share collision protection with native provisioning, so no two Nodes can claim the same key through those paths.

## Native peer convergence

The Gateway records the full public `node.network_converge` intent, including pinned SSH connections for the peer and VPN owner. All native steps run on Node 209. Official `@swamp/ssh` prepares the peer key, updates that peer on the hub, writes the peer configuration, applies it, and sets the resolver and firewall policy. The typed terminal result records the public key only after the native steps succeed. There are no effect-time Gateway reads, callbacks, or child Operations.

Private WireGuard keys stay on their hosts. The hub update preserves every other peer and the existing Interface section. It serializes with the host's existing WireGuard tool lock and uses `wg syncconf`; VPN1 receives no Swamp binary or worker, and an active tunnel is not restarted. An uncertain SSH outcome remains a failed native step. Resume through native workflow recovery; no hidden rollback or retry loop claims completion.

`fleet.peer_dns` also runs on 209 with the stored WireGuard host pin. Separate native steps inspect the current resolver, publish its persistent policy, set DNS, domains and default-route, restore any old uplink, and inspect the result. The typed result checks the admitted policy and usable uplink. It never calls an agent action or restarts the tunnel. The Gateway records the native result without repeating the comparison.

The retained enrollment and TaskVM parents still use their own admitted hub contract. Their publication and recovery authority is not changed by these two direct-operation ports.

## Recover an invalid stored endpoint

The Gateway does not guess where the host ends and the port starts in a stored bare IPv6 endpoint. Correct the value to the bracketed form, such as `[2001:db8::10]:51820`, and retry bootstrap or Node provisioning. Running `orbit:bootstrap` again with a valid `--wireguard-endpoint` replaces the Gateway setting.

### Hub restoration in the same native root

A removal parent admits `_wireguard` and `_wireguard_restore` through `NodeWireGuardInput::restorationSnapshot()`. The restore snapshot preserves the same hub, executor, context and pin and clears only the excluded Node. In its running result transaction, the parent restores the original Node status and calls `NodeWireGuardInput::beginRestoration($operation)` before restoration steps. The canonical bound public snapshot and existing private `configuration` field then select the admitted restore view and fresh peer data. Keep the same `wireguard-hub-ID` native model lock. No private endpoint selector or configuration renderer belongs in the parent.

Enrollment may admit the same nonexcluded hub view for restoration. Its parent restores the recorded peer key and original Node status before the same `beginRestoration()` checkpoint. Fresh private rendering then includes the original key, or omits a new peer whose prior key was null. Enrollment's Gateway executor must match its immutable target/root binding. The shared hub helper does not restore peer rows or infer a completed network rollback.

Before that restoration checkpoint, an exact `node.add` parent renders its context peer with the immutable enrollment candidate's WireGuard address. The peer key and all other peers come from current rows. This does not commit the candidate account or address to the Node. After the checkpoint, fresh rendering uses the restored database address and key and ignores the candidate override.

## Converge the hub

`node:wireguard-hub:converge <node>` admits one `node.wireguard_hub_converge` Operation for the active VPN owner. The Gateway records the full intent: the public peer list (name, public key and WireGuard address of every other Node that is not being removed) and the hub's SSH target. The hub's private key never leaves the hub; the run carries no secret.

One Swamp run on 209 reaches the hub over official SSH as root and runs one shipped script under the hub's peer lock. The script keeps the hub's own `[Interface]` section and writes the new `[Peer]` sections; an identical, running list writes nothing.

The script validates the file with `wg-quick strip`, keeps a rollback copy, live-syncs `wg-quick@orbit` with `wg syncconf` (or enables and starts a stopped one), then requires the service active and enabled and `wg show` to list exactly these peers.

When any check fails, the script puts the previous file and service state back and reports one error code:

- `wireguard.hub_config_invalid`: the rendered file has a shell hook or fails `wg-quick strip`.
- `wireguard.hub_start_failed`: the service did not stay active and enabled, or `wg show` lists other peers.
- `wireguard.hub_state_unsupported`: the hub has no file of its own; the role's bootstrap creates it.

The step uses the behavior name `wireguard-hub-<hub id>`, which task VM enrollment shares, so hub changes run one at a time. The command changes no peer key, address, role or credential.

The API is `POST /api/v1/nodes/{node}/wireguard-hub/converge` with an empty body. It returns HTTP 202 and an Operation, which the Gateway closes from the run's terminal result. The CLI follows the same Operation and accepts `--idempotency-key=KEY` and `--json`.

