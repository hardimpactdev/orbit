---
title: "ADR 0248: Render private DNS as desired state"
sidebarTitle: "0248 Private DNS desired state"
description: "In progress. Option A is chosen: every publication renders the complete private DNS from the Gateway database, and one shared Ansible phase writes the vpn and Cluster Router dnsmasq projections. LAN members select their Cluster resolver."
---

# ADR 0248: Render private DNS as desired state

Every DNS publication renders the whole private DNS from the Gateway database. One shared Ansible phase writes a stock dnsmasq on the `vpn` Node and a dedicated dnsmasq on each active Cluster Router with a LAN address. LAN members select their Cluster resolver for its names. There is no custom listener, no delta piece and no three-way merge.

## Status

In progress.

The VPN desired-state build is in PR #3165 and is not merged. The Cluster Router amendment is approved after the review fixes of 2026-11-08; implementation is in progress. The member mechanism is a Cluster-only DNS delegate with no automatic VPN fallback. The orchestrator owns the live cutover after implementation and proof.

Principle: [Deterministic first](/mission#principles) and [Lean](/mission#principles). This ADR replaces the publication machinery of [ADR 0219](/decisions/0219-answer-cluster-names-from-a-dns-on-the-router), retains its Router LAN DNS firewall families, and defines the simpler Cluster resolver below. Members use the Cluster-only DNS delegate below; the old automatic fallback plan is superseded.

The VPN baseline and Cluster amendment:

- Option A remains: all private Routes are answered on the vpn Node. Cluster resolvers add a LAN overlay; the vpn Node does not delegate names to them.
- The AAAA fix is taken: NODATA for exact Routes, no upstream IPv6 leak.
- Rollback is the previous rendered configuration. The phase takes a snapshot before its first change and restores it when a check fails. A rerun is the rollback. There is no rollback bridge.
- The render reads only stored rows. Every pending state (Cluster TLD, Router and LAN address changes, a pending Node) is stored before the publication.
- Source-dependent answers on the vpn Node are gone. Other callers receive WireGuard addresses there; a LAN member obtains LAN answers from its Cluster Router.

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

The vpn Node keeps the complete WireGuard projection. Each eligible Cluster Router holds a complete LAN projection for its own Cluster, built from the same stored Route selection. The resolver a member selects determines the address; neither server inspects requester identity.

### One render

The Gateway renders the complete state from the database as data, not as text: the listen addresses, the TLDs with their addresses, and every record with its address and whether it needs the NODATA pair. `DevelopmentDnsConfigRenderer` becomes this data builder. It loses its override and pending-Node parameters, `catalog()` and `registeredRequesters()`. It checks every name and address against a strict grammar (lowercase DNS labels, IPv4) before it emits them, as [ADR 0219](/decisions/0219-answer-cluster-names-from-a-dns-on-the-router#record-boundary) requires. `dnsmasq --test` checks only `orbit.conf`, not the hosts and servers files.

The render is a function of rows. A change stores the row state that decides inclusion, such as a Route `retiring`, a Cluster TLD, a Router, a LAN address or a pending Node, before the owning kind is admitted. The render is taken at admission and carried in the intent.

### One revision

- The revision is `max(stored + 1, now in microseconds)`. The Gateway takes it in the same transaction that reads the rows. A database restore cannot move it below an installed revision, because the clock keeps going.
- The Node holds the installed revision in `orbit.revision`, written last.
- On each target, the newest render wins and contains every change recorded before it. Distribution across Nodes is not atomic; the handover barrier below keeps old paths alive until every member confirms. A failed run does not undo its change by replaying an old render. The Gateway projects the failure into the rows, and the next publication renders them.

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

### Cluster Router resolver

The Gateway builds VPN answers, Router overlays and member selection in the same revision transaction. One rule selects the stored Route placement that serves each name. Its VPN projection uses WireGuard addresses; its Cluster projection substitutes the selected Router LAN address only for names served through that Cluster. A record already answered by the same TLD suffix is omitted. New Instances inside that TLD need no DNS publication.

| Name on a Cluster resolver | Answer |
| --- | --- |
| Cluster TLD, apex and descendants | Router LAN address; absent types, including AAAA, give NODATA |
| Exact Cluster Route outside its TLD | Router LAN address; other types give NODATA; descendants forward to VPN DNS unless separately owned |
| More specific Node TLD or Route with a different serving target | Preserve the canonical selection with an explicit exception; never let the Cluster wildcard capture another target |
| Unmatched name, including other Clusters and reserved names | Forward directly to VPN DNS |

A Cluster without a TLD still serves its exact Cluster Routes. A Router without a LAN address has no LAN resolver, and members keep VPN selection. An inactive Cluster has no selected resolver. Public Routes do not become private merely because their workload belongs to a Cluster. Preserve existing publication and reserved-name conflict rules.

The Router runs a separate `orbit-cluster-dns-CLUSTER.service` from `dnsmasq-base`, with its own explicit config path and state directory. It binds only the recorded LAN address on UDP and TCP port 53, using `bind-dynamic`. It does not load `/etc/dnsmasq.d`, bind loopback or WireGuard, move Incus snippets, or change the Node's own resolver. Refuse an overlapping bind before mutation; the Router may share a Node with VPN DNS only when their bind sets are disjoint. Install packages and the unit during Router preparation, not on every Route publication.

Reuse `swamp/ansible/dns-publish.yml`, its templates and its validation, lock, snapshot, pending-reload, check and revision contract. Pass the unit, owned paths, listen addresses, local zones and upstream addresses as target data. Keep the existing reloadable hosts and servers files alongside one startup config per resolver; do not replace record-only SIGHUP with a restart. Router upstream is the VPN address only, with `no-resolv` and `no-hosts`. It must not discover uplinks or inherit the VPN-only `local=/orbit/` rule. A forwarded child of an exact name goes to VPN DNS, not straight to a public resolver. The vpn Node never forwards to a Router, so no forwarding loop exists.

### Member selection and persistence

An active Cluster's managed Linux members with a recorded LAN address and delegate support use its ready LAN Router, including the Router itself. Roles do not decide membership eligibility. A roleless member needs managed SSH enrollment before selection; report an unmanaged member as unresolved work rather than claiming it was configured. Nodes without a LAN address and non-members retain VPN split DNS. A conflicting explicit DNS override is refused before changing it.

The Gateway renders one systemd-resolved DNS delegate for the Cluster TLD and outside-TLD Cluster Route domains. It has one DNS server: the Router LAN address. Every other private suffix stays on `orbit`, with the VPN server. Both private scopes are route-only. Remove equal or more specific competing WireGuard domains for the selected Cluster; do not deduplicate across destination scopes. Keep more specific foreign namespaces on VPN. An outside-TLD Route domain also captures descendant queries in resolved; the Cluster resolver forwards those queries back to VPN DNS.

```ini
[Delegate]
DNS=<Router LAN address>
Domains=<Cluster route-only domains>
DefaultRoute=no
```

Write `/etc/systemd/dns-delegate.d/orbit-cluster.dns-delegate` and persist the remaining VPN domains in the existing WireGuard hook and DNS marker. Stage, apply and check both scopes under the member's peer lock. Reload systemd-resolved only when the delegate file or live scope differs; apply changed WireGuard link policy and flush affected cached answers in the same phase. On withdrawal, persist and activate the complete VPN split policy first, then remove the delegate and reload resolved, flush caches and verify VPN selection before retiring the old Router. A brief overlap of both private scopes is allowed during this handover; an interruption must leave a private scope for every Cluster name, never a gap to public DNS. The delegate survives reboot, and the WireGuard hook cannot recreate a competing domain on tunnel restart.

One shared member phase serves `fleet.peer_dns`, Node convergence, explicit DNS repair and Cluster lifecycle work. Its digest includes Cluster and Router identity, LAN address, both domain sets, VPN address and delegate capability. Record the revision separately so an unchanged effective policy stays unchanged. Lifecycle runs wait for affected members in their own Swamp graph; an asynchronous peer reconcile is not the handover barrier. The phase checks that the recorded LAN address has a route to the Router. There is no LAN-link DNS, global `resolved.conf`, `resolv.conf`, address, route or DHCP change. Doctor and peer reconciliation use this same effective policy, so they cannot undo a healthy delegate.

The delegate keeps public DNS independent even when LAN and public traffic share an interface. systemd-resolved has one server set per link, not one set per routing domain. Beast uses public DNS server `192.168.6.1` on LAN link `enp11s0`; that link and its server stay unchanged. The delegate is an independent scope, and the routing table sends its queries to the Router over the LAN.

### Required systemd version

DNS delegates require **systemd 258 or newer** and an active systemd-resolved with delegate support, as the [delegate manual](https://www.freedesktop.org/software/systemd/man/258/systemd.dns-delegate.html) specifies. Read-only inspection confirmed the running service is active, the installed resolver binary is version `259.5-0ubuntu3.4`, and the delegate manual is installed on beast, sabre and shark. All three meet the minimum. This confirms support; the implementation's Molecule proof must exercise actual delegate selection and persistence.

A member without delegate support keeps the complete VPN split policy and receives WireGuard answers, with no LAN path. The phase reports `dns.cluster_delegate_unsupported` as a successful degraded outcome, records the effective VPN policy and removes any owned delegate. Doctor reports `node.cluster_dns_delegate_unsupported`. Do not add a second member-selection mechanism. After a systemd upgrade, explicit repair or Node convergence checks capability again and selects the delegate when supported.

### Membership and Router lifecycle

Before the VPN cutover, admit a `cluster_only` publication. The same phase prepares Router resolvers and member selection while the existing VPN listener continues to serve. Its barrier covers the selected Router projections; it does not write VPN files or stop the listener. The complete publication includes VPN DNS after the orchestrator authorizes cutover. The member policies and Router projections already match, so that cutover preserves their LAN selection.

The lifecycle caller supplies its stored pending state to the shared publication. Serving sites and LAN access exist before a resolver selects them. Expansion writes and checks DNS targets before selecting members. Withdrawal or replacement confirms every affected member has moved, waits the shared DNS publication grace (31 seconds from the last successful DNS selection), and only then removes the old resolver, sites or LAN rules. Removal failures retain their target identities for retry; absence from a render is not authority to delete a daemon.

| Event | Required DNS work |
| --- | --- |
| Join, or member gains LAN | Open existing managed ingress, publish and check Router projection, then persist and check that member's selection |
| Leave, or member loses LAN | Restore and check that member's VPN split policy before pruning its LAN DNS access; retain the old address until withdrawal completes |
| Activate | Prepare and check Router DNS, then select every eligible member |
| Change or remove Cluster TLD | Publish the new projection and member domains together; remove old domains after selection and grace; exact Routes remain without a TLD |
| `cluster:router:set` | Prepare candidate sites and DNS, publish selected VPN and LAN projections, move every eligible member, wait grace, then retire the old Router |
| `cluster:router:unset` | Keep existing active-TLD and Route refusals; restore members to VPN and remove owned DNS before clearing the assignment and pruning ingress |
| `node:remove`, including `--offline` | Require completed Cluster detach and router unset. Membership, assignments and retained transitions block removal. |
| Deactivate | Reconcile Routes to their remaining scope, publish VPN state, restore every LAN member to VPN, then retire Cluster DNS and its ingress |
| Router LAN or VPN address changes | Treat as replacement: prepare new endpoint, update dependent projections and members, retain old endpoint until every dependent target confirms |
| Route outside TLD changes | Publish the complete affected resolver projections and changed member routing domains; keep the existing Route withdrawal grace |

An offline member keeps its detach transition until policy withdrawal is verified. Neither `--force` nor `--offline` bypasses this barrier. A Router address change retains any bind still named by member evidence; a fresh publication prunes it after every member confirms the new selection and grace completes.

The shared DNS publication owns the grace once for all affected Routes and members. It records the last successful DNS selection time and waits its remaining 31 seconds before authorizing retirement. A retry reuses that time when selection is unchanged; each new successful selection resets the deadline. Route reconciliation and Router transitions consume this barrier and add no second wait. Any successful VPN or Router publication or member selection is the cutover, including success before another parallel target fails. Retain candidate serving resources after that point until forward recovery or a fresh-revision rollback has moved all consumers back and passed the same barrier.

If publication is interrupted after it starts, its missing terminal receipt cannot prove that consumers stayed on the old Router. Retain the candidate and reconcile with a fresh revision. Restore candidate preparation only when DNS publication never started.

### Consistency, failure and rollback

Every target records its installed revision and content digest. The publication returns per-target receipts, including the selected server and domain digest for each member. A superseded revision is a successful `dns.revision_superseded` skip on that target, but it does not by itself authorize a stale member switch or retirement. A dependent phase requires the effective projection it needs. If newer state differs, stop that obsolete transition and reconcile from current rows. Keep existing Cluster lifecycle serialization and the per-Node peer/DNS locks; do not add a fleet lock or claim distributed atomicity.

An unchanged target needs no reload; a newer publication with identical content updates only its revision. A failed changed phase restores only its own captured persistent and live settings while it still owns the target lock. Report failed recovery as `dns.rollback_failed` and keep its snapshot. Successful target publications stay installed. A member failure blocks old Router retirement and leaves the unfinished transition and receipts recorded. A worker crash does not prove cleanup ran; a retry inspects pending state before completing or restoring that target.

A deliberate rollback records the intended prior topology as new desired state and publishes a fresh revision through the same graph. It first restores and checks the serving endpoint, then moves members, then retires the unused endpoint. Do not replay an old render across the fleet or restore a whole Node. Do not delete an old Router, its LAN address or its DNS access while an offline member can still select it. Keep that member as an explicit blocker.

There is no automatic VPN fallback for selected Cluster names. A failed Cluster resolver or LAN path makes uncached names in that scope fail until repair or an explicit selection change. Other private names still use VPN, and ordinary public names retain their public scope. A VPN outage leaves locally answered Cluster names available, but forwarded names fail. Doctor reports `node.cluster_dns_unreachable` when the delegate or LAN resolver path fails. Keep the delegate selected until repair; no probe switches servers. The member phase installs an owned read-only systemd check and a 60-second timer. The check resolves an uncached Cluster name through the normal stub with a 20-second budget. The existing agent watches its unit state; Doctor reports a failed check, a stopped resolver, or missing observations. No new agent action is required. Capability comes from the recorded phase receipt, never from an assumed systemd version. The Router hosts these Routes, so fallback adds machinery without making a failed Router serve traffic.

### Rollout and acceptance

PR #3165 stays unmerged. Build and prove Router DNS and member selection while the PHP listener still serves VPN DNS; the overlay can forward to that existing endpoint. The implementation needs a deployable pre-cutover stack layer: split listener deletion and VPN socket takeover into the final layer, rather than deploying the #3165 base as a prerequisite. This design branch is based on #3165 for review only and is not deployable. Prove and select LAN members before allowing the final cutover layer to run. The cutover preserves the LAN path for members that have selected their Cluster resolver.

Use real Ubuntu Molecule machines for a VPN resolver, a Router and a member. Cover record-only reload and unchanged PID, TLD restart, invalid config and bind conflict, UDP/TCP, AAAA NODATA, exact descendants, nested foreign namespaces, Cluster without a TLD, roleless managed members, member policy persistence after reboot and link restart, and ordinary public DNS with VPN down. Prove join, leave, Router move/unset, deactivate, offline-member cleanup refusal, phase rollback, controller interruption and a newer revision overtaking an older transition. Include the shared LAN/public interface case, a member without delegate support that keeps WireGuard answers, and a Cluster DNS or LAN failure that leaves the delegate selected and reports a Doctor finding.

Before cutover, update the comparison kit to query the member stub separately from direct VPN and Router endpoints. Preserve a fresh baseline and list every expected change. The historical five WireGuard exceptions under `.test` may become LAN answers under the complete Cluster suffix; distinguish that preselection change from the VPN cutover. Do not declare byte-for-byte agreement with old LAN expectations until each exception is classified by Route ownership. List the address change on app-prod separately. The design step changes neither the kit nor live DNS.

### What gets deleted

- The listener, its release, sockets and units: `PrivateDnsMessageCodec`, `PrivateDnsTransportServer`, `PrivateDnsInheritedSockets`, `PrivateDnsSocketBinder`, `PrivateDnsListener*`, `PrivateDnsRequestHandler`, `SocketPrivateDnsUpstream`, `ServePrivateDnsCommand`, `NodePrivateDnsListenerFootprint`, `PrivateDnsFootprintArtifact`, `resources/private-dns/serve.php`, and the `php:8.5` build of the `vpn` profile that only the listener needs.
- The catalog and the requesters: `PrivateDnsAnswerCatalog`, `FilePrivateDnsCatalogStore`, the answer selectors and cache, `*DnsRequesterResolver`, `DnsRequester`, `catalog.json`, `catalog.json.loaded`, and the `overrides` and `answers()` code in `ClusterRouterDnsSelection`, with `ClusterRouterDnsSelectionReconciler` and the DNS part of `ClusterRouterTransition`.
- The publishers and the merge: `DnsmasqPrivateDnsManager` (744 lines), `VpnDnsmasqBackendListen`, `mutation-command/dns.ts` and its test, and the workflows `route-dns-publish`, `route-create-dns` and `node-private-dns`.
- Keep the Router LAN DNS firewall families of ADR 0219 step 1. The Cluster resolver uses them; their scope remains eligible member LAN sources, TCP and UDP port 53. Do not add a second rule owner.
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
- A WireGuard member gets the Router's WireGuard address. LAN members get the Router LAN address from the Cluster resolver, so their application traffic stays on the LAN.
- AAAA for an exact Route gives NODATA. It no longer leaks a public IPv6 address.
- A TLD change restarts dnsmasq, so every name stops for the length of the restart. Measured on Ubuntu 26.04 with a query every 66 ms on average: five restarts, 213 queries, none failed. The gap is shorter than the query spacing.
- Parallel publications are safe because each one writes a whole state and the newest revision wins. A failed run leaves its rows in the database, and the next publication renders them.
- A restore keeps the revision it took at admission, and a publication admitted after it takes a newer one, so the Node skips the restore. After a failed `cluster.router_set`, `cluster.router_unset` or `instance.transfer` is stored, the Gateway queues one fresh `dns.publish` (`PublishPrivateDns`).
- The first snapshot is the state to go back to until a publication commits. A killed first cutover followed by a failed retry restores the original old listener setup, not the candidate.
- A failed recovery keeps the snapshot and says `dns.rollback_failed` with `recovered: false`. The next run starts from the snapshot. The recovery checks that the restored resolvers answer over UDP and TCP, not what they answer.
- The stop of the old listener and the restart of dnsmasq are one script that runs detached on the Node. When dnsmasq does not start or does not hold the listen addresses, the script restores the snapshot itself and writes the outcome to `cutover.state`.
- So a controller that dies in the cutover (the 300-second step timeout, a worker crash) cannot leave both listeners down. The script needs no controller, and the next publication waits for a running one before it reads the Node.
- The lock holder of a dead controller does not release the lock. It expires after 420 seconds. The next publication waits 150 seconds, which covers a controller stopped by the step timeout. An earlier crash can make it fail on the lock, and the owner retries. The Node's DNS does not depend on the lock.
- `reload.pending` makes the retry of a killed record publication reload dnsmasq although the files match.
- The VPN endpoint changes the following historical A answers. The cutover must preserve the already-selected LAN path through the member stub; direct queries to VPN DNS intentionally receive WireGuard answers.
  - Direct queries from beast, sabre and shark to VPN DNS: 120 names change from `192.168.6.20` to `10.44.0.7`. They are the apex `test` and 119 exact `*.test` records. Any new name under `test` changes the same way.
  - Five exact `.test` records already give `10.44.0.7` today, because they bypass the override: `flow-viewer.orbit.test`, `gateway-feedback.orbit.test`, `operator.test`, `reverb.sync-engine.mealou.test` and `team.test`.
  - App-prod (`10.44.0.4`): 13 names change from `10.0.0.4` to `10.44.0.4`. They are `hauzer.app`, `mealou.app`, `nckrtl.com`, `nckrtl.com.prod` and the `sync.`, `analytics.`, `horizon.` and `treadmill.` hosts of them. That address is app-prod's own, so no traffic path changes.
  - The round trip from shark to beast takes 0.18 ms over the LAN and 8.9 ms over WireGuard through the hub. Both Nodes have one WireGuard peer, the hub.
  - All other callers (Mac, iPad, mini, task VMs, services, swamp) already get the WireGuard answer, so nothing changes for them.
- The vpn Node's own resolver points at `127.0.0.55`, which the new dnsmasq also serves. The end check asks it and the Node's normal resolver, so a Node that resolves nothing fails the phase.
- The rollback is the last committed set. The cutover keeps the old listener's files in `previous/legacy/` and deletes its release in `/var/lib/orbit/private-dns`, so a rollback after a successful cutover cannot restart the old listener. A failed cutover still can. This is intended: the listener code is deleted.
- The handover gap of the first cutover is measured by the Molecule dig loop (bound 3,000 ms). On Ubuntu 26.04, with a fake old listener that answers, 685 queries every ~50 ms during the cutover all got an answer. The real listener holds its sockets until it exits, which is at most 5 seconds, so a query that arrives meanwhile is still answered by it. The live gap is checked again at the cutover.

## Affects

- Components: apps/gateway, apps/docs
- ADRs: replaces the unbuilt resolver mechanisms of [0219](/decisions/0219-answer-cluster-names-from-a-dns-on-the-router); retains split private DNS from [0226](/decisions/0226-split-private-dns-on-managed-peers)
- Detail: [Private DNS](/reference/private-dns), [Routes](/reference/routes), [Node provisioning](/reference/node-provisioning), and the chart `docs/cli/dns/publish.mmd`. The change also touches `swamp/`: the `dns-publish` workflow, its Ansible phase and the parent workflows that call it.
- Verify: Molecule on the phase, a rehearsal on a task VM fleet, and a name-by-name comparison of old and new answers on the live vpn Node
