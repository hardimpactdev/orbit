---
title: "Private DNS"
description: "How a managed Node selects its resolver, how the Gateway answers Cluster Router addresses, and how to inspect and repair one peer."
covers:
  - apps/gateway/app/Infrastructure/AppDev/{DnsmasqPrivateDnsManager,DevelopmentDnsConfigRenderer,PrivateDns*,*PrivateDns*,*DnsRequester*,DnsAddress,DecodedDnsQuery,VpnDnsmasqBackendListen,NativeClusterRouterDnsSelectionReconciler}.php
  - apps/gateway/app/Domain/AppDev/{ClusterRouterDnsSelection*,Dns*,PrivateDns*}.php
  - apps/gateway/app/Infrastructure/WireGuard/{NativeWireGuardPeerConverger,NativeWireGuardPeerDnsRepairer,PeerDnsPolicy}.php
  - apps/gateway/app/Infrastructure/Gateway/GatewayPrivateDnsResolver.php
  - apps/gateway/app/Domain/Nodes/{GatewayPrivateDnsRoute,NodeDesiredConvergeInput,NativeNodeRoleAddInput}.php
  - swamp/ansible/tasks/private-dns-listener.yml
  - apps/gateway/app/{Actions/Fleet/AdmitPeerDnsAction,Console/Commands/ReconcilePeerDnsCommand}.php
---

# Private DNS

Orbit VPN DNS resolves private names (such as Route domains and `gateway.orbit`) on the `vpn` Node (or on the Gateway itself when no separate `vpn` Node exists) and forwards all other queries to normal uplink resolvers. Managed Linux Nodes route only Orbit names to VPN DNS so public resolution stays up even if VPN DNS is unreachable.

## Resolver selection

Managed Linux peers are active Linux Nodes with at least one host role (any role except a record-only one such as `agent`), no `vpn` or `gateway` role, a WireGuard IP, a public key, and an SSH host fingerprint. For each peer, Orbit renders a split-DNS routing domain list containing the VPN domain, `orbit`, every Cluster and Node TLD, and every outside-TLD private Route domain. The list holds up to 512 domains so systemd's 1,024-domain limit keeps headroom for replacements.

| Peer setting | DNS server | Routing domains |
| --- | --- | --- |
| Default | Orbit VPN DNS | Rendered private domain set (such as `~orbit ~test`) |
| `--dns-server` | Usable WireGuard-subnet IP answering Orbit names | Same rendered private domain set |

Routing domains for outside-TLD Routes (such as `~app.example.com`) also match descendants; VPN DNS forwards uncatalogued descendants upstream. Managed split DNS leaves `/etc/resolv.conf` untouched and sets `DefaultRoute=no` on the `orbit` link via `PostUp`. A usable non-`orbit` uplink resolver is required (`vpn.peer_dns_uplink_missing` otherwise).

Namespace changes do not trigger automatic fleet SSH. Run `orbit:peer-dns-reconcile` on the Gateway to apply pending `fleet.peer_dns` updates to peers whose digest changed. Each `fleet.peer_dns` run updates `/etc/wireguard/orbit.conf` and `/etc/wireguard/orbit.dns-link`, configures `systemd-resolved`, and verifies that `gateway.orbit` resolves through `orbit` while `example.com` does not. Unmanaged peers return `vpn.peer_dns_unmanaged` (422). Roleless Nodes keep their client-owned `DNS =` line, and macOS machines outside the fleet use [`dns:resolve`](/cli/dns).

### Inspect a peer

Run these commands on a managed Linux peer to inspect its active split-DNS policy:

| Command | Expected result |
| --- | --- |
| `sudo cat /etc/wireguard/orbit.dns-link` | Line 1: link (`orbit`); line 2: VPN DNS IP; remaining lines: private domains. |
| `resolvectl status orbit` | Shows VPN DNS IP, `DefaultRoute: no`, and rendered `~` routing domains. |
| `getent ahostsv4 <route-domain>` | Returns the private Route address. |
| `getent ahostsv4 example.com` | Resolves through uplink DNS. |

Doctor reports `node.private_dns_uplink_missing`, `node.private_dns_policy_mismatch`, `node.private_dns_unreachable`, and `node.public_dns_unreachable`.

## The Gateway machine

When a separate Node holds `vpn`, the Gateway routes only its private domain (`~orbit`) to fleet VPN DNS via `/etc/systemd/system/wg-quick@orbit.service.d/orbit-gateway-dns.conf`. It keeps `DefaultRoute=no` so package mirrors, GitHub, and ACME stay reachable if VPN DNS is down. If `/etc/wireguard/orbit.dns-link` exists, the Gateway keeps that managed peer policy.

When no separate `vpn` role exists, the Gateway role installs the shared private-DNS listener (`orbit-private-dns.socket` and `orbit-private-dns.service`) on its own WireGuard IP. It requires `dig` (`bind9-dnsutils`, failing with `gateway.private_dns_tool_missing` if absent) before applying resolver routing. Removing the `gateway` role removes the drop-in, reverts the `orbit` link, and withdraws any local listener unused by a colocated `vpn` role.

## The listener

`orbit-private-dns` runs on the `vpn` Node (or Gateway when no separate `vpn` exists). It binds UDP and TCP port 53 on the VPN DNS WireGuard IP and forwards uncatalogued queries to dnsmasq on `127.0.0.55:53`.

### Upstream resolvers

The dnsmasq backend sets `no-resolv` and forwards only to non-loopback, non-`orbit` IPv4 resolvers read from `/run/systemd/resolve/resolv.conf` (or the default-route DHCP lease). If none are found, it falls back to `1.1.1.1` and `8.8.8.8`. Convergence moves `/etc/dnsmasq.d/ubuntu-fan` to `/var/lib/orbit/dnsmasq/disabled`.

### Release and sockets

The Gateway deploys the listener as a self-contained PHP 8.5 release (`serve.php` plus classes, no Composer dependencies) to `/var/lib/orbit/private-dns/releases/<digest>/`, keeping the previous release for rollback. Every publication runs `serve.php --self-test` before touching services and restarts the listener if its running PHP binary is stale.

`orbit-private-dns.socket` holds UDP/TCP port 53 (`FreeBind=yes`) across service restarts so queued queries are not dropped. The listener reloads `/var/lib/orbit/private-dns/catalog.json` on every query and every second while idle, writing its SHA-256 to `catalog.json.loaded`. Publication verifies that digest within 5 seconds and restarts the service only if it lags.

### Publication target

Orbit resolves the `vpn` and `gateway` holders from `active` or `provisioning` Nodes and assignments (`active` preferred over `provisioning`; `failed` and `removing` excluded), so publications continue working while `node:add` or `node:role:add --converge` runs.

## Cluster Router addresses

The listener selects the Cluster Router's LAN address for a query when:

- The Cluster is active and its Router has a `lan_ip`.
- The requester's WireGuard source IP belongs to an active member of that Cluster with its own `lan_ip`.

All other requesters receive the Router's WireGuard address. If a LAN path breaks, Orbit does not fall back to WireGuard; fix the LAN path or clear `lan_ip` and reconverge.

| Name | Answer |
| --- | --- |
| Node-scoped or custom proxy Route, or public Process hostname | Serving Node's (or Ingress's) WireGuard IP |
| `app-dev` Node TLD (when not a Cluster TLD), or non-Cluster `app-prod` Node TLD | That Node's WireGuard IP |
| `gateway.orbit`, `metrics.orbit` | `gateway` Node's WireGuard IP (`metrics.orbit` only while `metrics` is active) |
| `reverb.orbit`, `analytics.orbit`, `collector.ai-router.orbit` | Respective role or AI router collector Node's WireGuard IP |

New Routes cannot take reserved platform names (`route.domain_reserved`), and Cluster/Node TLDs cannot overlap `orbit` or the configured VPN domain at DNS label boundaries. Orbit omits exact `host-record` and LAN override lines whenever a TLD rule already returns the identical address for every requester.

## Repair one peer

`wireguard.repair-peer-dns` updates a managed peer's resolver settings under `/run/lock/orbit-wireguard-peer.lock` without touching WireGuard keys, roles, or the running tunnel, recording a `dns-repair-v2` snapshot in `/etc/wireguard/.orbit.peer-transaction` (mode `0600`, 2 MiB limit) for rollback on failure. Failure codes map `wireguard.*` to `vpn.*`: `vpn.peer_dns_busy`, `vpn.peer_dns_unreadable`, `vpn.peer_recovery_pending`, `vpn.peer_dns_state_unsupported`, `vpn.peer_dns_candidate_invalid`, `vpn.peer_dns_apply_failed`, `vpn.peer_dns_recovery_failed`, `vpn.peer_dns_uplink_missing`, `vpn.peer_dns_repair_failed`, and `vpn.configuration_invalid`.

## Why it works this way

Split DNS keeps public resolution working during VPN DNS outages without maintaining static `/etc/hosts` files. Deploying the listener as a small PHP release behind a systemd socket unit avoids syncing a full monorepo on the `vpn` Node and preserves in-flight queries across restarts. Forwarding the dnsmasq backend to uplink resolvers rather than `127.0.0.53` prevents DNS loops.
