---
title: "WireGuard endpoints"
description: "Accepted endpoint forms, generated defaults, and recovery for an invalid stored WireGuard endpoint."
covers:
  - apps/gateway/app/Domain/WireGuard/WireGuardEndpoint.php
  - apps/gateway/app/Infrastructure/WireGuard/VpnConfigurationRepository.php
  - apps/gateway/app/Console/Commands/BootstrapGatewayCommand.php
  - apps/gateway/app/Actions/Gateway/GatewayBootstrapIdentityValidator.php
  - apps/gateway/app/Domain/Nodes/{NodeNetworkInput,NodeNetworkParticipantInput,NodeNetworkProjection,NodeWireGuardInput}.php
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

The Gateway records one `node.network_converge` intent. Its native Swamp DAG prepares the peer key, records that public key, publishes the hub, then publishes and commits the peer configuration. Every effect uses the exact `orbit-node-<id>` target and matching `node` label. An unavailable worker waits in Swamp; another Node never takes its place.

All parents use the native `wireguard-hub-<hub-id>` model lock. The locked method reads fresh peer records and the existing `node.vpn_configuration` private material. The hub private key remains inside that effect callback. Router LAN changes reuse the Routes family’s canonical system lower; peer DNS repair preserves the running tunnel when it succeeds. The admitted hub executor may read its bounded public hub view to recheck the parent identity and cancellation. Only the root Node may post the parent result.

An exact `task_vm.destroy` parent may place shared hub publication on its admitted active Gateway worker. The six-field hub snapshot keeps the actual hub owner and records the Gateway executor. Its `configuration` binding admits only that Gateway reader. Private connection metadata comes from the hub's pinned SSH endpoint and the existing Gateway identity path; the shared native method reads it fresh inside `wireguard-hub-<hub-id>` and invokes the canonical bootstrap SSH transport. It never runs the hub command locally on the Gateway. Other parents must supply their own closed ownership predicate; this shared contract grants no enrollment, provision, or Node removal authority.

Failure recovery restores only the admitted peer transaction and original public key, then republishes the hub. A lost commit reply with matching active files and no pending transaction preserves the applied key. The parent still reports failure because that lost reply did not prove completion.

## Recover an invalid stored endpoint

The Gateway does not guess where the host ends and the port starts in a stored bare IPv6 endpoint. Correct the value to the bracketed form, such as `[2001:db8::10]:51820`, and retry bootstrap or Node provisioning. Running `orbit:bootstrap` again with a valid `--wireguard-endpoint` replaces the Gateway setting.

### Hub restoration in the same native root

A removal parent admits `_wireguard` and `_wireguard_restore` through `NodeWireGuardInput::restorationSnapshot()`. The restore snapshot preserves the same hub, executor, context and pin and clears only the excluded Node. In its running result transaction, the parent restores the original Node status and calls `NodeWireGuardInput::beginRestoration($operation)` before restoration steps. The canonical bound public snapshot and existing private `configuration` field then select the admitted restore view and fresh peer data. Keep the same `wireguard-hub-ID` native model lock. No private endpoint selector or configuration renderer belongs in the parent.

Enrollment may admit the same nonexcluded hub view for restoration. Its parent restores the recorded peer key and original Node status before the same `beginRestoration()` checkpoint. Fresh private rendering then includes the original key, or omits a new peer whose prior key was null. Enrollment's Gateway executor must match its immutable target/root binding. The shared hub helper does not restore peer rows or infer a completed network rollback.


## Converge the hub

`node:wireguard-hub:converge <node>` admits one `node.wireguard_hub_converge` Operation for the active VPN owner. The Gateway captures public identity and the existing VPN private reference; it does not render a secret configuration or contact a Node. The native parent reads fresh peers and the private key inside the stable `wireguard-hub-ID` method lock, then applies and records the bounded result.

VPN1 keeps the exact `orbit-node-1` placement and string `node=1` label. Its approved worker on 209 uses the canonical pinned SSH command transport. The hub never receives a local worker, and a missing proxy cannot fall back to the Gateway or 209. Readiness must be verified before a live convergence. The command changes no peer key, address, role, worker selection or credential.

The API is `POST /api/v1/nodes/{node}/wireguard-hub/converge` with an empty body. It returns HTTP 202 and an Operation. The CLI follows the same Operation and accepts `--idempotency-key=KEY` and `--json`. A failure remains visible; it does not prove the hub rolled back after an uncertain host effect.
