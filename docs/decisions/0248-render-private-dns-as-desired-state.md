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
- Rollback is the previous rendered configuration. The phase takes a snapshot before its first change and restores it when a check fails. A rerun is the rollback. There is no rollback bridge.
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
| Same name, other types (AAAA, MX) | `server=/name/` with no address, in `orbit.servers`; none for a name under `.orbit`, where `local=/orbit/` gives the same NODATA | NODATA. Today the backend forwards AAAA upstream, so a public IPv6 address can leak. |
| Names below an exact Route use the public resolver | `server=/*.name/#` in `orbit.servers`, except below `.orbit`: a forward rule would override `local=/orbit/` | Same. Below `.orbit` a child is NXDOMAIN at once, not forwarded. |
| Add or remove an exact Route | `SIGHUP` re-reads both files | No restart. The answer, NODATA and the child forward all change on SIGHUP alone. |
| Add, change or remove a TLD | A new `orbit.conf` and a restart | A regression: every name stops during the restart. A TLD changes rarely. |
| TTL of 30 seconds, which the 31-second Route grace wait relies on | `local-ttl=30` in `orbit.conf` | Same |
| Uplink resolvers, bind address, the `ubuntu-fan` snippet, the Node's own resolver, `vpn.orbit` | The phase reads the uplink resolvers, binds with `listen-address` and `bind-dynamic` (also on `127.0.0.55`, the Node's own resolver), and moves the snippet away. `orbit.conf` keeps `domain-needed`, `bogus-priv` and `local=/orbit/`; the Gateway renders `vpn.orbit` | Same, as [Private DNS](/reference/private-dns#upstream-resolvers) describes |
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

`swamp/ansible/dns-publish.yml` renders `orbit.conf`, `orbit.hosts` and `orbit.servers` from the data with Jinja templates. It runs under the existing `/run/lock/orbit-dnsmasq.lock` for the whole publication and its recovery:

1. Skip when the revision is not newer than `orbit.revision`. This is a successful skip with the code `dns.revision_superseded`.
2. Validate the files in a temporary folder with `dnsmasq --test` on a temporary `orbit.conf` that names the temporary hosts and servers files. A refusal fails the phase with `dns.config_invalid`, and nothing is installed.
3. Take a snapshot before the first change: the files, the unit states of dnsmasq and the old listener, and who holds the listen addresses. Reuse an existing snapshot, never overwrite it. Write `reload.pending`. Then install the files whose content changed, each in one rename.
4. Reload with SIGHUP when `orbit.conf` equals `orbit.conf.loaded`, the copy dnsmasq last started with, and restart otherwise. Delete the copy before a restart and write it after.
5. Check with `dig` on the VPN address and on `127.0.0.55`, then through the Node's normal resolver: reserved names, one name per TLD, each exact Route (A, AAAA NODATA, a forwarded child), a public name (advisory: a warning, never a failure).
6. Write `orbit.revision`, then commit: the new files become the last good set, `reload.pending` and the snapshot go. A failed check restores the snapshot and checks the restored resolvers.

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
4. The cutover moves dnsmasq to the VPN DNS address, stops the listener, and asserts the answers, while someone watches. The listener code is already gone, so a rollback after the cutover restores the last committed rendered files; a failed cutover restores the old listener from the snapshot.
5. The restart gap of a TLD change and the handover gap of the first cutover are measured with a query loop. Both numbers are in Consequences.

## Rejected alternatives

- **B: delegate each TLD to its Router or owning Node.** The vpn dnsmasq would list only TLDs, reserved names and Routes outside any TLD, and send `server=/TLD/ADDRESS` to each Router. Every name under a TLD then fails when its Router or the Router's dnsmasq fails, and nothing falls back to WireGuard answers. It needs `dnsmasq-base`, a unit, a phase and a digest on every Router, port 53 rules from the vpn Node, and a listener on the Router WireGuard address. It does not give LAN answers unless the ADR 0219 member step is built too. A can move to B by one publication that swaps a Cluster's names for one `server=/TLD/ROUTER` line.
- **A second dnsmasq on the vpn Node for LAN members.** ADR 0219 rejected two listeners: members would select a server by identity again, and a membership change would need a peer DNS run.
- **A custom listener that keeps answers per caller.** It keeps the codec, the sockets, the release, the catalog and the merge, which is what this ADR removes.

## Consequences

- The listener, the catalog, the requesters and the three-way merge are gone. The vpn Node runs stock dnsmasq with three files.
- A WireGuard member gets the Router's WireGuard address, and beast, sabre and shark reach beast through the WireGuard hub instead of the LAN.
- AAAA for an exact Route gives NODATA. It no longer leaks a public IPv6 address.
- A TLD change restarts dnsmasq, so every name stops for the length of the restart. Measured on Ubuntu 26.04 with a query every 66 ms on average: five restarts, 213 queries, none failed. The gap is shorter than the query spacing.
- Parallel publications are safe because each one writes a whole state and the newest revision wins. A failed run leaves its rows in the database, and the next publication renders them.
- A restore keeps the revision it took at admission, and a publication admitted after it takes a newer one, so the Node skips the restore. After a failed `cluster.router_set`, `cluster.router_unset` or `instance.transfer` is stored, the Gateway queues one fresh `dns.publish` (`PublishPrivateDns`).
- The first snapshot is the state to go back to until a publication commits. A killed first cutover followed by a failed retry restores the original old listener setup, not the candidate.
- A failed recovery keeps the snapshot and says `dns.rollback_failed` with `recovered: false`. The next run starts from the snapshot. The recovery checks that the restored resolvers answer over UDP and TCP, not what they answer.
- The stop of the old listener and the restart of dnsmasq are one detached command. A phase that is killed meanwhile (the 300-second step timeout, a worker crash) leaves a running dnsmasq, and the next publication finds no `orbit.conf.loaded` and restarts.
- The lock holder expires after 420 seconds: the step timeout, the 90-second cutover budget and a margin. The next publication waits 150 seconds, longer than a killed phase's holder has left. DNS can be down until a killed run's successor ends.
- `reload.pending` makes the retry of a killed record publication reload dnsmasq although the files match.
- The answers per caller go, and these A answers change. Whether the first is wanted is Nick's decision. Per-caller answers are not built, so both outcomes stay possible.
  - Beast, sabre and shark: 120 names change from `192.168.6.20` to `10.44.0.7`. They are the apex `test` and 119 exact `*.test` records. Any new name under `test` changes the same way.
  - Five exact `.test` records already give `10.44.0.7` today, because they bypass the override: `flow-viewer.orbit.test`, `gateway-feedback.orbit.test`, `operator.test`, `reverb.sync-engine.mealou.test` and `team.test`.
  - App-prod (`10.44.0.4`): 13 names change from `10.0.0.4` to `10.44.0.4`. They are `hauzer.app`, `mealou.app`, `nckrtl.com`, `nckrtl.com.prod` and the `sync.`, `analytics.`, `horizon.` and `treadmill.` hosts of them. That address is app-prod's own, so no traffic path changes.
  - The round trip from shark to beast takes 0.18 ms over the LAN and 8.9 ms over WireGuard through the hub. Both Nodes have one WireGuard peer, the hub.
  - All other callers (Mac, iPad, mini, task VMs, services, swamp) already get the WireGuard answer, so nothing changes for them.
- The vpn Node's own resolver points at `127.0.0.55`, which the new dnsmasq also serves. The end check asks it and the Node's normal resolver, so a Node that resolves nothing fails the phase.
- The rollback is the last committed set. The cutover keeps the old listener's files in `previous/legacy/` and deletes its release in `/var/lib/orbit/private-dns`, so a rollback after a successful cutover cannot restart the old listener. A failed cutover still can. This is intended: the listener code is deleted.
- The handover gap of the first cutover is measured by the Molecule dig loop (bound 3,000 ms). On Ubuntu 26.04, with a fake old listener that answers, 685 queries every ~50 ms during the cutover all got an answer. The real listener holds its sockets until it exits, which is at most 5 seconds, so a query that arrives meanwhile is still answered by it. The live gap is checked again at the cutover.

## Affects

- Components: apps/gateway, apps/docs
- ADRs: replaces [0219](/decisions/0219-answer-cluster-names-from-a-dns-on-the-router) as a whole, and the Cluster DNS member step of [0226](/decisions/0226-split-private-dns-on-managed-peers)
- Detail: [Private DNS](/reference/private-dns), [Routes](/reference/routes), [Node provisioning](/reference/node-provisioning), and the chart `docs/cli/dns/publish.mmd`. The change also touches `swamp/`: the `dns-publish` workflow, its Ansible phase and the parent workflows that call it.
- Verify: Molecule on the phase, a rehearsal on a task VM fleet, and a name-by-name comparison of old and new answers on the live vpn Node
