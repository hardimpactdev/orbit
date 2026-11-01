# Observer gap audit

Written 2026-11-01T20:14Z at `d761329ff8` (branch `observer-gap-audit`). Read-only audit. It changes no code. It feeds the later "strip to observer" pass; `apps/agent` stays frozen until then.

**Decision under audit (Nick, 2026-11-01).** orbit-agent is the only collector of live Node facts. It observes services, Processes, disk and the firewall (ufw) continuously, reports them to the Gateway with `observed_at`, and acts on nothing. `orbit doctor` and `firewall:live:list` read the Gateway's latest observations: no Swamp run, no SSH. A stale or missing observation is "unverifiable".

## Summary

- Today the agent reports only a heartbeat, `orbit-process-*` unit states, Docker availability and task-workspace Git state. Everything else doctor needs (disk, platform, role services, ufw, awake markers) is read over SSH by Swamp, or by on-demand agent commands that the observer does not run.
- Swamp doctor plans reads for four families only (`firewall`, `node`, `role` services, `process`). The other seven families have issue codes in the Gateway catalog but no producer today. Their old agent commands (`doctor.inspect-*`) are frozen and nothing calls them.
- The agent runs as root with an empty capability set and `NoNewPrivileges=yes`. Reading `ufw status numbered` needs `CAP_NET_ADMIN` (to verify on a Node), not sudo. The doctor comparison logic (TypeScript in Swamp) must also move to the Gateway.

## 1. What doctor checks today

### Path

`orbit doctor` → `StartDoctorAction` captures stored intent (`apps/gateway/app/Actions/Doctor/StartDoctorAction.php`) → Operation `doctor.inspect` → workflow `swamp/workflows/workflow-operation-doctor-inspect.yaml`:

1. `plan` (behavior `@nckrtl/doctor`, `planReads`) lists reads from the intent.
2. `reads` runs each read as one `@swamp/ssh` `exec` from Node 209 (`okExitCodes` 0 and 1, 60 s bound). `sudo` is set per read.
3. `logs` runs `scripts/logs.py` over SSH, only when the Gateway collects problems (`collect_problems`).
4. `report` (`compare`) compares the output to intent and emits one typed report. `DoctorProjection` stores it in the Gateway.

The behavior is `swamp/extensions/behaviors/observation/doctor-read/doctor.ts`. The ufw parser and comparison are `swamp/extensions/behaviors/operations/firewall/inspection-result.ts`.

### Families and issue codes

The Gateway catalog (`apps/gateway/app/Domain/Doctor/*DoctorIssueCode.php`) has 11 families. `planReads` makes reads for four. The "Producer today" column says who emits each code now.

| Family | Codes in catalog | Producer today |
| --- | --- | --- |
| node | 28 | Swamp: `platform_mismatch`, `architecture_mismatch`, `disk_low`, `agent_missing`, `agent_inactive`, `agent_view_stale`, `lifecycle_not_active`, `task_vm_parked`, `inspection_failed`. `ssh_unreachable` only blocks the platform read. Gateway jobs: `task_vm_*` (`TaskVmHostAudit`, sweep). `FleetNodeVerifier` owns repair for `ssh_unreachable`, `agent_*`, `cli_foreign`. No producer: `private_dns_*`, `public_dns_unreachable`, `wireguard_ip_mismatch`, `route_residue_retained`, `release_lag`, `worker_failed`. |
| role | 19 | Swamp: `services_inactive`, `lifecycle_not_active`. No producer: `packages_missing`, `caddy_*`, `php_pool_directory_missing`, `vpn_*`, `dns_projection_mismatch`, `firewall_projection_mismatch`, `ci_runner_host_mismatch`, claim and cluster codes (`caddy_build_drift` is only a fleet-repair trigger). |
| process | 5 | Swamp: `runtime_missing`, `state_mismatch`, `crash_loop`. |
| firewall | 6 | Swamp: `backend_inactive`, `rule_missing`, `rule_mismatch`, `lifecycle_not_active`. |
| project, instance, schedule, tool, database_connection, database_server, route | 4 / 33 / 11 / 8 / 4 / 9 / 11 | No host read. (`instance.workspace_lease` may come from Gateway task lease records; not traced.) `planReads` adds no read for these families, so the report checks 0 resources. The old agent commands (`doctor.inspect-project`, `-production-instance`, `-schedule`, `-private-route`, `-public-route`, `-application-urls`, `-custom-proxy`) exist only in `apps/gateway/resources/agent/frozen-commands.json`. |

### Reads Swamp makes today (the `planReads` list)

| Read | argv | Root? |
| --- | --- | --- |
| firewall | `/usr/sbin/ufw status numbered` | yes (`sudo: true`) |
| platform | `uname -sm` | no |
| disk | `df -Pk /` | no |
| agent | `systemctl show --property=LoadState,ActiveState,SubState,NRestarts -- orbit-agent.service` | no (flag unset) |
| role service | same `systemctl show` for each service of each active role: `caddy`, `php8.5-fpm`, `wg-quick@orbit`, `dnsmasq`, `docker`, `incus`, `orbit-websocket` (`NodeRoleServiceCatalog`) | no |
| process (systemd) | same `systemctl show` for `orbit-process-{id}-{name}.service` | flag set, not needed |
| process (docker) | `docker container ls --all --filter name=^/NAME$ --format '{{.Names}}\t{{.State}}'` | yes (`sudo: true`) |
| process awake marker | `test -e /…/app-instance-{id}.awake` (`RuntimeHibernation::awakePath`), only for hibernating dev Processes | flag set |
| problem logs | `python3` `logs.py` reads the Gateway's `storage/logs` with a cursor | no |

Registry-only checks make no host read: `node.lifecycle_not_active`, `role.lifecycle_not_active`, `firewall.lifecycle_not_active`, `node.task_vm_parked`, `node.agent_view_stale`. The last one already reads the Gateway's agent view (`DoctorObservations`).

## 2. What orbit-agent observes and reports today

### Continuous (the observer service, `apps/agent/src/main.rs`)

The observer joins Reverb as `presence-node.{id}` and keeps running. It listens on no port.

| What | Source | Cadence | Event |
| --- | --- | --- | --- |
| Liveness | timer | 5 s | `client-heartbeat` |
| `orbit-process-*` systemd units: name and `ActiveState` only | D-Bus `ListUnits`, plus `PropertiesChanged`, `UnitNew`, `UnitRemoved` signals | on change (250 ms merge), 30 s poll | `client-process` |
| Full unit list and Docker `available`/`absent` | the same | every 60 s (`SNAPSHOT_INTERVAL`), on join, on request | `client-snapshot` (chunked) |
| `orbit-process-*` containers and their state | `docker.sock` watcher | on change | `client-process` |
| Git state of task checkouts (head, branch, dirty, commits, diff) | libgit2 on a Gateway-named path list | check 2 s, full read 30 s, list 60 s | `client-workspace(s)` |
| Agent version | join query | on join | presence |

Filter: `process_unit_name` keeps only names that start with `orbit-process-`. No role service, `orbit-agent.service`, disk, OS or firewall fact leaves the Node.

### On-demand, read-only

Live log streams (`logs/*`: journal, Laravel log, Docker) run while someone watches, on `presence-node-logs.{id}`. They send lines, not state.

### On-demand command mode (not the observer)

`orbit-agent command <name>` starts over SSH (`sudo -n`) and exits. None of these feed the Gateway view:

| Files | Commands | Mutates? |
| --- | --- | --- |
| `commands/health.rs` | `http.await-healthy` (curl poll) | no |
| `commands/caddy_observation.rs` | `caddy.check-addresses`, `caddy.read-live` (root) | no |
| `commands/firewall/{native,inventory}.rs` | `firewall.inspect` (root): runs `ufw status numbered`, reads `/etc/ufw/user.rules`, `user6.rules`, parses both | no (other firewall commands mutate) |
| `commands/metrics_runtime.rs`, `metrics_exporter.rs`, `metrics_publication.rs`, `service_metrics.rs` | install and manage exporters and scrape rules | yes (`metrics.runtime-health` does not) |
| `commands/doctor_*.rs`, `process_doctor.rs`, `php_doctor.rs` | legacy `doctor.inspect-*` | no, but uncalled |

So the files named in the brief (`health.rs`, `caddy_observation.rs`, `metrics_*`, `service_metrics`, `firewall/inventory.rs`) are command-mode code. The strip pass can reuse their parsers, but they are not part of the continuous stream.

### Where the Gateway stores it

- `AgentViewSubscriber` (`php artisan orbit:agent-view`, one WebSocket to Reverb) feeds `AgentChannelState::apply`, which validates events: `MaxUnits` 4096, `MaxWorkspaces` 64, unit names must match `orbit-process-N-name`, status `[a-z][a-z-]{0,31}`.
- `CacheAgentStateView` writes a file cache under `ORBIT_HOME/cache/agent-view`, key `agent-view.node.{id}`: `received_at`, `agent_at`, `sequence`, `docker`, `units`, `workspaces`, `logs`. TTL is 60 s (`NodeTtlSeconds`).
- Freshness uses only the Gateway clock: `received_at` within 15 s (`FreshSeconds`). `agent_at` is stored but not used.
- `DoctorObservations::forNode` hands this view to doctor (`units`, `docker`, `workspaces` when fresh). Only `node.agent_view_stale` uses it today.
- `ListLiveFirewallRulesAction` (`firewall:live:list`) is not agent-fed yet. It returns every desired rule with `match` `unverifiable` and never reads over SSH.

Gaps in the store for the new model: the 60 s TTL deletes an observation, so "last known value with `observed_at`" is not kept; there is no per-fact `observed_at`; and only unit status strings fit the schema.

## 3. Gap table

"Agent today" is yes / partly / no. "Root?" is whether the agent needs root, or a capability, to observe the fact. The new reads must run in the observer unit (root, no capabilities, `ProtectSystem=strict`, read-only view of the file system).

| # | Doctor check (codes) | Reads today | Agent today | What the agent must add | Root? | Gateway reads from |
| --- | --- | --- | --- | --- | --- | --- |
| 1 | Firewall backend and rules (`firewall.backend_inactive`, `rule_missing`, `rule_mismatch`) | `sudo ufw status numbered` | no (only command `firewall.inspect`) | Run `ufw status numbered` on a timer (say 60 s). Send backend state and parsed v4 rows, plus `observed_at`. Reuse `inventory::live`. | `CAP_NET_ADMIN` (see §4) | New observation key `firewall`. Replaces the `unverifiable` result in `ListLiveFirewallRulesAction`. |
| 2 | Firewall stored rules (`firewall:live:list` "stored" view) | agent command `stored` | no | Optionally send `/etc/ufw/user.rules` and `user6.rules` parsed rows. | no (root-owned `0640`, owner read) | Same `firewall` observation. |
| 3 | Firewall lifecycle (`firewall.lifecycle_not_active`) | registry | n/a | nothing | no | Registry (`node_firewall_rules`) |
| 4 | Platform (`node.platform_mismatch`) | `uname -sm` | no | Send OS from `std::env::consts::OS` once per snapshot. | no | New `node` observation |
| 5 | Architecture (`node.architecture_mismatch`) | `uname -sm` | no | Send machine architecture (`ARCH`/`uname`) in the same snapshot. | no | `node` observation |
| 6 | Root disk (`node.disk_low`) | `df -Pk /` | no | `statvfs("/")` each snapshot: size KiB, free KiB. Doctor rule stays: free < 2 GiB or < 10 %. | no | `node` observation |
| 7 | Agent freshness (`node.agent_view_stale`) | Gateway view | yes (heartbeat 5 s) | nothing | no | `CacheAgentStateView` (`received_at`, 15 s) |
| 8 | Agent running (`node.agent_missing`, `agent_inactive`) | `systemctl show orbit-agent.service` | partly: a heartbeat proves "active". A dead agent cannot report its own death. | Nothing. Under the new rule, no fresh heartbeat is "unverifiable", not `agent_inactive`. Drop or merge these two codes; `FleetNodeVerifier` repair keeps its own trigger. | n/a | Freshness of the view |
| 9 | Agent binary and secret (`node.agent_binary_mismatch`, `agent_secret_mismatch`) | fleet verify (Swamp) | partly: version with digest is in presence | Report `reported_version()` (already includes the digest). | no | Presence/version in the view (kept, not in `AgentNodeView` today) |
| 10 | Role service active (`role.services_inactive`) | `systemctl show` per role service | partly: D-Bus `ListUnits` already runs, but the filter keeps only `orbit-process-*` | Widen the filter to the role services (`caddy`, `php8.5-fpm`, `wg-quick@orbit`, `dnsmasq`, `docker`, `incus`, `orbit-websocket`). Send `LoadState`, `ActiveState`, `SubState`. A unit missing from `ListUnits` = not loaded. | no (D-Bus read) | `units` (widen `AgentChannelState` name regex and `MaxUnits`) |
| 11 | Process systemd state (`process.state_mismatch`, `runtime_missing`) | `systemctl show` on `orbit-process-N-name.service` | partly: `ActiveState` only; absent entry reads as `inactive`, not "missing" | Add `LoadState` so "not found" is distinct from "inactive". | no | `units` → `AgentNodeView::status()` |
| 12 | Process crash loop (`process.crash_loop`) | `ActiveState=activating` and `SubState=auto-restart` | no: `SubState` is not sent | Send `SubState` (column 5 of `ListUnits`; `NRestarts` optional via property read). | no | `units` |
| 13 | Process docker state (`process.state_mismatch`, `runtime_missing`) | `sudo docker container ls --all …` | partly: watcher reports `orbit-process-*` container state and `available`/`absent` | Verify the watcher lists stopped containers (`created`, `exited`) and sends absence explicitly. Send nothing new if it does. | docker socket access (agent is root) | `units` `docker:` + `docker` flag |
| 14 | Process awake marker (hibernation `state_mismatch` exemption) | `sudo test -e …/app-instance-{id}.awake` | no | Stat the awake-marker directory (`RuntimeHibernation::MarkerDirectory`) and send the set of `app-instance-*.awake` names. | root only if the directory is not world-readable (to verify) | New `hibernation` observation |
| 15 | Node/role/task-VM lifecycle (`*.lifecycle_not_active`, `node.task_vm_parked`) | registry | n/a | nothing | no | Registry |
| 16 | Task VM host audit (`node.task_vm_orphaned`, `missing`, `stray_*`) | Gateway job via `IncusHost` | no | Send Incus instance names (project `tvm-*`) on task VM hosts. Today the sweep job reads Incus itself. | root or `incus` group | New `incus` observation |
| 17 | Problem log scan (collector) | `logs.py` over SSH on the Gateway | partly: Laravel log reader exists only for live streams | A continuous tail that sends exception class, frame, request id, cursor. Decide whether the collector belongs in doctor at all. | no | `ProblemCollector` |
| 18 | Node network and DNS (`node.private_dns_*`, `public_dns_unreachable`, `wireguard_ip_mismatch`) | none today (old `doctor.inspect-node`) | no | Addresses (`ip -4 addr`), `wg show` summary, resolver state. Define per code before building. | `wg show` needs `CAP_NET_ADMIN` | New `network` observation |
| 19 | Role prerequisites (`role.packages_missing`, `caddy_version_unsupported`, `caddy_build_drift`, `php_pool_directory_missing`, `vpn_*`, `dns_projection_mismatch`, `firewall_projection_mismatch`) | none today (old `doctor.inspect-role-runtime`) | no | Installed package versions, `caddy version`, live Caddyfile digest (`/etc/caddy/Caddyfile`), PHP pool dir, `wg` and dnsmasq config digests. | Caddyfile read: root | New `role` observation |
| 20 | Project and Instance checkouts (`project.*`, `instance.*` source, layout, release, env, FPM, Caddy, TLS) | none today (old `doctor.inspect-project`, `-production-instance`) | partly: task checkouts only (Git state of 64 paths) | Extend the Git observer to the recorded Project and Instance paths; add release symlink, `.env` digest, FPM pool, Caddy fragment digests. | root (checkouts belong to `orbit`; libgit2 owner check already off) | `workspaces` plus new `instance` observation |
| 21 | Schedules (`schedule.*`) | none today (old `doctor.inspect-schedule`) | no | Timer and service unit state, artifact files, permissions, calendar. | no for `ListUnits`; root for protected command files | New `schedule` observation |
| 22 | Tools (`tool.*`) | none today | no | Installed versions per manager (apt, Homebrew, Vite+). | no | New `tool` observation |
| 23 | Database connections and servers (`database_connection.*`, `database_server.*`) | none today | no | Needs credentials or ACL files; this is not a pure host fact. Decide if it stays a Gateway or Swamp probe. | root (Valkey ACL file) | undecided |
| 24 | Route checks (`route.*`, `instance.public_*`, `private_*`) | none today (old `doctor.inspect-*-route`) | no | Live Caddy config per Route, certificate expiry, listener state, ufw rule per Route. Compares to intent in the Gateway. | root (Caddyfile, certificates) | New `route` observation |
| 25 | Comparison logic | `compare()` in `doctor.ts`, `inspectionResult()` in TS | n/a | Not an agent task. The Gateway must compare observations to intent in PHP (disk rule, unit rules, `ufw` row matching incl. `exact`/`drift`/`missing`/`unmanaged`). | n/a | Gateway PHP (new) |

Rows 1–15 are today's doctor. Rows 16–25 are catalog codes with no current Swamp producer; the observer pass should decide per row whether to build, drop or keep them elsewhere.

## 4. ufw specifics

### Who the agent runs as

`NodeAgentSshExecutor::unit()` (`apps/gateway/app/Infrastructure/Nodes/NodeAgentSshExecutor.php`) writes `orbit-agent.service`:

```
User=root
Group=root
CapabilityBoundingSet=
NoNewPrivileges=yes
ProtectSystem=strict
PrivateTmp=yes
MemoryMax=128M
RestrictAddressFamilies=AF_UNIX AF_INET AF_INET6
```

`docs/reference/node-agent.md` agrees: "the agent runs as `root`" and reads `orbit`-owned checkouts with the libgit2 owner check off. The empty `CapabilityBoundingSet` means root has no capabilities: it still passes owner checks on root-owned files, and it passes ufw's `uid 0` check.

Command mode is different. It starts through SSH as the managed `orbit` user with `NOPASSWD:ALL` sudo and runs with full root capabilities. That is how `firewall.inspect` and the doctor SSH read get `ufw` today.

### How the observer can read ufw without acting

- **A sudo rule does not fit this unit.** The agent is already root, so it needs no sudo. `NoNewPrivileges=yes` stops any setuid gain. A sudoers line such as `orbit-agent ALL=(root) NOPASSWD: /usr/sbin/ufw status numbered` would work only if the observer moved to a dedicated unprivileged user. That change would also remove its access to `docker.sock`, root-owned logs and `orbit`-owned checkouts. Not recommended.
- **Recommended: one capability.** Set `CapabilityBoundingSet=CAP_NET_ADMIN` and `AmbientCapabilities=CAP_NET_ADMIN`, and run only the fixed argv `/usr/sbin/ufw status numbered`. `ufw` reads `/etc/ufw/*.rules` and `ufw.conf` as root, and asks `iptables` about the live chains, which needs `CAP_NET_ADMIN`. The agent code sends no argument from the Gateway, so it cannot change a rule. The capability itself could change rules, so "observer only" stays a code and review rule, as it is now for the whole binary. **Verify before building:** run the unit with an empty set on a Node and check whether `ufw status numbered` fails. If it succeeds with no capability, keep the set empty.
- **Fallback without a capability.** Parse `/etc/ufw/ufw.conf` (`ENABLED`) and `/etc/ufw/user.rules` / `user6.rules` as root. This reports configured rules, not kernel state, so a rule that was removed from the kernel but not the file would pass. The agent already separates "stored" from "live" (`native.rs` `stored()`), so the Gateway would show only "stored".
- **Alternative: a separate root helper unit** (a systemd timer) that writes `ufw status numbered` to `/run/orbit/ufw-status` for the agent to read. It adds a second privileged unit, and the agent would report the file's mtime as `observed_at`. Not preferred.
- Keep the existing bounds when reusing `native.rs`: stdout 1 MiB, stderr 64 KiB, 30 s timeout, `ufw` absent = backend `absent`, non-zero exit = unverifiable.
- IPv4 only: `inspection-result.ts` drops `(v6)` rows and sets `family: "v4"`; the new parser must keep that rule or doctor changes its result.

## 5. Notes for the strip pass

1. Widen the report schema from "unit name → status" to typed facts, each with `observed_at` set by the agent (Gateway `received_at` stays the freshness clock). Chunking limits (`PUSHER_FRAME_LIMIT`) apply.
2. The Gateway store needs retention for the last good observation. The 60 s TTL turns a stale fact into "missing", which cannot be told from "never observed". Pick: keep the value and mark stale, or keep TTL and call both "unverifiable". The decision text says the second is acceptable.
3. `firewall:live:list` and `orbit doctor` become GET-only readers. The `firewall:inspect` command is removed; an on-demand refresh would ask the agent for a snapshot (`Route::Snapshot` already exists in the observer loop).
4. The `doctor.inspect-*` agent commands and the SSH reads in `planReads` are removed together with the comparison move (row 25). Do not delete `inspect-*` command code before the observations in rows 16–24 exist or are dropped on purpose.
5. Facts that need credentials (row 23) do not fit "agent observes host state" and need a separate decision.
