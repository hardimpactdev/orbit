---
title: "Node retarget"
description: "How the Gateway moves a Node to a new public SSH target over public SSH or WireGuard without changing its identity, and how one lifecycle owner serializes Node changes."
covers:
  - apps/gateway/app/Actions/Nodes/RetargetNodeAction.php
  - apps/gateway/app/Console/Commands/RetargetNodeCommand.php
  - apps/gateway/app/Infrastructure/Nodes/NativeNodeProvisioningLock.php
---

# Node retarget

`php artisan orbit:node-retarget NAME HOST [--ssh-port=PORT]` admits one `node.retarget` Operation. Swamp verifies the admitted endpoint through official SSH. The Gateway records the new public SSH host and port only after native terminal success. The pinned host key, WireGuard address and other identity fields stay unchanged.

## One lifecycle owner per Node name

Admission takes the existing Node-name lifecycle lock, reads the actual active Node again and records its Operation hold. A competing admission fails before changing its endpoint. Swamp owns the remote steps and their compensation. Different Nodes can proceed independently.

## Two paths

Any role assignment selects the WireGuard path, including a failed or provisioning assignment. A roleless Node uses its new public SSH endpoint to prepare and publish its peer configuration, publish the exact context on the shared hub, start the tunnel and verify pinned SSH over WireGuard. The shared hub action preserves unrelated newer peers and the local hub private key.

| Node | Native path |
| --- | --- |
| No role | Pinned public SSH, peer and hub publication, then pinned WireGuard SSH |
| Any role | Pinned WireGuard SSH verification |

For a Node with roles, verification precedes the public endpoint record change. The roleless path captures its prior peer files and service state before mutation. Native compensation restores those files and the exact prior hub entry when a later step fails. Its terminal result reports `restored`; an uncertain compensation remains a failed Operation. The Gateway keeps the old endpoint after failure or cancellation.

The native workflow uses official SSH and public step results. It performs no agent action or synchronous Gateway host work. Follow the Operation to distinguish admission from completion.

## Repair a tunnel that is down

The Node starts its tunnel toward the `Endpoint` in `/etc/wireguard/orbit.conf`. When the Gateway's public address changes, for example in a cloned topology on a new subnet, that endpoint is stale and the tunnel stays down. Retarget then fails with `node.retarget_requires_vpn`, and the Node record stays active and unchanged.

Repair the endpoint on the Node as root, then retry:

```bash
conf=/etc/wireguard/orbit.conf
gateway=198.51.100.1            # the Gateway's current public address
port=$(sed -n 's/^Endpoint *= *.*://p' "$conf" | head -n 1)
sed -i "s|^Endpoint *=.*|Endpoint = $gateway:$port|" "$conf"
systemctl restart wg-quick@orbit
ping -c 1 10.44.0.1              # the Gateway's WireGuard address
```

The Incus harness runs the same repair in `apps/e2e/resources/guest/retarget-vpn.sh`.

## Failure codes

Admission refusals leave the endpoint unchanged. Native failures belong to the recorded Operation.

| Code | Meaning |
| --- | --- |
| `node.public_ssh_host_invalid` | The public endpoint intent is invalid. |
| `node.provisioning_busy` | Another admission holds the Node-name lifecycle lock. |
| `node.not_active` | The selected Node does not exist. |
| `node.retarget_requires_vpn` | The Node is inactive or lacks its recorded pinned WireGuard identity. |
| `node.bootstrap_gateway_unavailable` | There is no active Gateway control worker. |
| `node.bootstrap_identity_unavailable` | The existing Gateway SSH execution identity is unavailable. |
| `operation.native_failed` | A native verification or effect failed; inspect the Operation and native run. |
