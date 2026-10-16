---
title: "ADR 0219: Answer Cluster names from a DNS server on the Router"
sidebarTitle: "0219 Cluster DNS"
description: "In progress. Each Cluster Router with a LAN address runs dnsmasq that answers the Cluster TLD with that LAN address. LAN members ask it first through a systemd-resolved delegate. The VPN DNS becomes plain dnsmasq with WireGuard answers, and the PHP listener is deleted."
---

# ADR 0219: Answer Cluster names from a DNS server on the Router

This proposed design picks the Router address by where a query goes, not by who sends it. Each active Cluster with a TLD and a Router LAN address gets a Cluster DNS: dnsmasq on the Router LAN address that answers names under the Cluster TLD with that address. A LAN member sends those names to the Cluster DNS first and to the VPN DNS second. Every other name, and every other requester, uses the VPN DNS, which becomes plain dnsmasq that gives everyone WireGuard addresses. The PHP listener, its codec, and its catalog of requesters are deleted.

## Status

In progress.

Design proposal only; no implementation or fleet change is authorized by this ADR.

### Approval boundary

Nick's decision on 2026-10-16 includes the Cluster TLD **and exact Cluster-scoped Route domains outside it**, forwarding remaining names to VPN DNS, and Cluster DNS first with VPN DNS fallback for LAN members. The TLD-only, non-forwarding design below is an agent recommendation, not Nick's approval. Decisions 1–3 require explicit maintainer approval before implementation. Keeping the original scope is possible, but forwarded failures can move the resolver to VPN DNS and leave it there; this proposal avoids that coupling by reducing scope. The absence of outside-TLD Routes in the recorded fleet is not approval to remove that feature.

All new units, programs, files, digest storage, lifecycle hooks, and Doctor codes below are planned. Existing code names describe integration points, not completed implementation. Fleet observations below were recorded during design on 2026-10-16 and must be checked again before rollout.

Principle: [Lean](/mission#principles). Two stock dnsmasq instances replace a custom DNS server, its release, its socket units, and its catalog of requesters. It also serves [Deterministic first](/mission#principles): the configuration, not a lookup of the query's source, decides which server answers first. No principle exception is proposed.

## Context

- The `vpn` Node answers private names with a PHP listener in front of a dnsmasq backend on `127.0.0.55` ([Private DNS: The listener](/reference/private-dns#the-listener)). The listener is about 2,200 lines of code and 2,100 lines of tests: a DNS codec, a transport server, inherited sockets, a release, a catalog store, and a catalog confirmation.
- The listener gives a Cluster member the Router LAN address and everyone else the Router WireGuard address. It knows the requester by the WireGuard source address of the query ([Cluster Router addresses](/reference/private-dns#cluster-router-addresses)). dnsmasq alone cannot do that: `--localise-queries` keys only on the receiving interface, and every WireGuard query arrives on the `orbit` interface.
- The backend records, `orbit-records.conf`, already hold the WireGuard answer for every private name. The catalog adds only the LAN answers for members and the map of requesters.
- Managed Linux Nodes run Ubuntu 26.04 with systemd 259. The [systemd-resolved manual](https://www.freedesktop.org/software/systemd/man/259/systemd-resolved.html), [delegate manual](https://www.freedesktop.org/software/systemd/man/259/systemd.dns-delegate.html), and [v259 resolver source](https://github.com/systemd/systemd/tree/v259/src/resolve) establish these constraints. These facts decide how a member can hold two servers:
  - A DNS server set on a link is bound to that link with `IP_UNICAST_IF` (`dns_server_ifindex`: "The link ifindex always takes precedence"). A LAN address on the `orbit` link goes into the tunnel. No peer `AllowedIPs` covers it, so WireGuard drops it.
  - In one scope, resolved uses one current server. It moves to the next server after a timeout, an ICMP error, a REFUSED answer, or a SERVFAIL without an extended error code. It stays there until a reload, a restart, or a failure of that server.
  - When two scopes tie on the best routing domain, resolved asks both and returns the first successful answer.
  - A DNS delegate (`/etc/systemd/dns-delegate.d/*.dns-delegate`, since systemd 258) is a scope with its own servers and routing domains. Its servers are not bound to a link, so the routing table picks the interface. `systemctl reload systemd-resolved` builds each delegate again from its first server, keeps the link settings, flushes the caches, and restarts the stub listener.
- The live fleet has one Cluster with a LAN Router: `dev`, TLD `test`, Router beast. Its LAN members are beast, shark, and sabre, which has no roles. Its task VMs have no LAN address, and their firewall drops private ranges. The Router of the `prod` Cluster has no LAN address. All 39 `dev` Routes use names under `test`. Three custom proxy Routes on beast also use names under `test` and get beast's WireGuard address.
- On beast, operator rules admit every port from 192.168.6.0/24 and 192.168.1.0/24, and an unmanaged rule, `Orbit E2E Incus DNS`, admits port 53 from any source.
- Before 2026-09-13, dnsmasq answered on the VPN DNS address itself, with `bind-dynamic`, and on the private interface of the `vpn` Node. Bootstrap firewall rules still open port 53 on that interface, but nothing listens there. app-prod's `--dns-server` override, 10.0.0.2, points at it.

## Decision

The Gateway renders the VPN DNS, each Cluster DNS, and each member delegate from the same records. This changes two rationales for member DNS: [LAN first, without fallback](/reference/routes#lan-first-without-fallback) and [Router LAN answers from registered intent](/reference/private-dns#router-lan-answers-from-registered-intent). A member that cannot reach its Cluster DNS gets the WireGuard answer. The Router-to-workload hop keeps no fallback.

### Where each answer comes from

| Requester | Names under the Cluster TLD | Other names |
| --- | --- | --- |
| A LAN member that holds the delegate | The Cluster DNS gives the Router LAN address, or for an exception the address that the VPN DNS gives. The VPN DNS is the second server. | The VPN DNS |
| Any other Node, sabre included | The resolver of the Node, as today. The VPN DNS gives the Router WireGuard address. | The resolver of the Node, as today |

A Cluster-scoped Route domain outside the TLD keeps the VPN DNS answer on every Node. This narrows the decision of 2026-10-16 (decision 1 below).

### The Cluster DNS

- A Cluster has a Cluster DNS while it is active, has a TLD, and its Router has an IPv4 LAN address.
- It runs as `orbit-cluster-dns.service` on the Router: `/usr/sbin/dnsmasq` from `dnsmasq-base`, with its own files in `/etc/orbit/cluster-dns/`. It never reads `/etc/dnsmasq.d` and never uses the stock `dnsmasq.service`.
- It listens only on the Router LAN address, with `bind-dynamic`, so it starts before the address exists at boot and never binds the wildcard address. The unit orders itself before `nss-lookup.target`, as the stock dnsmasq unit does.
- It answers every name under the TLD itself and forwards nothing. It has no upstream server, so it refuses every other name. Members never send it other names.

| File | Content | On change |
| --- | --- | --- |
| `dnsmasq.conf` | `listen-address`, `bind-dynamic`, `no-resolv`, `no-hosts`, `local-ttl=30`, `local=/<tld>/`, `address=/<tld>/<Router LAN>`, an `address=` line with the VPN DNS address for each more specific Node TLD below the Cluster TLD, and `addn-hosts=/etc/orbit/cluster-dns/hosts` | Restart |
| `hosts` | The exceptions: each exact VPN DNS record below the TLD that is not a Route domain of this Cluster, such as a custom proxy Route, with its VPN DNS address | Reload with `SIGHUP` |

- A name in `hosts` wins over the TLD address, and a more specific `address=` wins over a less specific one. A test with a real dnsmasq checks both.
- `SIGHUP` reads `hosts` again and clears the cache without closing a socket. So a change of an exception never stops the Cluster DNS. A Route under the TLD that is not an exception changes nothing on the Router.

### The VPN DNS

- The VPN DNS is the stock `dnsmasq.service` on the `vpn` Node, as before the listener. `orbit-vpn.conf` has one `listen-address` line, the VPN DNS address, an `interface=` line for the private interface, and `bind-dynamic`.
- Its records are today's WireGuard answers. The TLD wildcards stay in `orbit-records.conf`. The exact names move to an `addn-hosts` file, which that file names. After each change the publication sends `SIGHUP`, so a Route change never restarts the VPN DNS.
- `local-ttl=30` keeps the 30-second TTL of today's answers, which the [withdrawal grace period](/reference/routes#withdrawal-grace-period) relies on.
- It keeps the `server=` lines of the `vpn` Node, which bootstrap wrote from that Node's uplink resolvers. The publication rewrites only the lines that Orbit owns.
- `bind-dynamic` cannot run next to a snippet with `bind-interfaces`, such as the stock `ubuntu-fan` snippet. The cutover moves every such snippet in `/etc/dnsmasq.d` to `/var/lib/orbit/dnsmasq/disabled`.
- The listener, its release, its socket units, `catalog.json`, the requester map, and the LAN overrides are deleted.

### Record boundary

[PR #1299](https://github.com/nckrtl/orbit/pull/1299), now merged into main, added `ContributedPrivateDnsRecords` and `ContributedReservedHostnames`. The implementation must preserve that contract. Core and extension records pass one validation boundary before either server is rendered:

- Accept canonical lowercase DNS labels only (1–63 characters per label, at most 253 per name). Reject whitespace, newlines, commas, slashes, wildcards, control characters, and config separators. Validate stored data as well as API input.
- Accept only IPv4 host addresses. VPN records, including extension records and Node TLD targets, must be usable addresses in the configured VPN subnet, excluding network and broadcast addresses. A Cluster wildcard target must equal the registered Router LAN address and pass the existing LAN-address validation. Never accept raw dnsmasq fragments from a contributor.
- Refuse duplicate exact names across contributors and core, even with equal addresses, before merging maps. Refuse duplicate suffix owners. An exact Route overriding a TLD wildcard and a more specific Node TLD are intentional precedence, not duplicate owners.
- Reserve core names and extension names before accepting Routes. As in #1299, extension names must belong to their registered reserved `.orbit` namespace; no Cluster or Node TLD may cover it or the configured private VPN namespace. Reject a conflicting stored Route or TLD before publication; do not silently overwrite it.
- Validate the full record set before the first remote write. An invalid contribution fails publication with its owner and reason and leaves the previous projections intact. Run `dnsmasq --test` on staged files too; syntax checks do not replace boundary validation or live socket checks.

Under that namespace contract, extension records go only to VPN DNS. Cluster DNS derives its exceptions from the validated core records under its TLD, never from an independent extension renderer. If the maintainer changes that contract to permit extensions under a Cluster TLD, the same boundary and exception rules must apply before either render.

Disabling an extension makes its contribution empty but retains its reserved names. The disable action publishes the resulting full snapshot and sends `SIGHUP` to remove the old hosts entries and flush server caches. It reports publication failure instead of claiming DNS removal; a retry or forced convergence repairs it. Clients may retain the old answer for its 30-second TTL. A Router whose render is unchanged needs no write. Tests cover malicious names and addresses, duplicate owners, reserved-name collisions, disable, failed disable publication, and retry.

### Publication

- One publication renders the VPN DNS and each Cluster DNS from the same database state and overrides, under the projection lock `$ORBIT_HOME/.dnsmasq-projections.lock`. It writes the VPN DNS first, then the Cluster DNS of each Cluster in Cluster order.
- For each Cluster that has a Cluster DNS in the render, it writes the Router that `ClusterRouterDnsSelection` names: the active Router, or a replacement candidate after its `dns-publication` step. It never writes an old Router or a `Failed` row. An explicit transition render remains authoritative until commit or restore; intermediate Route publications must carry that same override. Simply omitting deletion does not protect an expanded TLD or Router selection from being overwritten. It never removes a Cluster DNS. Only the explicit removals in the lifecycle do that.
- The Gateway stores the digest of the last Cluster DNS it wrote to each Router Node. It clears the digest before a write and after a removal, and stores it after the program confirms the write. A Router with an unchanged digest gets no connection, so a down Router blocks only publications that change its own Cluster DNS. A Router with no digest always gets a write.
- Remote writes are sequential, not atomic across Nodes. On partial failure, invalidate the affected digests, restore every touched projection from the previous snapshot, and report any failed restore as drift with a repair target. When a write fails, the publication fails with `app-dev.dns_config_failed`, and the caller's restore publishes the previous state, as today. A command with `--offline` skips a Router write that it cannot make, and Doctor reports the drift.
- The planned ADR 0211 remote program `dns.converge-cluster-dns` owns the Cluster DNS on a Router. With a render, it installs and enables the unit when missing, writes the files, restarts for a `dnsmasq.conf` change, sends `SIGHUP` for a `hosts` change, starts an inactive unit, and confirms that the unit owns UDP and TCP port 53 on the LAN address. With `absent`, it stops and disables the unit and deletes `/etc/orbit/cluster-dns`. It returns `package_missing` when `/usr/sbin/dnsmasq` is missing.
- The publication, the Router baseline and its removal, and a new `cluster-dns` footprint artifact run that program. The artifact ignores the stored digest. The fleet rollout runs it once on each Router that has a Cluster DNS, and `node:converge <router> --force` repairs drift.
- The planned ADR 0211 remote program `dns.converge-vpn-dns` writes the VPN DNS.
- `dnsmasq-base` joins the `router` role packages and its recipe entry. Recipe convergence installs it on existing Routers.

### The member step

- A LAN member is an active member of a Cluster that has a Cluster DNS, with an IPv4 LAN address, a WireGuard address, and a WireGuard key. The Router is a LAN member of its own Cluster. The firewall sources are the LAN members other than the Router.
- A LAN member holds the delegate when it is a managed Linux Node with the default DNS policy: it has a role, it has no `--dns-server` override, and it holds neither `vpn` nor `gateway`.
- The member step is the ADR 0211 remote program `dns.select-cluster-dns`. On a Node that holds the delegate, it writes `/etc/systemd/dns-delegate.d/orbit-cluster.dns-delegate`. On every other managed Linux Node, it deletes that file when it exists.

```ini
[Delegate]
DNS=<Router LAN address> <VPN DNS address>
Domains=~<cluster tld>
```

- It then runs `systemctl reload systemd-resolved`, also when the file did not change, so that a repair moves a member back from the second server. It creates the directory when it is missing.
- The `orbit` link keeps the VPN DNS and `~.`. The tunnel, `orbit.conf`, its `PostUp` line, and `orbit.dns-link` do not change. systemd-resolved sends a name to the scope with the longest matching routing domain, so names under the TLD go to the delegate and every other name goes to the VPN DNS, as today.
- The step takes the peer lock `/run/lock/orbit-wireguard-peer.lock` on the Node and the Router lock of the Cluster on the Gateway. The Router lock is re-entrant, so the Cluster actions that hold it can run the step.
- Node provisioning, `php artisan orbit:node-dns-repair <node>`, and the lifecycle steps below run it.

### Failover

- When the Cluster DNS times out, refuses, or returns SERVFAIL without EDE after resolved exhausts feature retries, resolved moves the delegate to the VPN DNS. The member then gets the Router WireGuard address, which the Router also serves.
- The delegate stays on the VPN DNS until a member step, a repair, a reload or restart of systemd-resolved, or a failure of the VPN DNS, which wraps it back to the first server. A member that boots before its Router's Cluster DNS listens can fall back at its first lookup. Doctor reports a member on the second server.
- The Cluster DNS answers every name under the TLD from its own files and forwards none. So a public failure never reaches it, a VPN DNS outage never moves a member, and a down Router never stops other lookups.
- The two servers answer the same names differently. That breaks the systemd-resolved rule that the servers of one scope be equivalent, on purpose: the design relies on ordered failover that stays on the second server.
- Recommendation: accept this resolver behavior after testing systemd 259. SERVFAIL with an extended error code, NXDOMAIN, and NODATA need not trigger failover. DNS fallback is not Router high availability: if the whole Router is down, its WireGuard address cannot serve traffic either. It helps when the DNS service or LAN path fails but the Router remains reachable over WireGuard. If both DNS servers are down, uncached names fail.

### Lifecycle

A change is either up or down for its members:

- **Up.** The Gateway writes the Cluster DNS and opens port 53 first, then makes the change authoritative. The member steps run after the last publication of the action.
- **Down.** The member steps run first, with the proposed state of the action. They run a second time after the commit, with the stored state. The Cluster DNS removal and the port 53 prune come last.

An action validates before its first member step. When it fails after a member step ran, its failure path runs the member step again with the stored state. The planned member program stages its file and restores the old file on apply failure. Failed expansion steps leave members on their previous resolver and appear in the new `follow_up` response field, as role responses do. A failed removal or replacement step must block old Router cleanup and DNS removal: a stale delegate can keep returning successful old answers, so timeout fallback cannot make it safe. Retry the action or repair those members before cleanup. An offline destructive operation must explicitly report retained DNS state and affected members; it cannot claim safe convergence. OpenAPI, CLI, and MCP response contracts gain `follow_up`. Doctor reports the remaining drift.

| Event | Kind | Members | Cluster DNS and port 53 |
| --- | --- | --- | --- |
| Activate a Cluster | Up | Every LAN member | Written by the expand publication; port 53 rules with the LAN ingress expand |
| Deactivate a Cluster | Down | Every LAN member | Removed after the commit; port 53 rules pruned |
| Set or change the Cluster TLD | Up | Every LAN member | Written by the expand publication |
| Remove the Cluster TLD | Down | Every LAN member | Removed after the commit |
| Attach a member, or a member gains its LAN address | Up | That member | Its port 53 rules with the LAN ingress expand |
| Detach a member, or a member loses its LAN address | Down | That member | Its port 53 rules pruned |
| A member gains its first role, or loses its override | Up | That member | None |
| A member loses its last role, or gains an override | Down | That member | None |
| Set the first Router | Up | Every LAN member | Installed by the Router baseline |
| Replace the Router | Up | Every LAN member | Installed by the candidate baseline; never written on the old Router |
| Clear the Router | Down | Every LAN member | Removed by the baseline removal |
| The Router gains or changes its LAN address | Up | Every LAN member | Written; a change restarts the unit on the new address |
| The Router loses its LAN address | Down | Every LAN member | Removed after the commit |
| The VPN DNS address changes | Up | Every LAN member | None |
| Remove a member Node | Down | Remove its delegate while reachable | Its port 53 rules pruned at `router-lan-ingress` |
| Remove a Router Node or delete its Cluster | Down | Every LAN member before removal | Remove DNS and prune rules; offline residue is reported |
| An extension disables its record | None | None | Republish VPN DNS; see Record boundary |

- In `node:add`, the member step runs after `activate`, when the Node is active.
- A Router replacement keeps the Cluster DNS of the old Router. The old Router answers with its own LAN address and serves the Router sites until cleanup. The member steps run after `database` and before the cleanup of the old Router, in the first run, in a resumed run, and in a Cluster without Routes. With Routes, the cleanup grace wait starts after the last member step.
- A failed candidate gets the Cluster DNS removal and the port 53 prune in its restore. The members never moved.
- Offline Node removal can leave the delegate on the machine and lists it in `retained_on_node`. Router residue is also listed there. Online removal deletes it before losing access.
- A LAN address changes only through `node:add` on a Node without Instances. For beast or shark, this ADR does not change that.
- Each action keeps running inside its request. [ADR 0209](/decisions/0209-run-long-operations-as-durable-queued-operations) moves these actions when it moves the rest of them.

### Firewall

- The `router` role adds two rule families beside `orbit:router-lan-https`: `orbit:router-lan-dns-udp:<node-id>` and `orbit:router-lan-dns-tcp:<node-id>`. Each is one IPv4 UFW rule: `allow in proto udp|tcp from <member LAN> to <Router LAN> port 53`.
- The sources are the sources of the HTTPS family. The same reconciler applies them, in the same expand and prune calls.
- The Router's own queries arrive on loopback, which the stock Ubuntu UFW rules accept.

The rollout must narrow overlapping operator and unmanaged rules before enabling Cluster DNS. The member-only allow rules do not override a broader allow. Preserve required Incus DNS access by binding its rules to the bridge destination/interface; verify an unauthorized LAN source cannot query the Router LAN address over UDP or TCP. This is planned rollout work, not a mutation made by this ADR PR. VPN DNS on the private interface also needs managed UDP/TCP rules for `vpn.private_interface` and Doctor coverage, replacing the existing bootstrap-only rules.

### Doctor

Every finding below is `drift`. A Router finding needs the Router in scope, and a member finding needs the member.

| Code | Family | Finding |
| --- | --- | --- |
| `role.dns_projection_mismatch` | role | Changed: the records, the hosts file, or the Orbit lines of `orbit-vpn.conf` on the `vpn` Node differ from the render. |
| `role.dns_listener_mismatch` | role | New: dnsmasq does not own UDP and TCP port 53 on the VPN DNS address and the private interface address. |
| `role.dns_listener_retained` | role | New: listener units, the catalog, or listener releases remain on the `vpn` Node. |
| `role.cluster_dns_inactive` | role | New: `orbit-cluster-dns.service` is not active on a Router that has a Cluster DNS. |
| `role.cluster_dns_projection_mismatch` | role | New: a Cluster DNS file on the Router differs from the render. |
| `role.cluster_dns_listener_mismatch` | role | New: the unit does not own UDP and TCP port 53 on the Router LAN address. |
| `role.cluster_dns_retained` | role | New: an active Router whose Cluster has no Cluster DNS keeps the unit or its files. |
| `node.cluster_dns_retained` | node | New: a Node without an active `router` role keeps the unit or its files. |
| `role.firewall_projection_mismatch` | role | Changed: it also reports a Router LAN rule whose source is not eligible, for the HTTPS and DNS families. |
| `node.cluster_dns_selection_mismatch` | node | New: the delegate file differs from the render, or exists on a Node that must not hold it. |
| `node.cluster_dns_fallback` | node | New: the delegate has a current server, and it is not the Cluster DNS. An unset current server is not a fallback. |
| `instance.private_dns_mismatch` | instance | Changed: it expects the Router LAN address when the workload Node holds the delegate, else the Router WireGuard address. A member on the fallback reports this and `node.cluster_dns_fallback`. |

### Build order

Each step is one pull request. Each step preserves a working resolver path, apart from the stated reload and cutover interruptions, and names its rollback. Roll back in reverse order.

1. **Firewall families.** Generalize the Router LAN families, which are hard-coded to TCP port 443, and add the two DNS families. Live: the next Router LAN reconcile adds four rules on beast, for sabre and shark. That is the next task VM enrollment, attach, detach, or `node:add`. Before enabling DNS, narrow the overlapping rules described under Firewall and verify denied sources too. Check with `orbit firewall:live-list beast` and Doctor. Record the previous operator rules so rollback restores them. Rollback: revert, then delete the four `orbit:router-lan-dns-*` rules on beast with `ufw delete`. The reverted code does not know their names.
2. **Cluster DNS on Routers.** Add the renderer, the stored digests, `dns.converge-cluster-dns`, the unit, the `cluster-dns` artifact, `dnsmasq-base` for the `router` role, and the `role.cluster_dns_*` and `node.cluster_dns_retained` findings. No member uses it yet. Precondition: beast has `dnsmasq-base`, which it has. Ship the Record boundary checks in this step before either new renderer accepts data. Live: the first DNS publication after the deploy installs it on beast. That is a task VM enrollment, a Route change, or the `private-dns` converge of the `vpn` Node, which the rollout visits before beast. Check with Doctor, and query 192.168.6.20 from shark for a Route domain, `x.test`, and a custom proxy name with a short `python3` query. A query from shark does not test the firewall, because the operator rules admit shark. Rollback: roll back step 3 first. Then revert, and run `systemctl disable --now orbit-cluster-dns` on beast.
3. **Member step.** Add `dns.select-cluster-dns`, the lifecycle steps, `follow_up`, the repair change, the `node.cluster_dns_*` findings, and the new `instance.private_dns_mismatch` rule. Precondition: Doctor reports no `role.cluster_dns_*` finding on beast. The deploy changes no member. Live: run `orbit:node-dns-repair shark`. Check `resolvectl status`, `getent ahostsv4` for a Route domain, a custom proxy name, and a public name, and Doctor. Then do beast. Inventory every eligible member and record successful selection before step 5; any proposed exclusions, including sabre, require maintainer approval first. Test DNS-service failure and LAN-path failure separately from a whole-Router outage, then repair and confirm return to Cluster DNS. While the listener runs, the VPN DNS also gives members LAN answers, so no answer changes in this step. Rollback: revert, then on each member delete the delegate file and run `systemctl reload systemd-resolved`.
4. **Rollback bridge.** Change the listener publication so that it moves dnsmasq off the VPN DNS address before it starts the listener socket, whenever that socket is not active. Today the socket start comes first and fails while dnsmasq holds TCP port 53. A test runs this publication on the files that step 5 leaves and starts a real dnsmasq. This is a short-lived edit of an inline script that ADR 0211 still lists, and step 6 deletes it. Live: no change. Rollback: revert.
5. **VPN DNS cutover.** Add `dns.converge-vpn-dns` and the `role.dns_listener_*` findings. Each publication runs the cutover while listener units exist, under `/run/lock/orbit-dnsmasq.lock`. The cutover installs the files, moves the `bind-interfaces` snippets away, stops the listener service and socket, and restarts dnsmasq at once. It confirms with `ss` that dnsmasq owns UDP and TCP port 53 on both addresses, and then deletes the units, the catalog, and the releases. On a failure it restores the backend files, restarts dnsmasq, and starts the listener again. Precondition: Doctor reports no `node.cluster_dns_*`, `role.cluster_dns_*`, or `role.firewall_projection_mismatch` finding. Live: merge it while someone watches the `vpn` Node, and merge nothing else until it is verified. The first publication after the deploy runs the cutover. Lookups through VPN DNS can fail between stopping the listener and confirming dnsmasq. Measure this interval in rehearsal; it has no proven sub-second bound. Preserve backups until confirmation, and restore all changed snippets and listener assets on failure. A successful cutover rollback must restore the disabled snippets recorded in its manifest too. From here, sabre gets WireGuard answers. Check `getent` from shark, beast, a task VM, the services Node, and app-prod, and run Doctor. Rollback: run `gateway:release:rollback` to the release before step 5, which holds the bridge, or merge a revert. The next publication moves dnsmasq back to `127.0.0.55` and starts the listener. A rollback pauses automatic releases until `gateway:release:auto:resume`.
6. **Delete and absorb.** Delete the listener, the codec, the catalog, the requester code, the bridge, and their tests. Remove `DnsmasqPrivateDnsManager.php` from the ADR 0211 inline script list when its last script goes. Delete `role.dns_listener_retained` after Doctor reports it clean on the live `vpn` Node. Absorb this ADR into [Private DNS](/reference/private-dns) and [Routes](/reference/routes), and retire it. Live: no change. Rollback: revert.

### Decisions for the maintainer

The decision of 2026-10-16 fixes the location rule, the two servers, plain dnsmasq on the `vpn` Node, the port 53 rule, and the member step on join and leave. The points below are additional proposals, not approved decisions. Each has a recommendation.

1. **The Cluster DNS covers only names under the Cluster TLD.** This narrows the decision of 2026-10-16, which also named Cluster Route domains outside the TLD. Recommendation: accept. No such domain exists in the live fleet. Each one would need a routing domain on every member, a member step on every Route change, and local answers for its subdomains and other record types. Without those, a public failure below it moves members to the second server.
2. **Members send only names under the TLD to the Cluster DNS, through a delegate.** The `orbit` link keeps `~.` for the VPN DNS. Recommendation: accept. The Cluster DNS cannot be a server on the `orbit` link. In one ordered list for every name, one public SERVFAIL or timeout moves a member to the VPN DNS until a reset.
3. **The Cluster DNS forwards nothing and refuses names outside the TLD.** This narrows "forwards every other name to the VPN DNS". Recommendation: accept. Members never send it those names, and the wide port 53 rules on beast would make a forwarder an open resolver for the LAN.
4. **No automatic return after a fallback.** A member returns at the next member step, repair, or reload, and Doctor reports it as drift. Recommendation: accept. If Router reboots make this drift frequent, revisit the policy with the maintainer; removing fallback would change Nick's requirement.
5. **Router, TLD, and Cluster changes run the member step on every LAN member.** The decision named only join and leave on one machine. Recommendation: accept, because the delegate holds the Router LAN address and the TLD.
6. **Failed expansion steps produce `follow_up`; failed withdrawal or replacement steps hold cleanup.** Recommendation: accept. Successful stale answers do not trigger fallback, so cleanup must wait for members to move.
7. **Members without roles and members with a `--dns-server` override get no delegate.** sabre gets WireGuard answers from step 5. The firewall still admits sabre, so an operator can add the delegate by hand. Recommendation: accept.
8. **The Router resolves its own names under the TLD through its own Cluster DNS**, unless it holds `vpn` or `gateway`. Recommendation: accept, so that one rule covers every LAN member.
9. **A separate `orbit-cluster-dns.service` from `dnsmasq-base`**, not the stock `dnsmasq.service`. Recommendation: accept. A Router can also hold `vpn` or Incus bridges, and on beast the stock unit is failed while `/etc/dnsmasq.d` holds an Incus snippet and a stale Orbit file.
10. **Exact names live in a hosts file that dnsmasq reads again on `SIGHUP`, on both servers.** Recommendation: accept. A restart drops lookups, and `SIGHUP` also clears answers that dnsmasq cached for a name before it became a Route.
11. **Both servers keep the 30-second TTL with `local-ttl=30`.** Recommendation: accept.
12. **The Gateway writes a Router only when its stored digest differs, and publications never remove a Cluster DNS.** Recommendation: accept. A down Router then blocks only its own Cluster, and a publication from stored state during an activation cannot undo the expand.
13. **The VPN DNS answers on the private interface of the `vpn` Node again.** Recommendation: accept. The override of app-prod points at that interface, and one `interface=` line restores it.
14. **A rollback bridge before the cutover.** Recommendation: accept. Without it, a rollback needs a manual step on the `vpn` Node and several seconds without DNS.
15. **Doctor reports Router LAN rules whose source is not eligible**, for the HTTPS family too. Recommendation: accept.
16. **Narrow overlapping port 53 rules before enabling Cluster DNS.** Recommendation: include this in rollout, preserving Incus access on bridge interfaces. Without it, Nick's member-only requirement is not met.
17. **The member steps run inside the request of their action.** Recommendation: accept while a Cluster has few LAN members.
18. **One validated record boundary, reserved extension names, and removal on disable.** Recommendation: accept the Record boundary contract already introduced by #1299.
19. **Transition overrides remain authoritative across intermediate publications; partial writes require compensating restore.** Recommendation: accept. A digest alone does not provide distributed consistency.

## Rejected alternatives

- **Unbound on the `vpn` Node with `access-control-view`.** One daemon and no change on members, but it adds a package and still answers by source identity.
- **Two dnsmasq listeners on the `vpn` Node.** Each member would need the address of its own listener, which is selection by identity again. Every LAN answer would still cross the hub and depend on the `vpn` Node.
- **No LAN answers.** Traffic between LAN members would go through the hub in the datacenter.
- **Keep the PHP listener.** It keeps a custom DNS server, its release, and its socket units to answer what two stock dnsmasq instances answer by location.
- **The Cluster DNS as a second server on the `orbit` link.** systemd-resolved sends each query to a link server out of that link, and WireGuard drops the LAN query.
- **Two scopes with `~.`.** resolved asks both and takes the first success, so a Cluster name gets the LAN answer or the WireGuard answer by chance.
- **The Cluster DNS on the LAN link.** networkd drops the setting when it restarts, NetworkManager overwrites it, and the VPN DNS cannot be a second server there.
- **A global `resolved.conf` drop-in instead of a delegate.** It takes over the global `DNS=` list of the machine and needs the same reload.

## Consequences

- The listener code and its tests are deleted. The VPN DNS is stock dnsmasq again.
- A LAN member resolves names under the TLD over the LAN, also when the `vpn` Node or its own tunnel is down.
- Each Router of an active Cluster with a TLD and a LAN address runs a DNS server.
- A member step reloads systemd-resolved, which flushes its cache, restarts the stub listener, and fails the lookups in flight on that member.
- A TLD change or a LAN address change of the Router can move a LAN member to the second server between the publication and its member step. The member step reloads systemd-resolved and moves it back.
- Software that sends DNS to the VPN DNS address itself, outside systemd-resolved, gets WireGuard answers on a LAN member.
- When an uplink does not answer, dnsmasq sends no reply and the client times out. The listener answered NXDOMAIN after about 4 seconds. A Node TLD nested below a Cluster TLD follows the dnsmasq rule that the more specific domain wins.
- A LAN member needs systemd 258 or newer for the delegate. Ubuntu 26.04 ships systemd 259.

## Affects

- Components: apps/gateway, apps/cli, packages/php-sdk, apps/docs
- ADRs: [0210](/decisions/0210-give-each-extension-its-own-code-boundary), whose plug-in point for private DNS records feeds only the VPN DNS; [0211](/decisions/0211-ship-node-changes-as-remote-programs), which the three new programs follow; [0212](/decisions/0212-turn-proxycli-into-the-ai-router-extension), whose step 2b DNS record is a VPN DNS record
- Detail: [Private DNS](/reference/private-dns), with a Cluster DNS section in place of "Cluster Router addresses" and "The listener", and their "Why it works this way" sections; [Routes: Cluster scope](/reference/routes#cluster-scope), [Private network trust](/reference/routes#private-network-trust), and "LAN first, without fallback"; [Node provisioning: Converge the Orbit footprint](/reference/node-provisioning#converge-the-orbit-footprint); [cluster](/cli/cluster); [node](/cli/node); [doctor](/cli/doctor); [firewall](/cli/firewall); [Relocate the gateway role](/solutions/relocate-gateway-role)
- Verify: `php artisan test --compact --filter='ClusterDns|PrivateDns|ClusterRouterDnsSelection|RouterLanIngress|WireGuardPeerDns|Doctor'` in apps/gateway, `bin/remote-programs-check`, a test that runs the rendered Cluster DNS and VPN DNS files in a real dnsmasq, and the live checks of each build step
