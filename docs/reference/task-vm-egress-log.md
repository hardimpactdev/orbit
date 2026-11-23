---
title: "Task VM egress log"
description: "Read retained task VM egress rows and clean up egress logging files left on former VM hosts."
covers:
  - apps/gateway/app/Http/Controllers/Api/TaskEgressController.php
  - apps/gateway/app/Actions/Tasks/ShowTaskGroupEgressAction.php
  - apps/gateway/app/Data/Tasks/TaskGroupEgressData.php
  - apps/gateway/app/Models/TaskGroupEgress.php
---

# Task VM egress log

The task VM egress observer and host setup have been removed. The agent [watch list](/reference/node-agent#watch-list) omits `egress`, and the Gateway rejects any new `egress` fact with HTTP `422 agent.fact_not_expected`.

Existing rows in `task_group_egress` remain readable for audit purposes. They reflect outbound destinations observed on past task VMs, not current container task copies.

## Reading stored rows

Read retained egress rows for a parent task group (requiring Gateway access and the `tasks` extension). Treat destination names as untrusted data:

- **CLI:** `orbit task:egress <group>`
- **API / MCP:** `GET /api/v1/tasks/{task}/egress` and `task-egress`

Each row exposes `destination`, `kind`, `port`, `protocol`, `addresses`, `sources` (`conn` for kernel flow logs, `dns` for bridge DNS logs), `flags` (`dot` for port 853, `dns` for external port 53, `doh` for port 443 to known public resolvers), `vm`, and `first_seen_at`. Port and protocol are `null` for DNS-only entries, which are hidden once a connection to that destination exists.

## Running the host part

Host egress logging is not installed or converged on any Node. On hosts that still carry files from earlier task VM setups, remove them manually:

| Artifact | Cleanup action |
| --- | --- |
| `/etc/systemd/system/orbit-egress-log.service` | Disable and delete the unit |
| `/etc/orbit/egress-log.nft` | Delete the nftables config (`orbit_egress` table) |
| `/etc/rsyslog.d/19-orbit-egress.conf`, `/etc/logrotate.d/orbit-egress` | Delete rsyslog and logrotate rules |
| `/var/log/orbit/task-egress.log`, `/var/log/orbit/dnsmasq-<network>.log` | Delete old log files |
| `raw.dnsmasq` on `orbittask*` Incus bridges | Remove `log-queries=extra` and `log-facility=...` lines (restarts bridge dnsmasq) |
