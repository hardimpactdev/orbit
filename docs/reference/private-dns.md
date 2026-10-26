---
title: "Private DNS"
description: "How a managed Node selects its resolver, how the Gateway answers Cluster Router addresses, and how to inspect and repair one peer."
covers:
  - apps/gateway/app/Infrastructure/AppDev/{DnsmasqPrivateDnsManager,DevelopmentDnsConfigRenderer,PrivateDns*,*PrivateDns*,*DnsRequester*,DnsAddress,DecodedDnsQuery,VpnDnsmasqBackendListen,NativeClusterRouterDnsSelectionReconciler}.php
  - apps/gateway/app/Domain/AppDev/{ClusterRouterDnsSelection*,Dns*,PrivateDns*}.php
  - apps/gateway/app/Infrastructure/WireGuard/{NativeWireGuardPeerConverger,NativeWireGuardPeerDnsRepairer,PeerDnsPolicy,PeerDnsInspector,UplinkDnsResolvers}.php
  - apps/gateway/app/Infrastructure/Gateway/GatewayPrivateDnsResolver.php
  - apps/gateway/app/Domain/Nodes/GatewayPrivateDnsRoute.php
  - apps/gateway/app/{Actions/Nodes/RepairNodeDnsAction,Actions/Nodes/ReconcilePeerDnsAction,Console/Commands/RepairNodeDnsCommand,Console/Commands/CheckNodeDnsCommand,Console/Commands/ReconcilePeerDnsCommand}.php
---

# Private DNS

Orbit VPN DNS answers private names, such as Route domains and `gateway.orbit`, and forwards every other query to ordinary resolvers. It runs on the Node with the `vpn` role. Managed Linux Nodes route only Orbit names to it by default. This page explains resolver selection, the Gateway machine's resolver, the listener, Cluster Router addresses, and how to inspect or repair one peer. The active WireGuard manager projects VPN DNS behavior without creating local dnsmasq snippet files.

[ADR 0219](/decisions/0219-answer-cluster-names-from-a-dns-on-the-router) is in progress. It proposes moving the Router LAN answers to a Cluster DNS on each Cluster Router and replaces the listener with plain dnsmasq. The proposal preserves the Cluster TLD and exact Cluster Route domains outside it, and forwards remaining names to VPN DNS. The approved behavior returns members automatically to healthy LAN DNS after VPN fallback. The recovery mechanism and member acceptance checks remain implementation gates. Until the DNS service and member steps are built, this page describes the listener.

Build step 1 adds only the [Router LAN DNS firewall families](/cli/firewall#router-lan-member-rules), alongside HTTPS, with exact eligible-member IPv4 sources and Router LAN destination port 53 over UDP and TCP. It preserves this listener, current resolver selection, and Route answers; it does not install dnsmasq on Routers or switch members.

Before later Cluster DNS enablement, the [firewall rollout](/cli/firewall#safe-dns-firewall-rollout) requires authorized narrowing of overlapping allowances, preservation of bridge-scoped Incus DNS, and authorized success plus unauthorized LAN denial over both transports. Disposable tests are not live acceptance. Exact outside-TLD Route NODATA, descendant forwarding, no-TLD behavior, bind-overlap refusal, and automatic member return remain later implementation gates, not claims made by the firewall step.

## Resolver selection

Managed Linux peers send only Orbit names to private DNS. The Gateway renders the private VPN domain, every Cluster TLD, every Node TLD, and every private Route domain outside those suffixes. It removes duplicates and suffixes already covered by another suffix. All other names use the machine's normal provider or DHCP resolver. A VPN DNS outage therefore leaves public resolution available while that resolver and network work.

| Peer configuration | DNS server | Routing domains |
| --- | --- | --- |
| Default | Orbit VPN DNS | The rendered Orbit private-name set, such as `~orbit ~test` |
| Per-Node `--dns-server` | A usable WireGuard-subnet address that answers Orbit names | The same complete private-name set |

A routing domain for an outside-TLD Route, such as `~app.example.com`, also captures descendants. It creates no private wildcard answer: VPN DNS still forwards descendants that have no private record. Resolver routing cannot select only the exact name. A private Route on an apex such as `example.com` also moves every descendant lookup to VPN DNS; choose a narrower name when those descendants must keep public resolution.

Provisioning, `node:converge`, and [repair](#repair-one-peer) apply the render. The Gateway reconciles active managed peers every five minutes with `orbit:peer-dns-reconcile`. It checks persisted and live settings and repairs only mismatches. The delay is five minutes plus queue and traversal time; unreachable peers wait for a later successful pass. Record changes do not block on fleet SSH.

Private Route, Cluster TLD, and Node TLD writes validate names in a serialized database transaction. A change is refused only if the rendered count increases beyond 512 domains. Deletion or rollback that keeps or reduces an already oversized count is allowed. The renderer also refuses invalid stored names or more than 512 domains before peer mutation, never truncating or using `~.`. systemd 259 allows 1,024 domains per link. Orbit reserves half that capacity so a complete replacement can allocate new domains before resolved removes old ones.

Doctor reports `node.private_dns_uplink_missing` when no usable non-Orbit DNS server is configured. Reconcile reports `vpn.peer_dns_uplink_missing` by Node name. Doctor reports `node.private_dns_policy_mismatch` for drift and `node.private_dns_unreachable` when a private DNS query fails or returns the wrong Gateway address. `node.public_dns_unreachable` reports a failed uncached public lookup or one sent over `orbit`. Global `resolved.conf` DNS servers are valid public resolvers. Doctor and reconcile read the peer through the [Node agent](/reference/node-agent#commands): `wireguard.inspect-peer-dns` takes one snapshot of the saved and live state under a shared peer lock, and `wireguard.query-dns` sends the uncached lookups. A failed read is unverifiable (`node.inspection_failed`), not policy drift. Namespace changes can report temporary mismatch until the next successful scheduled pass.

The policy leaves `/etc/resolv.conf` and uplink DNS in place. Route-only suffixes make the private link ineligible as a default DNS route. Apply sets the private link's live default-route flag to false. PostUp sets its DNS server and route-only domains; after tunnel restart, resolved infers `no` from those domains. WireGuard endpoint overrides stay separate. The global VPN DNS server must also be a usable WireGuard-subnet address for managed peers.

When repair moves an old underlay selection to `orbit`, it first requires a networkd-managed uplink. It removes the old transient DNS selection with `resolvectl revert` and preserves the uplink’s LLMNR, mDNS, DNSSEC, DNS over TLS, and negative trust anchors. It reconfigures the uplink through networkd to restore provider or DHCP DNS. It waits up to 15 seconds for networkd and resolved to expose a usable non-Orbit server.

Every managed split apply requires a usable non-`orbit` per-link or global resolver before switching. If none exists, it refuses with `vpn.peer_dns_uplink_missing` and retains or restores the previous DNS state. A durable transaction snapshot restores all preceding uplink resolver settings, including its default-route flag, if migration fails. Successful migration leaves the uplink default-route decision with networkd.

A [Node without roles](/reference/node-provisioning#nodes-without-roles) keeps its client-owned `DNS =` line. macOS, the VPN holder, and the Gateway's separate bootstrap resolver are outside this managed peer policy. On a Mac outside the fleet, use [`dns:resolve`](/cli/dns) for a local override.

### Inspect a peer

Read the saved state first to find the resolver link, server, and routing domains. Then inspect the live systemd-resolved state.

| Command | Expected result for the default policy |
| --- | --- |
| `sudo cat /etc/wireguard/orbit.dns-link` | Line 1 is the resolver link, line 2 is the Orbit VPN DNS address, and the remaining lines are the rendered private domains without `~`. |
| `resolvectl status orbit` | The `orbit` link lists the Orbit VPN DNS address and the rendered route-only domains, such as `~orbit ~test`. |
| `sudo grep -E '^(PostUp|PreDown) =' /etc/wireguard/orbit.conf` | `PostUp` sets the DNS server and the rendered private domains. There is no `PreDown`. |
| `getent ahostsv4 <route-domain>` | The normal resolver returns the private Route address. |
| `dig +noall +answer @<vpn-dns-address> <route-domain> A` | A direct query returns the same answer. |
| `getent ahostsv4 example.com` | An ordinary name resolves through the provider or DHCP resolver. |
| `ip route` | Application routes do not depend on the DNS selection. |

Peer convergence uses the agent's `wireguard.prepare-peer-key`, `wireguard.publish-peer`, `wireguard.commit-peer`, and `wireguard.rollback-peer` commands. Admission carries them over the pinned bootstrap SSH connection until the tunnel is verified, then uses the verified connection to finish the transaction. Repair and inspection use the same agent. Routing domains travel in the standard-input envelope, so the SSH command line stays small at the 512-domain cap.

The `PostUp` hook restores the selection whenever the `orbit` interface starts. There is no `PreDown` hook, because systemd-resolved drops the link settings when WireGuard deletes the interface. The policy uses the `orbit` link, including with an explicit server override. Repair reads old underlay state only to migrate it. Native peer DNS repair refuses unmanaged targets with `vpn.peer_dns_unmanaged`. Explicit repair also refuses the Gateway. AppDev TLD convergence reports the refusal and leaves its bootstrap resolver unchanged.

## The Gateway machine

The Gateway machine is not a managed peer. When it also holds `vpn`, its tunnel is the hub. When `gateway` moved to another machine, that machine keeps the tunnel it joined with. So the Gateway sets its own resolver in a separate step.

Gateway bootstrap and every `gateway` role convergence send queries for the private VPN domain to Orbit VPN DNS over the `orbit` link. The address is the configured VPN DNS server, or the WireGuard address of the `vpn` Node. Every other query, including names under Node and Cluster TLDs, stays on the uplink resolvers when the Gateway machine is outside the managed peer policy. So the Gateway still resolves package mirrors, GitHub, and ACME endpoints while Orbit VPN DNS is down. The Orbit CLI on the Gateway machine resolves `reverb.orbit` and stays live.

The drop-in `/etc/systemd/system/wg-quick@orbit.service.d/orbit-gateway-dns.conf` applies the route whenever the tunnel starts. It sets the routing domain and turns off the link's default DNS route before it sets the server, so the link never routes every name. A failure there never fails the tunnel. When `/etc/wireguard/orbit.dns-link` exists, the machine is a managed peer, and the step leaves the peer's policy in place.

If Orbit VPN DNS is unreachable, a private-name lookup on the Gateway machine takes about 40 seconds to fail, because systemd-resolved has no per-link timeout. Ordinary names still resolve at once.

| Command | Expected result on the Gateway machine |
| --- | --- |
| `resolvectl domain orbit` | `~orbit` |
| `resolvectl default-route orbit` | `no` |
| `resolvectl dns orbit` | The Orbit VPN DNS address |
| `getent hosts reverb.orbit` | The address of the Node that holds `websocket` |
| `getent ahostsv4 example.com` | An ordinary name resolves through the uplink resolvers. |

A failure of this step does not fail bootstrap or the role. The role stays `active`, and the response's `follow_up` names the failed route and the command that retries it. The CLI prints it as a warning. The Gateway logs the underlying code, `vpn.dns_resolver_failed` when the command fails on the machine. `orbit doctor --family=role` reports `role.private_dns_route_mismatch` until the route matches. Run `orbit node:role:add <gateway-node> gateway --converge` to retry.

Removing the `gateway` role removes the drop-in and reverts the `orbit` link. When that step fails, the role stays `failed` with `failed_step=remove:gateway-private-dns-resolver`, and running the removal again finishes it. `--offline` removal changes nothing on the machine and lists the drop-in under `retained_on_node`. [Relocating the gateway role](/solutions/relocate-gateway-role) adds the route on the target and removes it from the source.

## The listener

The listener and the published catalog live on the Node that holds `vpn`. The listener answers private names from the catalog on the VPN WireGuard DNS address. It forwards every other query to a dnsmasq backend on `127.0.0.55`. The Gateway sends the publication to the `vpn` Node over pinned SSH, including when both roles share one Node. This uses the managed account and host key established during bootstrap. Local `sudo` would retain the HTTP worker’s read-only filesystem namespace and could not publish DNS configuration. The worker’s filesystem protection stays enabled.

Gateway bootstrap starts `orbit-private-dns.service` after the dnsmasq backend is bound, so the first managed peer can resolve ordinary names during its role setup.

### Upstream resolvers

The dnsmasq backend sets `no-resolv` and forwards only to the IPv4 resolvers that the `vpn` Node itself uses on its uplink. At convergence the Gateway reads them from `/run/systemd/resolve/resolv.conf`. When that file lists none, it reads the DHCP lease of the default-route interface. It skips loopback addresses and the `orbit` interface, so forwarding never returns to the VPN DNS listener. When it finds no resolver, the backend uses `1.1.1.1` and `8.8.8.8`.

Convergence also moves the stock `/etc/dnsmasq.d/ubuntu-fan` snippet to `/var/lib/orbit/dnsmasq/disabled`, because it would add listen addresses beside the backend.

### Release and sockets

Every publication self-tests the installed release with the installed PHP before any service changes, even when the release is unchanged. Every listener start or restart, including rollback, requires a passing self-test. A listener whose process executable differs from the installed PHP binary, including a deleted executable after a package update, is stale and restarts behind its socket after validation.

After the PHP installer reports `applied` on an active VPN Node, recipe and VPN role convergence call this publication path. The VPN Node remains active while role reconvergence temporarily claims its assignment as provisioning. Publication owns listener activation, including socket ownership and catalog confirmation. Failed self-tests retain the running listener and report the failure.

If activation fails, publication restores the previous records, catalog, and units, reloads systemd, and restarts dnsmasq. It self-tests the previous listener release before attempting to enable or restart it. A failed listener self-test does not skip the systemd reload or dnsmasq restart.

The Gateway installs the listener itself. The listener is a small release: `serve.php` and the Gateway classes it uses, with no Composer dependencies. Every publication that activates the listener sends the release from the Gateway's own code to the `vpn` Node. It lands in `/var/lib/orbit/private-dns/releases/<id>/`, where `<id>` is a digest of its files. So a Gateway deploy that changes the listener code produces a new id, and the next publication installs it. The publication keeps the previous release for rollback and removes older ones.

The `vpn` recipe profile owns `php:8.5`, including the listener’s CLI runtime and its `sockets` and `pcntl` modules. VPN role convergence installs and verifies that build before the role completes its prerequisites. When provisioning requests VPN with other roles, VPN converges first so publishing roles have their listener runtime.

VPN-only Nodes keep shared PHP-FPM disabled and inactive and preserve an existing mask. On a Node with Gateway or application roles, package changes validate and restart all active colocated PHP 8.5 FPM masters; a failed validation or restart fails installation and rolls it back. Recipe convergence verifies the same build on the VPN Node; an unmanaged PHP installation is not a listener prerequisite.

Each release holds a `.manifest` of file digests. Before a publication installs a release, it runs `serve.php --self-test`, which checks `ext-sockets` and `ext-pcntl` and loads every class. When an installed file differs from the manifest, the publication installs the release again and restarts the listener on it.

`orbit-private-dns.socket` binds UDP and TCP port 53 on the VPN DNS address and passes both sockets to `orbit-private-dns.service`. A service restart never closes them. Queries that arrive during a restart wait in the socket for the next listener. On `SIGTERM` the listener finishes the query in hand and exits. The unit gives it 5 seconds.

The listener limits each TCP read or write to two seconds. Its upstream client also limits each socket wait to two seconds. It reads a UDP answer as one datagram without waiting for the upstream socket to close. A missing or truncated UDP answer triggers a TCP attempt. When that attempt fails, the listener can still answer the next catalog query.

| Unit | Content |
| --- | --- |
| `/etc/systemd/system/orbit-private-dns.socket` | `ListenDatagram` and `ListenStream` on the VPN DNS address, with `FreeBind=yes`, and no ordering on the WireGuard tunnel, which would form a boot cycle |
| `/etc/systemd/system/orbit-private-dns.service` | `php8.5 /var/lib/orbit/private-dns/releases/<id>/serve.php --listen=… --port=53 --catalog=… --upstream=127.0.0.55:53`, `Sockets=orbit-private-dns.socket`, and `After=wg-quick@orbit.service` without `Requires=` or `Wants=`, so a query never starts a stopped tunnel |

### Catalog confirmation

The listener reads the catalog again on every query and once a second while idle. It compares file contents, not cached file status. After each load it writes the catalog's SHA-256 digest to `/var/lib/orbit/private-dns/catalog.json.loaded`. A catalog change never restarts a current listener.

Every publication checks that confirmation while the listener runs.

| Listener state | Publication result |
| --- | --- |
| The confirmation matches the published catalog within 5 seconds | The listener keeps running. |
| The catalog changed and the confirmation does not match within 5 seconds | The publication removes the confirmation and restarts the service behind its socket. |
| The catalog is unchanged and the confirmation names another catalog | The publication removes the confirmation and restarts the service once. |

A failed start or restart restores the previous units, DNS files, and services. A failed publication logs the target Node, exit code, duration, stdout, stderr, and whether the output was truncated before a role operation wraps the error. Read the Gateway log for `Private DNS publication failed.` when an activity reports `app-dev.dns_config_failed`.

### Publication target

The Gateway finds the `vpn` and `gateway` Nodes by their role. A holder can be `active` or `provisioning`, and its role assignment can be `active` or `provisioning`. An `active` Node wins over a `provisioning` Node. A `failed` or `removing` Node is never a holder.

This matters during these commands:

- `node:add` on an existing `gateway` or `vpn` Node marks the Node `provisioning` and keeps its assignments active.
- `node:role:add NODE ROLE --converge` marks the assignment `provisioning` and keeps the Node active.

In both cases, publication still finds the `vpn` Node and publishes there over SSH. The reserved records also keep their holder: `gateway.orbit`, `metrics.orbit`, `reverb.orbit`, `analytics.orbit`, the analytics tracking hosts, and `collector.ai-router.orbit`.

## Cluster Router addresses

The requester's registered Node decides which Router address it gets for a Cluster name. Private DNS answers are selections of an address. They do not change how application traffic is routed. [Routes](/reference/routes#set-up-private-traffic) explains how a resolved Route reaches its workload.

The Gateway answers with the Router's LAN address when all of these hold:

- the Cluster is active, and its Router has a LAN address;
- the requester is an active member of that Cluster with a LAN address and a registered WireGuard identity.

Every other requester gets the Router's WireGuard address. That includes a member without a LAN address, a member of another Cluster, and a source that is not an active registered WireGuard Node. The Gateway identifies the requester by the WireGuard source address that delivered the query. A shared LAN subnet or an identity in the DNS message does not count.

The rule covers the Cluster TLD and every exact Cluster-scoped Route domain, also a domain outside the Cluster TLD. Other names keep their own answers:

| Name | Answer |
| --- | --- |
| A Node-scoped Route | The workload Node's WireGuard address |
| A custom proxy Route | The serving Node's WireGuard address |
| A name under an `app-dev` Node's TLD | That Node's WireGuard address, unless the TLD is also a Cluster TLD |
| `gateway.orbit` | The WireGuard address of the Node with the `gateway` role, active or converging |
| `metrics.orbit` | The same address as `gateway.orbit`, and only while a Node holds the `metrics` role |
| `reverb.orbit` | The Node that holds `websocket` |
| `analytics.orbit` | The Node that holds `analytics` |
| `collector.ai-router.orbit` | The AI router collector Node, while setup holds the collector. See [ai-router](/reference/ai-router). |

New Routes and Route domain changes cannot take these reserved platform names. Existing conflicts remain published until an operator changes or removes the Route; Doctor reports `route.domain_reserved`. Core reserves and publishes `gateway.orbit`, `metrics.orbit`, `reverb.orbit`, and `analytics.orbit`. The ai-router extension reserves and publishes `collector.ai-router.orbit` through [plug-in points](/reference/extensions#plug-in-points). An extension can publish a record only for a name that an extension reserves. `cli-proxy-api.orbit` is not reserved, and a custom proxy Route can publish it.

No Cluster or Node TLD can equal, contain, or sit below either private zone: `orbit` and the configured VPN domain. Overlap is checked at DNS label boundaries. For example, a VPN domain of `vpn.corp` or `VPN.CORP.` also reserves `corp`, `vpn.corp`, and `n1.vpn.corp`; `beast.orbit` is always reserved. Unrelated suffixes such as `test`, `orp`, and `notvpn.corp` remain valid. This comparison ignores case and the optional DNS root dot, including in existing configured domains. Relocating the `gateway` role republishes `gateway.orbit`. Renaming a Node does not change it.

Doctor does not report stored TLD overlaps. Operators must inspect existing Cluster and Node TLDs and correct conflicts. This input guard does not remove stored conflicts or change their DNS publication.

### Inspect the selection

Inspect the published catalog and the listener on the `vpn` Node. Then query from the Node whose answer you want to explain.

| Command | Expected result |
| --- | --- |
| `sudo cat /var/lib/orbit/private-dns/catalog.json` | `requesters` maps each registered WireGuard address to a Node id. `records` and `suffixes` hold the WireGuard answers. `overrides` lists LAN answers by `node:<id>`. |
| `systemctl is-active orbit-private-dns.socket orbit-private-dns.service` | Both units are active. |
| `sudo cat /var/lib/orbit/private-dns/catalog.json.loaded` | The digest equals `sudo sha256sum /var/lib/orbit/private-dns/catalog.json`, so the listener serves the published catalog. |
| `systemctl show -p ExecStart orbit-private-dns.service` | The service runs `serve.php` from the current release. |
| `ss -ulpn sport = :53` and `ss -tlpn sport = :53` | `orbit-private-dns` owns the WireGuard address on UDP and TCP port 53. dnsmasq owns `127.0.0.55:53`. |
| `dig +noall +answer @<vpn-dns-address> <route-domain> A` | The answer selected for the querying Node. Add `+tcp` to check TCP. |

| Observation | Meaning |
| --- | --- |
| `overrides` has `node:<id>` for the name | That Node gets the Router's LAN address. |
| The name is only under `records` or `suffixes` | The answer is the Router's WireGuard address. |
| The query source is not in `requesters` | The Gateway treats the source as unknown and answers with the WireGuard address. |

To remove wrong LAN intent, run the Node's provision operation again without `lan_ip`, or with the right one. The Gateway republishes the affected answers before the new Node, Cluster, Router, or Route state becomes authoritative. A refused Cluster or Router change stays refused.

An unreachable LAN address stays selected. Orbit does not fall back to WireGuard. HTTPS connections fail until you repair the LAN path, or remove the LAN address and provision again.

## Repair one peer

Run this command on the Gateway to update the resolver settings of one active managed Linux peer. It runs the [Node agent](/reference/node-agent#commands)'s `wireguard.repair-peer-dns` over SSH to the saved WireGuard address with the pinned identity. Roles and the running tunnel stay the same.

```bash
php artisan orbit:node-dns-repair <node-name>
```

After upgrading the Gateway, repair app-prod and verify separate resolver paths with these Gateway commands:

```bash
php artisan orbit:node-dns-repair app-prod
php artisan orbit:node-dns-check app-prod ai-cli-proxy.test --public-domain=example.com
orbit doctor --node=4 --family=node
```

`orbit:node-dns-check` is read-only. It runs uncached A queries through the peer's normal resolved policy. It succeeds only when the private name resolves on `orbit` and the public name resolves on another link or a global resolver. The output shows both answers and links. It refuses names that do not fit the private and public scopes. This verifies provider selection without stopping VPN DNS. `orbit node:converge app-prod --force` also applies the peer policy through the `peer-dns` footprint artifact.

DNS repair does not replace a WireGuard key. The separate [`node:wireguard-key`](/cli/node#orbit-nodewireguard-key) command only changes an unmanaged peer's desired public key and reconverges the hub; it refuses managed peers and leaves the peer's resolver policy unchanged. Use DNS repair for resolver faults, not key recovery.

The command refuses a missing or inactive Node, a [Node without roles](/reference/node-provisioning#nodes-without-roles), and the Node that holds `vpn`, before it opens SSH. It also refuses a peer without a complete managed WireGuard and SSH identity.

Record the [inspect](#inspect-a-peer) commands, `systemctl is-active wg-quick@orbit`, each role's service state, and a fingerprint of `wg show orbit public-key` before and after the repair. The DNS server, routing domains, hooks, and saved DNS state can change. The key fingerprint, role services, tunnel state, application placement, and `ip route` stay the same.

The repair takes `/run/lock/orbit-wireguard-peer.lock`, the lock that peer convergence also takes, and validates the proposed configuration. It backs up managed files and records a `dns-repair-v2` transaction in `/etc/wireguard/.orbit.peer-transaction`. Then it writes the hooks and DNS state and applies the resolver settings. A refusal before publication leaves no candidate file. Repeating the command gives the same result.

On the `orbit` link, it changes DNS servers and routing domains and sets `DefaultRoute=no`. It preserves LLMNR, mDNS, DNSSEC, DNS over TLS, and negative trust anchors. When it migrates an old underlay selection, networkd restores the uplink configuration.

Each failure reports one code without remote command output. The agent refuses with a `wireguard.*` code, and the Gateway reports the `vpn.*` code of the same name.

| Failure code | Result and recovery |
| --- | --- |
| `node.dns_repair_missing`, `node.dns_repair_inactive` | Nothing changes. Correct the Node name, or restore the Node through its own lifecycle. |
| `node.dns_repair_operator_owned`, `node.dns_repair_vpn_server`, `node.dns_repair_platform_unsupported`, `node.dns_repair_identity_missing` | The Node is outside this command. Use its own resolver or provisioning workflow. |
| `vpn.peer_dns_busy` | Another peer operation holds the lock. Wait, then retry. |
| `vpn.peer_recovery_pending` | Another peer operation owns the saved candidate or backup. Keep the recovery files and finish that operation first. |
| `vpn.peer_dns_state_unsupported`, `vpn.peer_dns_candidate_invalid` | Nothing is published. Inspect `orbit.conf` and `orbit.dns-link`, then fix the peer through normal provisioning. |
| `vpn.peer_dns_apply_failed` | The command restored the previous files and resolver selection. Fix the DNS or systemd-resolved fault, then retry. |
| `vpn.peer_dns_recovery_failed` | Recovery files stay under `/etc/wireguard`. Keep them, fix the fault, and retry the same repair. The retry restores the previous state first. |
| `vpn.peer_dns_uplink_missing` | The previous DNS state stays or is restored. Configure a non-Orbit per-link or global DNS server, then retry. |
| `vpn.peer_dns_repair_failed`, `vpn.configuration_invalid` | The command reports no success. Fix SSH reachability, the agent, or the Gateway VPN configuration, then retry. |

Peer convergence and repair save the live WireGuard configuration, DNS state, and service state before they publish. The native publication command restores that state when it fails, without resetting unrelated link settings. Recoverable admission retains a successful publication until its continuation commits; a failed continuation requests rollback over its last verified connection. A lost publication result fails for inspection and is not retried. Recovery files under `/etc/wireguard` mean the last operation did not finish. Keep them for diagnosis. Do not edit `/etc/wireguard/orbit.key`, replace a private key, or restart the tunnel to repair DNS.

Peer publication keeps its complete snapshot in the root-owned, mode-0600 `/etc/wireguard/.orbit.peer-transaction` JSON record. Commit removes that single record after verifying the live public key. Rollback stops the current tunnel before restoring the saved files, then restores its active and enabled state, live DNS servers, routing domains, resolver flags, and DNS symlink if present. This also restores an operator's DNS selection when no saved DNS-link file exists.

The record must fit the same 2 MiB limit used to read it; an oversized snapshot refuses before publication. DNS repair refuses a peer publication record. Peer publication and rollback leave older or unrecognized recovery artifacts in place for inspection.

## Why it works this way

These reasons explain the design. Check them before you propose a change.

### Split private DNS on peers

Nick approved split private DNS on 2026-10-19. Public names must not depend on the VPN Node. The Gateway owns the private namespace, so it renders and reconciles the routing domains from its records. One renderer serves provision, repair, scheduled reconciliation, and Doctor. The scheduled pass keeps fleet SSH out of record mutations. It retries unreachable peers independently.

The former `~.` default is rejected because a VPN DNS outage also breaks public lookups. A VPN-first, public-second fallback chain is rejected because systemd-resolved stays on the second server after failover, where private names remain broken. Static hosts are rejected because Routes and Router placement change and TLDs need DNS. The cost of split DNS is propagating private namespace changes and respecting the 512-domain bound.

### Suffix-only routing on the Gateway machine

The Gateway repairs VPN DNS, so it must resolve ordinary names while VPN DNS is down. It therefore routes only the private domain to VPN DNS. `~.` on the Gateway machine is rejected for that reason. Resolving private names inside the CLI is rejected, because every other tool on the machine would still fail. Static `/etc/hosts` entries are rejected, because the records move with their roles.

### Router LAN answers from registered intent

A registered LAN address is operator intent. The Gateway selects the address centrally from registered Node data. Returning a LAN address to every client is rejected, because remote clients cannot reach that LAN. Detecting reachability or roaming on each client is rejected, because it needs client monitoring and automatic path changes. An unreachable LAN path shows as a failure instead of a silent WireGuard fallback.

### A listener release from the Gateway

The listener needs no framework: it reads a JSON catalog and answers DNS messages. A release built from the Gateway's own code reaches the `vpn` Node with the next publication, so the listener never runs stale code.

Syncing a full checkout on the `vpn` Node is rejected, because every deploy would then need GitHub, Packagist, and a Composer install there. The socket unit keeps the DNS address open during a restart, so a restart never stops VPN DNS. Two listeners that hand over with `SO_REUSEPORT` are rejected, because systemd runs one main process per service, and the kernel drops datagrams queued on a closing socket.

### Uplink resolvers for the backend

The backend must not forward to the systemd-resolved stub on `127.0.0.53` or `127.0.0.54`, because that path can loop back once the VPN DNS listener is bound. It forwards to the uplink resolvers instead, which a default-deny host firewall already admits.
