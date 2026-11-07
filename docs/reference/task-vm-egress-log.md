---
title: "Task VM egress log"
description: "How Orbit records, log only, which outside hosts a task VM connects to during task work, per VM and per task group, as the first step toward an egress allowlist."
---

# Task VM egress log

A task VM can reach any public address. The bridge ACL `orbittask0-egress` drops private ranges and allows the rest. Step 1 records which outside hosts task VMs really use. Step 2 derives an allowlist from that record and enforces it through a proxy outside the VM. Step 1 blocks nothing.

The [chart](https://github.com/hardimpactdev/orbit/blob/main/docs/reference/task-vm-egress-log.mmd) shows the flow. Read the result with `orbit tasks:egress`.

## How it works

Three owners split the work, and the host does the observing.

- **Host setup.** An Ansible part of the task VM host phase turns on DNS logging and a connection log on the bridge.
- **Observation.** `orbit-agent` on the host reads the two logs, joins them per VM, and reports an `egress` fact.
- **Attribution.** The Gateway adds each row to the task group that held the VM, and keeps it after the VM is deleted.

Nothing runs inside the VM. A compromised VM cannot hide or fake its traffic, because the host sees it first.

## What a VM looks like from the host

A VM reaches the internet only through the bridge `orbittask0`, with host NAT and IPv4 only. Its WireGuard peer routes only Orbit's private subnet, so the tunnel carries no internet traffic.

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

One Ansible part, tagged `egress-log`, sets up all of it. Each piece uses a stock tool.

1. **DNS log.** The part sets `raw.dnsmasq` on the bridge to `log-queries=extra` and a log file at `/var/log/incus/dnsmasq.<network>.log`. Incus's AppArmor profile allows exactly that path.
2. **Connection log.** The part loads an `nftables` table `orbit_egress` with a forward rule. It logs each new flow to a public address with the prefix `orbit-egress `, once per ten minutes for each distinct flow.
3. **File split.** An rsyslog rule moves those lines to `/var/log/orbit/task-egress.log`.
4. **Rotation.** A logrotate file keeps seven days of both logs.

A kernel without `/proc/net/nf_conntrack` is the reason for the `nftables` rule. Beast and shark build the kernel without it, and the agent has no netlink access. The `nft` log needs no new program.

Changing `raw.dnsmasq` restarts the bridge's dnsmasq. Run the part when no provision is running.

## The agent fact

The Gateway's watch list names the bridge, such as `{"egress": {"network": "orbittask0"}}`, for each task VM host. Without that member the agent reads nothing.

Every 5 seconds the agent reads the new lines of both logs and the bridge's leases. It names a VM by its MAC address. The lease gives MAC to IP, and Incus's own hosts file gives MAC to the VM name. The agent trusts nothing that the VM says about itself.

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
| `overflow` | Rows dropped above the cap of 4,000 per VM |

The fact holds no counters, so a quiet VM changes nothing. The agent never reads packet payload or URLs.

## Attribution to a group

The Gateway keeps one claim for each VM: its name, group, start and end. It adds each row to the group that held the VM, in the table `task_group_egress`. A row is unique per group, destination, port and protocol. A later report adds addresses, sources and flags to the row. It never removes a row.

The group keeps its rows after its VM and Node are deleted. The group's kind, feature work or proof, comes from the group record. Proofs run nested containers through the VM, so their traffic lands in the same group.

The end of a group deletes its VM without waiting for a last report. The last 15 seconds of teardown traffic can be lost.

## Reading it

Two reads show the rows of one group.

- **CLI:** `orbit tasks:egress <group>` lists the rows of one group.
- **API:** `GET /api/v1/task-groups/{group}/egress`, and the MCP tool `tasks-egress`.

## Blind spots

Each blind spot shows in the data.

| Blind spot | What shows |
| --- | --- |
| Fixed IP | A row with an address and no name |
| DNS over HTTPS or TLS | A row to a public resolver, flagged `doh` or `dot` |
| Another resolver | A port 53 flow to an outside address, flagged `dns` |
| Traffic inside WireGuard | Nothing, by design: it carries only private addresses |
| Two names on one address | One row for each name that the VM resolved to it |
| The agent is down | Flows that ended during the gap are lost; the next ten-minute repeat of the kernel log catches a flow that is still active |

A VM can spoof another VM's address, because its NIC has no address filtering. A task agent is not hostile in step 1. Step 2 adds `ipv4.address` and `security.ipv4_filtering` before it enforces anything.

## Not in this step

Blocking, allowlists, a proxy, SNI capture, address enrichment such as reverse DNS, a union across a Project's groups, and any change inside the VM or its image.
