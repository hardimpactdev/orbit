---
title: "Task VM egress log"
description: "Proposed design: how Orbit records, log only, which outside hosts a task VM connects to during task work, per VM and per task group, as the first step toward an egress allowlist."
---

# Task VM egress log

**Status: proposed, for review. Nothing is built, and no host has changed.**

A task VM can reach any public address. The bridge ACL (`orbittask0-egress`) drops private ranges and allows the rest. Step 1 records which outside hosts task VMs really use. Step 2 derives an allowlist from that record and enforces it through a proxy outside the VM. Step 1 blocks nothing.

The chart is [`task-vm-egress-log.mmd`](https://github.com/hardimpactdev/orbit/blob/task-vm-egress-monitor/docs/reference/task-vm-egress-log.mmd). It moves to `docs/cli/tasks/egress.mmd` with the read command, when that is built.

## Facts that shape the design

- A VM reaches the internet only through the bridge `orbittask0`: host NAT, IPv4 only (`ipv6.address=none`). Its WireGuard peer routes only Orbit's private subnet, so the tunnel carries no internet traffic. The model key guard on Services calls the model providers, so the VM itself needs no model host.
- The three nested containers sit behind the VM's own NAT. The host sees only the VM's address. The log therefore names the VM and its group, not the container. That is enough for a per-group list.
- `security.port_isolation=true` stops VMs from reaching each other on the bridge.
- A VM name (`beast-tvm-3`) is reused by later groups. A Node and its keys are new for each start, so the Gateway's claim record (VM name, group, start, end) is what ties a name to a group.

## Options

| Option | Sees | Misses | Cost | Maps to a VM and a group |
| --- | --- | --- | --- | --- |
| **DNS log on the bridge**: the bridge's own dnsmasq with `log-queries=extra` | Every name the VM, and the containers behind it, asked for, with the addresses returned. `extra` gives each query a serial number, so name and answers pair exactly. | A fixed IP, DNS over HTTPS or TLS, a resolver other than the bridge. Not ports. Not what the VM then did with the answer. | One `raw.dnsmasq` setting and a logrotate file. A dnsmasq reload blinks DNS for a second. Low volume. | The query line carries the VM's bridge address. |
| **Connection log**: new outbound connections, from the kernel's conntrack table, or a `ct state new` nftables `log` rule | Every TCP and UDP connection, including fixed IPs, DNS over HTTPS and QUIC: source, destination address, port, protocol. | Names. Traffic inside WireGuard (private Orbit addresses only, so out of scope). | Reading `/proc/net/nf_conntrack` needs no host change. A conntrack entry outlives the connection by at least 10 s (UDP 30 s, TCP in TIME_WAIT 120 s), so a 5 s read misses none. A log rule adds journald volume and needs a rate limit that can itself lose lines. | The source address is the VM's bridge address. |
| **SNI from a TLS peek**, passively (tcpdump, Zeek, Suricata) or in a transparent proxy | The host name in each TLS ClientHello, even for a fixed IP. | Encrypted Client Hello hides it, and its share grows. Non-TLS protocols. SSH to GitHub. QUIC needs a separate parser. | A capture daemon or a proxy in the path with its own parser and failure modes. A proxy in the path can break tasks. | By source address, like the others. |
| **Forward proxy in log mode** (squid or similar) | The host and, for plain HTTP, the URL, of every request that goes through it. | Everything that ignores `HTTP_PROXY`: git over SSH, apt, container pulls, and any tool that does not read the variable. A transparent redirect is needed for the rest, and then it is the SNI peek again. | A daemon in the path, for every task. | By source address. |

## Recommendation

Use the first two together: **the connection log is the ground truth, and the DNS log gives it names.** Join them per VM.

- The connection log cannot be bypassed from the VM, and it sees fixed IPs and DNS over HTTPS. It lacks names.
- The DNS log supplies the names. Where a VM connects to an address that the same VM resolved, the row carries the name. Where it connects to an address it never resolved, the row stays an address, which is itself the finding: a fixed IP, or another resolver. A connection to a public resolver on port 443 or 853 is marked `doh`.
- Neither needs a daemon, a rule in the packet path or any change inside the VM. Neither can slow or break a task.

Leave out the other two in step 1.

- **SNI**: ECH weakens it, and it needs a capture daemon. The question it answers, which name sits behind an unresolved address, is better answered by the first finding of a real run. Add it later only if fixed IPs turn out to be common.
- **Forward proxy**: it is the enforcement tool of step 2, not an observer. As an observer it sits in the path of every task and misses every tool that ignores the proxy. The connection log already shows which tools would bypass it, which is the exact input step 2 needs.

## Who does what

Following the rules: observe outside the VM, the agent observes, Swamp acts, Ansible does host work, stock tools only.

| Part | Owner | Work |
| --- | --- | --- |
| Host setup | Swamp runs an Ansible part (`egress-log` tag of `swamp/ansible/task-vm-host.yml`) | `incus network set orbittask0 raw.dnsmasq=...` (`log-queries=extra`, log file), a logrotate drop-in, a log directory. Idempotent. Run in a quiet window, not during a provision. |
| Observation | `orbit-agent` on each task VM host | Every 5 s, read `/proc/net/nf_conntrack` for sources in the bridge subnet, the new lines of the DNS log, the bridge's leases, and the Incus config of each VM. No new daemon, no port, no subprocess. |
| Roll-up | `orbit-agent` | One row per VM, destination (name or address), port and protocol, with first-seen time and sources (`dns`, `conn`, `doh`). |
| Report | `orbit-agent` | A new fact kind `egress` on the host Node, subject = VM name, over the existing `POST /api/v1/agent/facts`. Sent on change, within 15 s. |
| Store and read | Gateway | Attribute each row to the group that held the VM when the row was first seen. Keep it in a table per group. Read it with `tasks:egress`. |

The fact carries a set with no counters, so a quiet VM changes nothing and the Gateway writes nothing (facts already skip unchanged content). A fact holds at most 1 MiB. Rows cap at 4,000 per VM, with an `overflow` count above that.

## How a row maps to a VM and a group

1. The connection source address is a bridge address.
2. The bridge's lease file gives that address's MAC. The host's Incus config of each VM (`volatile.eth0.hwaddr`) gives the MAC's VM name. Both are host data. Nothing the VM says about itself, such as a DHCP host name, is trusted.
3. The Gateway holds the claim of each VM name (group, start, end). It adds the row to the group whose claim covers the first-seen time, and keeps it with the group after the VM and its Node are deleted.
4. The group's kind (feature work or proof) comes from the existing group record. The proofs' nested containers send their traffic through the VM like any other, so it lands in the same group.

The end of a group deletes its VM without waiting for a last report. Up to 15 s of teardown traffic can be lost. That is acceptable for a log whose purpose is the list of hosts, not an audit.

## Blind spots and how they show

| Blind spot | What shows |
| --- | --- |
| Fixed IP | A row with an address and no name. |
| DNS over HTTPS or TLS | A row to a public resolver on 443 or 853, marked `doh`. Later queries have no DNS lines, so their destinations appear as addresses. |
| A resolver other than the bridge | Port 53 connections to an outside address, in the connection log. |
| Traffic inside WireGuard | Not logged by design. If the VM's peer ever routed `0.0.0.0/0`, internet traffic would hide in the tunnel. The host would see one UDP flow to the hub. The Ansible part checks that the peer routes only the private subnet. |
| A VM that spoofs another VM's address | Possible: the NIC has no address filtering. A task agent is not hostile in step 1, and the log stays honest on the common case. Step 2 adds `ipv4.address` and `security.ipv4_filtering` on the NIC before it relies on the address to enforce anything. |
| Several names behind one address (CDN) | The name comes from the same VM's own queries, so it is a name that VM asked for. Where two names of one VM share an address, the row lists both. |
| The agent is down or restarts | Entries that ended in that gap are lost. The next read catches every connection still in conntrack. |

## Check before building

These details came from documentation, not from a host. Verify each on beast first, with no task running, before the PR that builds this:

- That `raw.dnsmasq` accepts `log-facility=<file>` for the Incus-run dnsmasq, that the process can write the file, and whether dnsmasq reopens it after logrotate (the signal, or `copytruncate`).
- That `nf_conntrack` shows the VM's pre-NAT source address in `/proc/net/nf_conntrack`, and that the agent can read it.
- That `incus network set raw.dnsmasq` reloads only dnsmasq. Shark's NAT dropped once after an Incus restart.
- A day of volume on beast: the size of the DNS log and the number of rows per group. The 4,000-row cap and the 15 s send interval are guesses until then.

## Build order after approval

1. Ansible part on beast in a quiet window, run twice to prove it idempotent.
2. Agent: the `egress` fact, with a parser test on recorded conntrack, DNS-log and lease samples.
3. Gateway: ingest, attribution, the table and `tasks:egress` with its page and chart (stage 1 of the [command pipeline](/reference/command-development-pipeline)).
4. Shark. Then let ten or so feature groups and proofs run, and read the union per Project.

## Not in this step

Blocking, allowlists, a proxy, SNI capture, a Gateway-side address enrichment (reverse DNS, ASN), and changes to the VM or its image.
