---
title: "ADR 0248: Render private DNS as desired state"
sidebarTitle: "0248 Private DNS desired state"
description: "In progress. Option A is chosen: every publication renders the complete private DNS from the Gateway database, and one Ansible phase writes a stock dnsmasq on the vpn Node. The listener, its catalog and the three-way merge go."
---

# ADR 0248: Render private DNS as desired state

Every DNS publication renders the whole private DNS from the Gateway database. One Ansible phase writes the result to a stock dnsmasq on the `vpn` Node. There is no custom listener, no delta piece and no three-way merge.

## Status

In progress.

Nick approved the direction on 2026-11-07 and chose option A the same day. The build follows the sections below.

Principle: [Deterministic first](/mission#principles) and [Lean](/mission#principles). This ADR replaces [ADR 0219](/decisions/0219-answer-cluster-names-from-a-dns-on-the-router) as a whole: no Cluster DNS, no member step, no fallback.

Decisions of 2026-11-07:

- Option A: all Routes in one dnsmasq file on the vpn Node.
- The AAAA fix is taken: NODATA for exact Routes, no upstream IPv6 leak.
- Rollback is the previous rendered configuration. The phase keeps the last good files and restores them when a check fails. A rerun with them is the rollback. There is no rollback bridge.
- The render reads only stored rows. Every pending state (Cluster TLD, Router and LAN address changes, a pending Node) is stored before the publication.
- Answers per caller are gone. A WireGuard member gets the Router's WireGuard address, and a LAN member reaches the Router through the hub.

## Context

A PHP listener on the `vpn` Node answers private names and forwards the rest to a dnsmasq backend ([Private DNS: The listener](/reference/private-dns#the-listener)). Each Route or Node change publishes its own piece of the records and of the requester catalog. A three-way JSON merge (`mutation-command/dns.ts`) joins parallel pieces. That merge does not port to an Ansible phase. The listener side is about 3,000 lines of PHP plus its tests.

Swamp orchestrates and Ansible acts ([Swamp shape](https://github.com/hardimpactdev/orbit/blob/main/swamp/docs/swamp-shape.md)). A state that the Gateway renders in full needs no merge. A phase writes it or finds it already written.

Live data, read on 2026-11-07 with the `route:list` and `cluster:list` Orbit tools:

- 141 Routes, 140 active. 123 active Routes sit under the TLDs `test` and `prod`. 17 sit outside any TLD: 12 public names and 5 under `.orbit`.
- Cluster `dev` has TLD `test`, Router beast, and LAN members beast, sabre and shark.
- Cluster `prod` has no TLD. Its only member is its Router. `prod` is a Node TLD of app-prod.

### What plain dnsmasq keeps

Checked with dnsmasq 2.92 on scratch ports. Nothing live was touched.

| The listener does | Plain dnsmasq | Result |
| --- | --- | --- |
| Gateway, metrics, reverb, analytics and collector names | A line in `orbit.hosts` | Same A answer |
| Cluster and Node TLD names | `address=/.tld/ADDRESS` with `local=/tld/` | Same A answer. Other types give NODATA. |
| Nested TLD | dnsmasq picks the more specific one | The listener picks the first listed suffix. They differ only for a Node TLD inside a Cluster TLD. |
| Exact Route outside any TLD, A | A line in `orbit.hosts` | Same |
| Same name, other types (AAAA, MX) | `server=/name/` with no address, in `orbit.servers` | NODATA. Today the backend forwards AAAA upstream, so a public IPv6 address can leak. |
| Names below an exact Route use the public resolver | `server=/*.name/#` in `orbit.servers` | Same |
| Add or remove an exact Route | `SIGHUP` re-reads both files | No restart. The answer, NODATA and the child forward all change on SIGHUP alone. |
| Add, change or remove a TLD | A new `orbit.conf` and a restart | A regression: every name stops during the restart. A TLD changes rarely. |
| TTL of 30 seconds, which the 31-second Route grace wait relies on | `local-ttl=30` in `orbit.conf` | Same |
| Uplink resolvers, bind address, the `ubuntu-fan` snippet | The phase reads the uplink resolvers, binds with `listen-address` and `bind-dynamic`, and moves the snippet away | Same, as [Private DNS](/reference/private-dns#upstream-resolvers) describes |
| Peers send only Orbit names to private DNS | The Gateway renders the routing domains and `fleet.peer_dns` applies them | Unchanged |
| **Answers per caller** | dnsmasq cannot. `localise-queries` keys on the receiving interface, and every WireGuard query arrives on `orbit`. | **Lost on the vpn Node** |

Today the per-caller rule gives the Router LAN address to an eligible member: an active Cluster member with a LAN address, a WireGuard address and a key, the Router included. It applies to the Cluster TLD and to Cluster-scoped Route names. In practice that is beast, sabre and shark asking for `dev` names. Everyone else gets the Router WireGuard address.

## Decision

Nick chose option A on 2026-11-07: all Routes in one dnsmasq file on the vpn Node.

### One render

The Gateway renders the complete state from the database as data, not as text: the listen addresses, the TLDs with their addresses, and every record with its address and whether it needs the NODATA pair. `DevelopmentDnsConfigRenderer` becomes this data builder. It loses its override and pending-Node parameters, `catalog()` and `registeredRequesters()`. It checks every name and address against a strict grammar (lowercase DNS labels, IPv4) before it emits them, as [ADR 0219](/decisions/0219-answer-cluster-names-from-a-dns-on-the-router#record-boundary) requires. `dnsmasq --test` checks only `orbit.conf`, not the hosts and servers files.

The render is a function of rows. A change stores the row state that decides inclusion, such as a Route `retiring`, a Cluster TLD, a Router, a LAN address or a pending Node, before the owning kind is admitted. The render is taken at admission and carried in the intent.

### One revision

- The revision is `max(stored + 1, now in microseconds)`. The Gateway takes it in the same transaction that reads the rows. A database restore cannot move it below an installed revision, because the clock keeps going.
- The Node holds the installed revision in `orbit.revision`, written last.
- Parallel publications are safe: the newest render wins, and it contains every change recorded before it. A failed run does not undo its change by replaying an old render. The Gateway projects the failure into the rows, and the next publication renders them.

### One phase

`swamp/ansible/dns-publish.yml` renders `orbit.conf`, `orbit.hosts` and `orbit.servers` from the data with Jinja templates. It runs under the existing `/run/lock/orbit-dnsmasq.lock` for the whole read, compare and install:

1. Skip when the revision is not newer than `orbit.revision`. This is a successful skip with the code `dns.revision_superseded`.
2. Validate the files in a temporary folder with `dnsmasq --test` on a temporary `orbit.conf` that names the temporary hosts and servers files. A refusal fails the phase with `dns.config_invalid`, and nothing is installed.
3. Save the installed files as the last good set. On the first run the installed set is the Node's current dnsmasq files, including the files of the old listener setup. Then install the files whose content changed, each in one rename.
4. Reload with SIGHUP when `orbit.conf` equals `orbit.conf.loaded`, the copy dnsmasq last started with. Otherwise restart, then copy it to `orbit.conf.loaded`. A restart that failed on an earlier run is repaired by the next newer revision.
5. Check with `dig`: each reserved name, one name per TLD, and for each exact Route its A answer, AAAA NODATA and a child name that is forwarded. When the check fails, restore the last good files, reload or restart dnsmasq to match them, and fail the phase with `dns.check_failed`.
6. Write `orbit.revision`.

A Molecule scenario tests the phase and its templates. Publications to one Node run one at a time. Each takes a few seconds, and an older one skips at step 1.

### One owner

Route, Node, Cluster, metrics and role workflows call the shared `dns-publish` child with the render in the intent. `route-dns-publish`, `route-create-dns`, `node-private-dns` and the compose step go.

Gateway PHP no longer publishes over SSH. The paths that still publish synchronously (Cluster actions, `orbit:node-provision`, the Route projectors and the ai-router extension) call `PrivateDnsManager::converge()`. It admits a `dns.publish` operation with a fresh render and waits for its end. One kind then writes private DNS, and `DnsmasqPrivateDnsManager` goes.

### What gets deleted

- The listener, its release, sockets and units: `PrivateDnsMessageCodec`, `PrivateDnsTransportServer`, `PrivateDnsInheritedSockets`, `PrivateDnsSocketBinder`, `PrivateDnsListener*`, `PrivateDnsRequestHandler`, `SocketPrivateDnsUpstream`, `ServePrivateDnsCommand`, `NodePrivateDnsListenerFootprint`, `PrivateDnsFootprintArtifact`, `resources/private-dns/serve.php`, and the `php:8.5` build of the `vpn` profile that only the listener needs.
- The catalog and the requesters: `PrivateDnsAnswerCatalog`, `FilePrivateDnsCatalogStore`, the answer selectors and cache, `*DnsRequesterResolver`, `DnsRequester`, `catalog.json`, `catalog.json.loaded`, and the `overrides` and `answers()` code in `ClusterRouterDnsSelection`, with `ClusterRouterDnsSelectionReconciler` and the DNS part of `ClusterRouterTransition`.
- The publishers and the merge: `DnsmasqPrivateDnsManager` (744 lines), `VpnDnsmasqBackendListen`, `mutation-command/dns.ts` and its test, and the workflows `route-dns-publish`, `route-create-dns` and `node-private-dns`.
- The Router LAN DNS firewall families of ADR 0219 step 1, which nothing then uses.
- About 3,000 lines of PHP and 500 lines of workflow YAML, with their tests. The build PR lists the exact files.

### Proof

1. Molecule tests the phase and its templates on a scratch Node: every row of the table above, the reload and restart choice, the superseded skip, and the restore of the last good files.
2. A rehearsal on a task VM fleet runs the whole flow, from a Route change to the answers.
3. On the live vpn Node, a comparison script queries every private name, for A and AAAA, against the listener and a second dnsmasq with the rendered files on a spare port. It prints each difference name by name. The only differences allowed are the AAAA fix and nested TLDs.
4. The cutover moves dnsmasq to the VPN DNS address, stops the listener, and asserts the answers, while someone watches. The listener code is already gone, so the rollback is the last good set the phase saved before its first install.
5. The restart gap of a TLD change is measured with a query loop, and the number goes into this ADR.

## Rejected alternatives

- **B: delegate each TLD to its Router or owning Node.** The vpn dnsmasq would list only TLDs, reserved names and Routes outside any TLD, and send `server=/TLD/ADDRESS` to each Router. Every name under a TLD then fails when its Router or the Router's dnsmasq fails, and nothing falls back to WireGuard answers. It needs `dnsmasq-base`, a unit, a phase and a digest on every Router, port 53 rules from the vpn Node, and a listener on the Router WireGuard address. It does not give LAN answers unless the ADR 0219 member step is built too. A can move to B by one publication that swaps a Cluster's names for one `server=/TLD/ROUTER` line.
- **A second dnsmasq on the vpn Node for LAN members.** ADR 0219 rejected two listeners: members would select a server by identity again, and a membership change would need a peer DNS run.
- **A custom listener that keeps answers per caller.** It keeps the codec, the sockets, the release, the catalog and the merge, which is what this ADR removes.

## Consequences

- The listener, the catalog, the requesters and the three-way merge are gone. The vpn Node runs stock dnsmasq with three files.
- A WireGuard member gets the Router's WireGuard address, and beast, sabre and shark reach beast through the WireGuard hub instead of the LAN.
- AAAA for an exact Route gives NODATA. It no longer leaks a public IPv6 address.
- A TLD change restarts dnsmasq, so every name stops for the length of the restart. The measured gap goes into this section after the rehearsal.
- Parallel publications are safe because each one writes a whole state and the newest revision wins. A failed run leaves its rows in the database, and the next publication renders them.
- The rollback is the last good set that the phase saved before its first install.

## Affects

- Components: apps/gateway, swamp, apps/docs
- ADRs: replaces [0219](/decisions/0219-answer-cluster-names-from-a-dns-on-the-router) as a whole, and the Cluster DNS member step of [0226](/decisions/0226-split-private-dns-on-managed-peers)
- Detail: [Private DNS](/reference/private-dns), [Routes](/reference/routes), [Node provisioning](/reference/node-provisioning), and the chart `docs/cli/dns/publish.mmd`
- Verify: Molecule on the phase, a rehearsal on a task VM fleet, and a name-by-name comparison of old and new answers on the live vpn Node
