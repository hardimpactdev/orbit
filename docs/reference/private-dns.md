---
title: "Private DNS"
description: "How a managed Node selects its resolver, how the vpn Node answers private names from one rendered dnsmasq configuration, and how to inspect and repair one peer."
covers:
  - apps/gateway/app/Infrastructure/AppDev/{DevelopmentDnsConfigRenderer,PrivateDnsPublication,PrivateDnsRevision,SwampPrivateDnsManager,DnsAddress,NativeClusterRouterDnsSelectionReconciler}.php
  - apps/gateway/app/Domain/AppDev/{ClusterTldTargets,PrivateDnsState,PrivateDnsManager,DnsPublishProjection,ClusterRouterDnsSelectionReconciler}.php
  - apps/gateway/app/Actions/AppDev/AdmitPrivateDnsPublicationAction.php
  - apps/gateway/app/Infrastructure/WireGuard/{NativeWireGuardPeerConverger,NativeWireGuardPeerDnsRepairer,PeerDnsPolicy}.php
  - apps/gateway/app/Infrastructure/Gateway/GatewayPrivateDnsResolver.php
  - apps/gateway/app/Domain/Nodes/GatewayPrivateDnsRoute.php
  - apps/gateway/app/{Actions/Fleet/AdmitPeerDnsAction,Console/Commands/ReconcilePeerDnsCommand}.php
---

# Private DNS

Orbit VPN DNS answers private names, such as Route domains and `gateway.orbit`, and forwards every other query to ordinary resolvers. It is one stock dnsmasq on the Node with the `vpn` role. The Gateway renders the complete private DNS from its database, and one Ansible phase writes it. Managed Linux Nodes route only Orbit names to it by default. This page explains resolver selection, the Gateway machine's resolver, the vpn Node's dnsmasq, the answers it gives, and how to inspect or repair one peer.

[ADR 0248](/decisions/0248-render-private-dns-as-desired-state) holds the design and replaces the plan of [ADR 0219](/decisions/0219-answer-cluster-names-from-a-dns-on-the-router). Every caller gets the same answer: a Cluster name resolves to the Router's WireGuard address, also for a Cluster member on the Router's LAN.

Each Route, Node, Cluster or role change stores its rows first, then publishes the whole state through the shared `dns-publish` child of its native run. A Gateway code path that must have DNS live before it goes on admits a `dns.publish` operation and waits for its end. Private DNS publication starts no other Operation.

## Resolver selection

Managed Linux peers send only Orbit names to private DNS. The Gateway renders the private VPN domain, every Cluster TLD, every Node TLD, and every private Route domain outside those suffixes. It removes duplicates and suffixes already covered by another suffix. All other names use the machine's normal provider or DHCP resolver. A VPN DNS outage therefore leaves public resolution available while that resolver and network work.

| Peer configuration | DNS server | Routing domains |
| --- | --- | --- |
| Default | Orbit VPN DNS | The rendered Orbit private-name set, such as `~orbit ~test` |
| Per-Node `--dns-server` | A usable WireGuard-subnet address that answers Orbit names | The same complete private-name set |

A routing domain for an outside-TLD Route, such as `~app.example.com`, also captures descendants. It creates no private wildcard answer: VPN DNS still forwards descendants that have no private record. Resolver routing cannot select only the exact name. A private Route on an apex such as `example.com` also moves every descendant lookup to VPN DNS; choose a narrower name when those descendants must keep public resolution.

Provisioning, `node:converge`, and [repair](#repair-one-peer) apply the render. A peer needs `fleet.peer_dns` only while its policy differs from the one its last successful `fleet.peer_dns` run applied. The digest covers the effective DNS server, peer public key and routing domains.

No schedule admits peer DNS. After a policy change, run `orbit:peer-dns-reconcile`, which admits every peer whose policy differs. A failed peer stays changed until you fix the cause and run it again.

The Gateway starts each `fleet.peer_dns` intent directly in Swamp. Its native workflow runs on Node 209 and reaches the peer over SSH.

The run keeps the `PostUp` hook and writes the DNS marker; both steps change nothing when the files already match. One guarded command then sets the server, routing domains and `default-route no` on `orbit`, unless the live link already holds them.

The run ends by resolving `gateway.orbit`, the private name the Gateway puts in the intent, which must answer through `orbit`, and `example.com`, which must not. A succeeded Operation records the applied server and domains. A policy change waits at most one minute plus queue and traversal time. Host drift with an unchanged policy is not detected by a schedule; doctor reports it, and a reconcile repairs it. Record changes do not block on fleet SSH.

Peer DNS reconciliation is serialized by the native behavior on each target Node. It holds no Gateway target lock. The agent uses a shared WireGuard peer lock for inspection and the existing exclusive peer lock for repair, including against native peer convergence.

Private Route, Cluster TLD, and Node TLD writes validate names in a serialized database transaction. A change is refused only if the rendered count increases beyond 512 domains. Deletion or rollback that keeps or reduces an already oversized count is allowed. The renderer also refuses invalid stored names or more than 512 domains before peer mutation, never truncating or using `~.`. systemd 259 allows 1,024 domains per link. Orbit reserves half that capacity so a complete replacement can allocate new domains before resolved removes old ones.

Doctor reports `node.private_dns_uplink_missing` when no usable non-Orbit DNS server is configured. Reconcile reports `vpn.peer_dns_uplink_missing` by Node name. Doctor reports `node.private_dns_policy_mismatch` for drift and `node.private_dns_unreachable` when a private DNS query fails or returns the wrong Gateway address. `node.public_dns_unreachable` reports a failed uncached public lookup or one sent over `orbit`. Global `resolved.conf` DNS servers are valid public resolvers. A failed read is unverifiable (`node.inspection_failed`), not policy drift. Namespace changes can report temporary mismatch until the next successful scheduled pass.

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

## The vpn Node DNS

The vpn Node runs the stock `dnsmasq.service`. It binds the VPN DNS address with `listen-address` and `bind-dynamic`, and it answers private names from three files that the `dns-publish` phase writes. The Gateway sends the publication to the `vpn` Node over pinned SSH, including when both roles share one Node.

| File | Content | On change |
| --- | --- | --- |
| `orbit.conf` | `listen-address`, `bind-dynamic`, `no-resolv`, `no-hosts`, `local-ttl=30`, the uplink `server=` lines, and one `address=/.TLD/ADDRESS` with `local=/TLD/` for each TLD | dnsmasq restarts |
| `orbit.hosts` | One `ADDRESS NAME` line for each record | SIGHUP |
| `orbit.servers` | For each exact name outside every TLD, `server=/NAME/` and `server=/*.NAME/#` | SIGHUP |
| `orbit.revision` | The revision of the installed state | None |

### What the Gateway renders

The Gateway renders data, not text: the listen address, the TLDs with their addresses, and the records with their addresses. A record below a TLD appears only when its answer differs from the TLD's. A record outside every TLD, such as a Route on its own domain or a reserved name, carries `pair`: the phase gives it local NODATA for other record types and sends queries for its children to the public resolver. The renderer checks every name against lowercase DNS labels and every address against IPv4 before it emits them, because `dnsmasq --test` does not check the hosts and servers files.

The render reads stored rows only. A change stores its state before it asks for a render. A Cluster change that publishes before it commits stores its pending TLD, state or Router on the Cluster row. Each source of change (`cluster:update`, a Router selection) keeps its own hold and releases only that one when it ends. A hold that nothing cleared expires after an hour.

### Revision and parallel publications

Every render takes a revision, `max(stored + 1, now in microseconds)`, under a lock in the transaction that reads the rows. The Node installs a state only when its revision is newer than `orbit.revision`. Two parallel publications are therefore safe: the newest render wins, and it holds every change stored before it. A restore, such as a failed Router change, uses a render with a newer revision than the forward one.

A publication admitted after that holds a newer revision still, and the Node then skips the restore as superseded. So after the Gateway stores the failure of a Router change or an Instance transfer, it queues one fresh publication, and the newest render holds the rolled-back rows.

### The phase

The `dns-publish` phase holds `/run/lock/orbit-dnsmasq.lock` for its whole run and any recovery. The holder expires after 420 seconds, longer than the 300-second timeout of the Swamp step plus the 90-second cutover budget. The next publication waits up to 150 seconds. The numbers are in `swamp/ansible/vars/dns.yml`, and a test keeps them consistent.

The phase first prepares the change:

1. It skips a revision that is not newer than `orbit.revision`, and succeeds with `dns.revision_superseded`.
2. It renders the files in a temporary folder and runs `dnsmasq --test` on them. A refusal fails with `dns.config_invalid` and installs nothing.
3. It takes a snapshot of the files, the unit states and the listen address holders. A snapshot that exists stays as it is.
4. It writes `reload.pending`, then installs the files whose content changed, each in one rename.

Then it applies the change. It compares `orbit.conf` with `orbit.conf.loaded`, the copy dnsmasq last started with:

- An equal file gets SIGHUP. A `reload.pending` from a run that was killed after the install also gets one.
- A changed file, a missing copy or a dnsmasq that is not running gets a restart.
- For a restart, the phase deletes the copy first. Then one detached command stops the old listener and restarts dnsmasq.
- The phase writes the copy again after the restart succeeded. A failed run deletes it, so the next publication restarts dnsmasq.

Last it checks the result:

1. It asks the running dnsmasq on the VPN address for the reserved names and one name per TLD.
2. For each exact Route it asks for the A answer, for NODATA on AAAA, and for a forwarded child name.
3. It asks `127.0.0.55`, then the Node's normal resolver for a private name and the AAAA of an exact name.
4. A public name only logs a warning, so an upstream outage does not roll back a publication.
5. A mismatch restores the snapshot and fails with `dns.check_failed`.
6. It writes `orbit.revision`, then commits: the new files become the last good set, and `reload.pending` and the snapshot go.

The recovery after a failure that changed the Node puts the snapshot back and starts the old listener units that ran. It asks the restored resolvers over UDP and TCP, on the VPN address and on `127.0.0.55`. The result then says `recovered: true`. When a task or an answer fails, it says `recovered: false` with `dns.rollback_failed` and the cause, and the snapshot stays. The check shows that the restored resolvers answer, not what they answer.

An exact Route change reloads and drops no query. A TLD change restarts dnsmasq, and every name stops for the length of the restart. On Ubuntu 26.04, five dnsmasq restarts under a query every 66 ms on average lost no query, and the first cutover from a fake old listener lost none of 685 queries.

The first publication on a Node that still runs the old listener moves the listener's files to `previous/legacy/` and deletes `/var/lib/orbit/private-dns`, the listener release. A rollback after a successful cutover restores the last good rendered files. It cannot restart the old listener, because its code is gone. A cutover that fails still restores the old listener from its snapshot.

### Upstream resolvers

dnsmasq sets `no-resolv` and forwards only to the IPv4 resolvers that the `vpn` Node itself uses on its uplink. The phase reads them from `/run/systemd/resolve/resolv.conf`. When that file lists none, it reads the DHCP lease of the default-route interface. It skips loopback addresses and the `orbit` interface, so forwarding never returns to VPN DNS. When it finds no resolver, it uses `1.1.1.1` and `8.8.8.8`.

The vpn Node's own resolver keeps `127.0.0.55`: its `systemd-resolved` drop-in sends every name there, so `orbit.conf` listens on that address too. The file also keeps `domain-needed`, `bogus-priv` and `local=/orbit/` from the old backend, and the Gateway renders `vpn.orbit`.

The phase also moves the stock `/etc/dnsmasq.d/ubuntu-fan` snippet to `/var/lib/orbit/dnsmasq/disabled`, because it would add listen addresses beside `bind-dynamic`.

### Publication target

The Gateway finds the `vpn` and `gateway` Nodes by their role. A holder can be `active` or `provisioning`, and its role assignment can be `active` or `provisioning`. An `active` Node wins over a `provisioning` Node. A `failed` or `removing` Node is never a holder.

This matters during these commands:

- `node:add` on an existing `gateway` or `vpn` Node marks the Node `provisioning` and keeps its assignments active.
- `node:role:add NODE ROLE --converge` marks the assignment `provisioning` and keeps the Node active.

In both cases, publication still finds the `vpn` Node and publishes there. The reserved records also keep their holder: `gateway.orbit`, `metrics.orbit`, `reverb.orbit`, `analytics.orbit`, the analytics tracking hosts, and `collector.ai-router.orbit`.

## Answers

Private DNS answers select an address. They do not change how application traffic is routed. [Routes](/reference/routes#set-up-private-traffic) explains how a resolved Route reaches its workload.

Every caller gets the same answer. A Cluster name resolves to the Router's WireGuard address, also for a Cluster member that shares the Router's LAN. Such a member reaches the Router through the WireGuard hub. The Router's LAN address is never published.

A Route change stores its rows, and the render follows them: a Route that is retiring or removed leaves the next render, and a failed Route keeps its record until an operator removes it. A domain inside a TLD needs no record when the TLD already gives its address.

The TLD of an active Cluster with a Router answers with that Router. A Node TLD answers with that Node. Other names keep their own answers:

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

### Inspect the DNS

Inspect the files and the daemon on the `vpn` Node. Then query from the Node whose answer you want to explain.

| Command | Expected result |
| --- | --- |
| `sudo cat /etc/dnsmasq.d/orbit.conf` | The listen address, one `address=/.TLD/ADDRESS` pair for each TLD, and the uplink servers |
| `sudo cat /etc/dnsmasq.d/orbit.hosts` and `orbit.servers` | One hosts line for each record, and the NODATA pair for each exact name outside a TLD |
| `sudo cat /etc/dnsmasq.d/orbit.revision` | The revision of the last installed state |
| `systemctl is-active dnsmasq` | `active` |
| `ss -ulpn sport = :53` and `ss -tlpn sport = :53` | `dnsmasq` owns the VPN DNS address on UDP and TCP port 53 |
| `dig +noall +answer @<vpn-dns-address> <route-domain> A` | The address that the render gives. Add `+tcp` to check TCP. |
| `dig +noall +comments @<vpn-dns-address> <route-domain> AAAA` | `NOERROR` with no answer for an exact Route |

| Observation | Meaning |
| --- | --- |
| The name is missing from `orbit.hosts` and sits below a TLD | The TLD wildcard answers it |
| `orbit.revision` is older than the last publication | The publication failed or is still queued. Read its `dns.publish` Operation or the owning Operation. |
| The query source does not matter | Every caller gets the same answer |

To republish the state, run the owning command again, or `node:converge` on the vpn Node. A failed publication restores the snapshot, so the Node keeps the files it had.

## Repair one peer

Provisioning an app-dev Node runs the [Node agent](/reference/node-agent#commands)'s `wireguard.repair-peer-dns` over SSH to the saved WireGuard address with the pinned identity. It updates the peer's resolver settings. Roles and the running tunnel stay the same.

DNS repair does not replace a WireGuard key. The separate [`node:wireguard-key`](/cli/node/wireguard-key) command only changes an unmanaged peer's desired public key and reconverges the hub; it refuses managed peers and leaves the peer's resolver policy unchanged. Use DNS repair for resolver faults, not key recovery.

The repair can change the DNS server, routing domains, hooks, and saved DNS state. The key fingerprint, role services, tunnel state, application placement, and `ip route` stay the same.

The repair takes `/run/lock/orbit-wireguard-peer.lock`, the lock that peer convergence also takes, and validates the proposed configuration. It backs up managed files and records a `dns-repair-v2` transaction in `/etc/wireguard/.orbit.peer-transaction`. Then it writes the hooks and DNS state and applies the resolver settings. A refusal before publication leaves no candidate file. Repeating the repair gives the same result.

On the `orbit` link, it changes DNS servers and routing domains and sets `DefaultRoute=no`. It preserves LLMNR, mDNS, DNSSEC, DNS over TLS, and negative trust anchors. When it migrates an old underlay selection, networkd restores the uplink configuration.

Each failure reports one code without remote command output. The agent refuses with a `wireguard.*` code, and the Gateway reports the `vpn.*` code of the same name.

| Failure code | Result and recovery |
| --- | --- |
| `vpn.peer_dns_busy` | Another peer operation holds the lock. Wait, then retry. |
| `vpn.peer_dns_unreadable` | Inspection could not read the saved or live DNS state. Check the peer's files and resolver service, then retry. |
| `vpn.peer_recovery_pending` | Another peer operation owns the saved candidate or backup. Keep the recovery files and finish that operation first. |
| `vpn.peer_dns_state_unsupported`, `vpn.peer_dns_candidate_invalid` | Nothing is published. Inspect `orbit.conf` and `orbit.dns-link`, then fix the peer through normal provisioning. |
| `vpn.peer_dns_apply_failed` | The repair restored the previous files and resolver selection. Fix the DNS or systemd-resolved fault, then retry. |
| `vpn.peer_dns_recovery_failed` | Recovery files stay under `/etc/wireguard`. Keep them, fix the fault, and retry the same repair. The retry restores the previous state first. |
| `vpn.peer_dns_uplink_missing` | The previous DNS state stays or is restored. Configure a non-Orbit per-link or global DNS server, then retry. |
| `vpn.peer_dns_repair_failed`, `vpn.configuration_invalid` | The repair reports no success. Fix SSH reachability, the agent, or the Gateway VPN configuration, then retry. |

The network part of `node.converge` and `fleet.peer_dns` keep no snapshot. They write Gateway-rendered files with official cfgmgmt behaviors and stop on the first failed step. A rerun writes the same files again.

Agent-run repair saves the live WireGuard configuration, DNS state, and service state before it publishes. The native publication command restores that state when it fails, without resetting unrelated link settings. Recoverable admission retains a successful publication until its continuation commits; a failed continuation requests rollback over its last verified connection. A lost publication result fails for inspection and is not retried. Recovery files under `/etc/wireguard` mean the last operation did not finish. Keep them for diagnosis. Do not edit `/etc/wireguard/orbit.key`, replace a private key, or restart the tunnel to repair DNS.

Peer publication keeps its complete snapshot in the root-owned, mode-0600 `/etc/wireguard/.orbit.peer-transaction` JSON record. Commit removes that single record after verifying the live public key. Rollback stops the current tunnel before restoring the saved files, then restores its active and enabled state, live DNS servers, routing domains, resolver flags, and DNS symlink if present. This also restores an operator's DNS selection when no saved DNS-link file exists.

The record must fit the same 2 MiB limit used to read it; an oversized snapshot refuses before publication. DNS repair refuses a peer publication record. Peer publication and rollback leave older or unrecognized recovery artifacts in place for inspection.

## Why it works this way

These reasons explain the design. Check them before you propose a change.

### Split private DNS on peers

Nick approved split private DNS on 2026-10-19. Public names must not depend on the VPN Node. The Gateway owns the private namespace, so it renders and reconciles the routing domains from its records. One renderer serves provision, repair, scheduled reconciliation, and Doctor. The scheduled pass keeps fleet SSH out of record mutations. It retries unreachable peers independently.

The former `~.` default is rejected because a VPN DNS outage also breaks public lookups. A VPN-first, public-second fallback chain is rejected because systemd-resolved stays on the second server after failover, where private names remain broken. Static hosts are rejected because Routes and Router placement change and TLDs need DNS. The cost of split DNS is propagating private namespace changes and respecting the 512-domain bound.

### Suffix-only routing on the Gateway machine

The Gateway repairs VPN DNS, so it must resolve ordinary names while VPN DNS is down. It therefore routes only the private domain to VPN DNS. `~.` on the Gateway machine is rejected for that reason. Resolving private names inside the CLI is rejected, because every other tool on the machine would still fail. Static `/etc/hosts` entries are rejected, because the records move with their roles.

### One answer for every caller

dnsmasq cannot choose an answer by the source of a query: `localise-queries` keys on the receiving interface, and every WireGuard query arrives on `orbit`. A custom listener could, but it needs its own codec, sockets, release and requester catalog. [ADR 0248](/decisions/0248-render-private-dns-as-desired-state) removes them. The cost is that a Cluster member on the Router's LAN reaches the Router through the WireGuard hub.

A Cluster DNS on each Router that answers the LAN address, with a member step that selects it first, is rejected. It needs a unit and a phase on every Router, a systemd-resolved delegate on every member, and automatic recovery after a fallback, and a failure of one Router's dnsmasq stops its names.

### Complete state, newest revision wins

Each publication writes the whole current state, so two parallel publications cannot lose each other's change. A delta publication needs a merge of the pieces, and that merge does not fit one Ansible phase. The revision keeps a slow older run from overwriting a newer one.

### Uplink resolvers for the backend

dnsmasq must not forward to the systemd-resolved stub on `127.0.0.53` or `127.0.0.54`, because that path can loop back once dnsmasq owns the VPN DNS address. It forwards to the uplink resolvers instead, which a default-deny host firewall already admits.
