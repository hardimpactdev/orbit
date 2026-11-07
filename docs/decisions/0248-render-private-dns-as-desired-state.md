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

## What plain dnsmasq keeps

Checked with dnsmasq 2.92 on scratch ports. Nothing live was touched.

| The listener does | Plain dnsmasq | Result |
| --- | --- | --- |
| Gateway, metrics, reverb, analytics and collector names | `host-record=` in `orbit.conf`, or a line in `orbit.hosts` | Same A answer |
| Cluster and Node TLD names | `address=/.tld/ADDRESS` with `local=/tld/` | Same A answer. Other types give NODATA. |
| Nested TLD | dnsmasq picks the more specific one | The listener picks the first listed (sorted) suffix. They differ only for a Node TLD inside a Cluster TLD. [ADR 0219](/decisions/0219-answer-cluster-names-from-a-dns-on-the-router) accepts this. |
| Exact Route outside any TLD, A | A line in `orbit.hosts` | Same |
| Same name, other types (AAAA, MX) | `server=/name/` with no address, in `orbit.servers` | NODATA. Today the backend forwards AAAA upstream, so a public IPv6 address can leak, for the `.orbit` names too. This is a deliberate fix. |
| Names below an exact Route use the public resolver | `server=/*.name/#` in `orbit.servers` | Same |
| Add or remove an exact Route | `SIGHUP` re-reads both files | No restart. Checked: the answer, NODATA and the child forward all change on SIGHUP alone. |
| Add, change or remove a TLD | A new `orbit.conf` and a restart | A regression: the restart stops every name for a moment. Today only the backend restarts and the listener keeps answering. A TLD changes rarely. The rehearsal measures the gap. |
| TTL of 30 seconds, which the 31-second Route grace wait relies on | `local-ttl=30` in `orbit.conf` | Same |
| Uplink resolvers, bind address, the `ubuntu-fan` snippet | The phase reads the uplink resolvers on the Node, binds with `listen-address` and `bind-dynamic`, and moves the snippet away | Same, as [Private DNS](/reference/private-dns#upstream-resolvers) describes |
| Peers send only Orbit names to private DNS | The Gateway renders the routing domains and `fleet.peer_dns` applies them | Unchanged by both options |
| **Answers per caller** | dnsmasq cannot. `localise-queries` keys on the receiving interface, and every WireGuard query arrives on `orbit`. | **Lost on the vpn Node.** See the options. |

Today the per-caller rule gives the Router LAN address to an eligible member: an active Cluster member with a LAN address, a WireGuard address and a key, the Router included. It applies to the Cluster TLD and to Cluster-scoped Route names. An exact custom proxy record wins over it. In practice that is beast, sabre and shark asking for `dev` names. Everyone else gets the Router WireGuard address.

## Options considered

[Chart A](https://github.com/hardimpactdev/orbit/blob/main/docs/cli/dns/publish.mmd) shows the chosen flow. Option B stays here as a rejected alternative.

| | **A. All Routes in one file on the vpn Node** | **B. Delegate each TLD** |
| --- | --- | --- |
| Idea | The vpn dnsmasq answers every private name. | The vpn dnsmasq lists the TLDs, the reserved names and the Routes outside any TLD. It sends `server=/TLD/ADDRESS` to the Router (Cluster TLD) or to the owning Node (Node TLD). |
| Status | Chosen | Rejected for now |
| LAN clients get | The Router WireGuard address, like every caller. Beast, sabre and shark reach beast through the WireGuard hub, not the LAN. | With delegation alone, the same as A: the Router sees the vpn Node as the asker. LAN answers need the [ADR 0219](/decisions/0219-answer-cluster-names-from-a-dns-on-the-router) member step as well. Members then ask the Router LAN address first and the vpn Node second. |
| A Router is down | DNS is unaffected. Names of that Cluster still resolve and the connections fail, as today. Other Clusters work. | Every name under that TLD fails to resolve. It also fails when the Router is up but its dnsmasq or port 53 is broken, so a working Route can stop resolving. ADR 0219's fallback to WireGuard answers is gone for those names, because the vpn Node no longer holds them. |
| The vpn Node is down | All private names fail, as today. | The same. |
| Names on the vpn Node | Everything. | Reserved names, TLD wildcards, Routes outside any TLD, and one delegation per TLD. |
| Names elsewhere | None. | Exact Cluster Routes on the Router. A Node TLD needs a DNS on the owning Node, which no Node has. |
| Records | `orbit.conf`, `orbit.hosts`, `orbit.servers`. `orbit.hosts` holds Routes whose answer differs from their TLD, and every Route outside a TLD. Today that is 17 names. | A's files, smaller, plus a file set on each Router. |
| Moving parts | 1 daemon, 3 files, 1 phase on 1 Node. | The same on the vpn Node. On each Router: `dnsmasq-base`, a unit, a file set, a phase, a stored digest. The Router DNS must listen on its WireGuard address, and the Router firewall must admit port 53 from the vpn Node. ADR 0219 binds only to the LAN address and admits only LAN members. Writes across Nodes are not atomic, so ADR 0219's digests and compensating restores apply. |
| Cluster limits | None. | A Cluster DNS needs a Router with a LAN address. |
| Doctor | One check: the files and the answers on the vpn Node. | ADR 0219's `role.cluster_dns_*` findings, the bind-overlap refusal, and the fallback findings. |
| Costs of LAN answers | None. | The member step: a systemd-resolved delegate, automatic recovery after fallback, about 20 lifecycle events, and a resolved reload that fails lookups in flight. |
| Moves to B later | The renderer builds the vpn state per owner: TLD, exact Route, reserved name. B adds a Router render per Cluster and proves its answers equal A's. One publication then swaps the Cluster's names under its TLD for one `server=/TLD/ROUTER` line. Rollback removes the line. No data migration. | Already there. |

Chosen: A. The vpn file stays small, because a TLD wildcard already answers every Cluster Route under it. B saves little there and adds a failure mode and more Nodes to write. It pays off only with LAN answers.

A third path would keep A and add a second dnsmasq on the vpn Node, with eligible members pointed at it through `fleet.peer_dns`. It was not taken. [ADR 0219](/decisions/0219-answer-cluster-names-from-a-dns-on-the-router#rejected-alternatives) rejected two listeners, because they select by member identity again. A membership change would also need a peer DNS run.

## Decision

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
5. Check with `dig`: each reserved name, one name per TLD, and for each exact Route its A answer, AAAA NODATA and a child name that is forwarded. When the check fails, restore the last good files, reload or restart as needed, and fail the phase with `dns.check_failed`.
6. Write `orbit.revision`.

A Molecule scenario tests the phase and its templates. Publications to one Node run one at a time. Each takes a few seconds, and an older one skips at step 1.

### One owner

Route, Node, Cluster, metrics and role workflows call the shared `dns-publish` child with the render in the intent. `route-create-dns`, `node-private-dns` and the compose step go.

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
