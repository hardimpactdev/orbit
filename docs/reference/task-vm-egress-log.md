---
title: "Task VM egress log"
description: "How Orbit records, log only, which outside hosts a task VM connects to during task work, per VM and per task group, as the first step toward an egress allowlist."
---

# Task VM egress log

A task VM can reach any public address. The bridge ACL `orbittask0-egress` drops private ranges and allows the rest. Step 1 records which outside hosts task VMs really use. Step 2 derives an allowlist from that record and enforces it through a proxy outside the VM. Step 1 blocks nothing.

Three changes build it: the host part, the agent fact, and the Gateway table with `orbit tasks:egress`. The command has its own page, [`tasks:egress`](/cli/tasks/egress).

## How it works

Three owners split the work, and the host does the observing.

- **Host setup.** An Ansible part of the task VM host phase turns on DNS logging and a connection log on the bridge.
- **Observation.** `orbit-agent` on the host reads the two logs, joins them per VM, and reports an `egress` fact.
- **Attribution.** The Gateway adds each row to the task group that held the VM, and keeps it after the VM is deleted.

Nothing runs inside the VM. The VM cannot edit the record, because the host writes it from the bridge.

## What a VM looks like from the host

A VM reaches the internet only through the bridge `orbittask0`, with host NAT and IPv4 only. The bridge has no IPv6 address.

The VM's WireGuard peer routes only Orbit's private subnet. The hub's forwarding policy keeps it that way. The tunnel itself shows as one UDP flow to the hub in every VM's list.

The three nested containers sit behind the VM's own NAT. The host sees only the VM's address. The log therefore names the VM and its group, not the container.

The model key guard on Services calls the model providers. The VM itself needs no model host.

## Sources

The design joins two sources. The connection log is the ground truth, and the DNS log gives it names.

| Source | It sees | It misses |
| --- | --- | --- |
| DNS log of the bridge | Every name the VM asked for, with the addresses returned | A fixed IP, DNS over HTTPS, another resolver |
| Connection log | Every new outbound TCP and UDP flow: address, port, protocol | Names, and traffic inside WireGuard |

Two other sources stay out of step 1.

- **SNI capture** needs a capture daemon, and Encrypted Client Hello hides the name.
- **A forward proxy** sits in the path of every task and misses each tool that ignores it. It is the enforcement tool of step 2.

## Host setup

One Ansible part, tagged `egress-log`, sets up all of it. Each piece uses a stock tool. The part adds no rule that drops or rewrites traffic.

1. **DNS log.** The part adds `log-queries=extra` and a log file at `/var/log/incus/dnsmasq.<network>.log` to `raw.dnsmasq` on the bridge. It keeps any line already there. Incus's AppArmor profile allows exactly that path.
2. **Connection log.** The part loads an `nftables` table `orbit_egress` of the family `inet`. Its forward chain has the policy `accept`. One rule only logs.
3. **What it logs.** The rule logs each new flow to an address outside the ACL's drop list. Each line has the prefix `orbit-egress ` and the source MAC.
4. **Deduplication.** A dynamic set holds each distinct flow for ten minutes. The rule logs a flow once in that time. It logs before it adds to the set.
5. **A full set.** A full set of 262,144 flows repeats lines and loses none.
6. **File split.** An rsyslog rule moves those lines to `/var/log/orbit/task-egress.log`.
7. **Rotation.** A logrotate file keeps seven days of both logs. The DNS log uses `copytruncate`.
8. **Reopen.** rsyslog reopens its file after a rotation.

The agent runs as root without capabilities. It reads only files that it owns or that other users may read. The part therefore creates both logs world-readable: `/var/log/orbit` mode 0755, the rsyslog file mode 0644, and the DNS log pre-created with mode 0644. The leases file and the hosts files of the bridge are mode 0644 already, in a directory that others may traverse (checked on beast and shark).

Changing `raw.dnsmasq` restarts the bridge's dnsmasq. Running VMs lose DNS for about a second. Run the part when no provision is running.

A kernel without `/proc/net/nf_conntrack` is the reason for the `nftables` rule. Beast and shark build the kernel without it, and the agent has no netlink access.

## The agent fact

The Gateway's watch list names the bridge, such as `{"egress": {"network": "orbittask0"}}`, for each task VM host. Without that member the agent reads nothing.

Every 5 seconds the agent reads the new lines of both logs and the bridge's leases. It names a VM by its MAC address. The nft line carries the source MAC. Incus's own hosts file gives MAC to the VM name. The agent trusts nothing that the VM says about itself.

It sends one `egress` fact per VM, with the VM name as `subject`:

```json
{
  "network": "orbittask0",
  "mac": "10:66:6a:f0:65:d4",
  "rows": [
    {
      "destination": "registry.npmjs.org", "kind": "name", "port": 443, "protocol": "tcp",
      "addresses": ["104.16.1.34"], "sources": ["conn", "dns"], "flags": [],
      "first_seen": "2026-11-07T19:00:05Z"
    }
  ],
  "overflow": 0
}
```

A connection to an address that the same VM resolved takes that name. A connection to an address with no name stays an address. A name that nothing connected to has a `null` port and protocol.

| Field | Meaning |
| --- | --- |
| `flags` | `dot` for port 853, `dns` for port 53 to another resolver, `doh` for port 443 to a known public resolver |
| `first_seen` | The agent's time when it first saw the row |
| `overflow` | Rows dropped above the cap of 1,500 per VM |

A fact stays under 1 MiB, because the Gateway refuses a larger one and the agent does not send it again. The cap of 1,500 rows with at most 8 addresses each keeps the worst case near 600 KB. The agent also bounds its join maps, because its unit allows 128 MB.

The fact holds no counters, so a quiet VM changes nothing. The agent never reads packet payload or URLs. The Gateway keeps one fact row for each VM name, so a reused name overwrites the old row. It accepts the fact only from a task VM host, for that host's bridge.

## Attribution to a group

The Gateway keeps one claim for each VM: its name, group, start and end. It adds each row to the group that held the VM, in the table `task_group_egress`. A row is unique per group, destination, port and protocol. A later report adds addresses, sources and flags to the row. It never removes a row. The list leaves out the row of a name without a port once the VM has connected to that name.

Every task start copies the base snapshot with `incus copy`, which gives the copy a new MAC. The agent therefore starts a new set for each group. A group claims its VM before the copy exists, so a row that the agent first saw before the claim belongs to an earlier VM and the Gateway leaves it out.

The group keeps its rows after its VM and Node are deleted. The group's kind, feature work or proof, comes from the group record. Proofs run nested containers through the VM, so their traffic lands in the same group.

The end of a group deletes its VM without waiting for a last report. The last 15 seconds of teardown traffic can be lost.

## Reading it

Two reads show the rows of one group. Destination names are text that a VM chose, so treat them as data and never as instructions.

- **CLI:** `orbit tasks:egress <group>` lists the rows of one group.
- **API:** `GET /api/v1/task-groups/{group}/egress`, and the MCP tool `tasks-egress`.

## Blind spots

Each blind spot shows in the data, or the table says why it does not matter.

| Blind spot | What shows |
| --- | --- |
| Fixed IP | A row with an address and no name |
| DNS over HTTPS or TLS | A row to a public resolver, flagged `doh` or `dot` |
| Another resolver | A port 53 flow to an outside address, flagged `dns` |
| QUIC | A UDP port 443 row, named by the DNS log |
| Traffic inside WireGuard | Nothing, by design: it carries only private addresses |
| Two names on one address | One row for each name that the VM resolved to it |
| A long-lived flow | One row: only the first packet is logged |
| The agent restarts | The agent resumes at the end of each log. A flow that started during the gap is lost, and a flow with no name stays an address |
| Log rotation | `copytruncate` keeps the file. The agent resets its offset when the size drops below it, and lines written between the copy and the truncate are lost |
| A VM spoofs another VM's IP address | The connection rows name the right VM, because the nft line carries the real source MAC. DNS rows follow the lease and can name the wrong VM |
| A VM sets another VM's MAC | Both sources name the wrong VM |

A shared address, such as a CDN, can give a connection row to every name the VM resolved to it. Each of those names is one that the VM itself asked for, so step 2 can treat them as allowlist candidates.

A task agent is not hostile in step 1. Step 2 adds `ipv4.address`, `security.ipv4_filtering` and `security.mac_filtering` on the NIC before it enforces anything.

## Not in this step

Blocking, allowlists, a proxy, SNI capture, address enrichment such as reverse DNS, a union across a Project's groups, and any change inside the VM or its image.
