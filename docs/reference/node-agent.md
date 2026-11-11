---
title: "Node agent"
description: "What orbit-agent observes on a managed Node, how the Gateway runs typed commands through it, how it connects and authenticates, how the Gateway delivers and installs it, and how Doctor checks it."
covers:
  - apps/agent/**
  - apps/gateway/app/{Infrastructure,Domain}/AgentView/**
  - apps/gateway/app/Infrastructure/Nodes/{NodeAgentSshExecutor,NodeAgentFootprint}.php
  - apps/gateway/{app/Infrastructure/AgentCommands/**,database/migrations/2027_01_02_000000_add_agent_failed_pin_to_nodes.php}
  - apps/gateway/app/{Http/Controllers/Api/AgentRealtimeController,Actions/Gateway/BootstrapGatewayAction}.php
  - {apps/gateway/app/Http/Middleware/RequireNodeAgentSecret.php,swamp/workflows/workflow-operation-node-agent-rekey.yaml}
  - apps/gateway/app/{Domain/Nodes/{NodeAgentRekey*,NodeConverge*},Actions/Nodes/StartNodeConvergeAction,Console/Commands/RekeyNodeAgentCommand}.php
  - apps/gateway/{database/migrations/{2026_12_03_000000_allow_cold_recipe_image_rebuilds,2027_01_11_120000_create_node_facts_table}.php,app/Models/NodeFact.php,app/Data/Nodes/NodeAgentData.php,app/Domain/NodeFacts/**,app/Actions/NodeFacts/**}
---

# Node agent

The Gateway reuses its bounded TLS WebSocket transport to read [Swamp run output](/reference/tasks#swamp-run-output), with a separate read-only credential. This read contacts Swamp on Services directly; it sends no command to the Node agent or task VM. The observer keeps its existing message limits.

`orbit-agent` is a small Rust program on every managed Linux Node. It observes Node state, runs typed commands, and supplies fixed service entry points.

The **observer** is a long-running service. It reports that it runs, the state of the Node's Orbit Processes, and the Git state of the Node's task checkouts. While someone watches a log live, it also reads that log and sends the lines to the Gateway. The web app shows these reports live. The Gateway keeps a [view](#gateway-view) of them to skip repeated SSH reads.

Native `node.add` fetches the pinned Linux binary and writes the observer configuration, public CA and mode-0600 secret through official cfgmgmt resources. The unit exposes the validated application root read-only. Admission renders that scope without contacting the Node. The Gateway records the secret verifier after the native Node check passes.

**Command mode** runs one named Node action, such as `agent.install`, and exits. The Gateway starts it over SSH, or as a local process on its own Node. See [Commands](#commands).

Fixed **service entry points** run under their own Linux systemd units. `service schedules-run <uuid>` reads the protected Schedule command file and executes its project command as the service user. `service schedules-complete <uuid>` runs as root, reads only the pinned CA, and reports completion. It runs no project code and reads no agent secret. [Schedules](/reference/schedules#node-timers) owns their units and execution context.

The observer listens on no port. Nothing reaches command mode over the network: only a process that SSH or the Gateway starts runs it. To build and check the agent, see [Bun and Rust projects](/reference/bun-and-rust-projects#node-agent).

The agent observes the Node. Swamp applies the network part after the agent footprint checks, and verifies a repaired tunnel with a handshake check.

Persistent Node workers are installed by `agent.configure` from the admitted Node footprint. They run as the Node's configured user with its existing sudo, use native enrollment and a persistent systemd unit, and keep a stable private cache. See [per-Node workers](/reference/node-provisioning#per-node-swamp-worker) for enrollment, placement and activation.

## Where it runs

The Gateway installs the observer only on active managed Linux Nodes with a WireGuard address and a pinned SSH host key. Only those Nodes hold an agent secret. [Exporter selection](/reference/metrics#exporter-selection) uses the same boundary. The observer needs no role.

A macOS Node has no observer, agent secret or LaunchAgent. Native enrollment reads its platform and existing tunnel address through pinned SSH. It installs no agent binary. Tool operations keep the enrolled account and its existing package-manager scopes.

Task VM host preparation runs through the shared Ansible phase of a [base refresh](/reference/compute-drivers#prepare-an-incus-host). The agent observes Incus state; host preparation uses the pool and network intent of the refresh.

In disposable proof fleets, each batch installs the tested checkout's frozen pin with checksum verification before cases start. After installation, it stops all three observers, clears their old presence versions and host receipts, starts them, and waits for each expected version with its binary digest and a new host fact. An installed checksum alone does not prove that a reporter reached the Gateway. See [proof preparation](/reference/verification-cases).

## What it observes

The agent watches two sources and reports each Orbit Process unit or container by its full name, such as `orbit-process-42-web`.

| Source | What it watches | Reported `runtime_state` |
| --- | --- | --- |
| systemd over D-Bus | Units named `orbit-process-*.service` | The unit's `ActiveState`, such as `active`, `inactive`, or `failed`. A missing unit reads as `inactive`. |
| The Docker socket `/run/docker.sock` | Containers named `orbit-process-*` | The container state, such as `running`, `exited`, or `restarting`. A missing container reads as `exited`. |

The values use the same words as the `runtime_state` field of the Process API. The agent does not know which Process owns a name. The Gateway matches the name to a Process, so a candidate container such as `orbit-process-42-web-candidate` never matches. The Process presence report carries no CPU or memory metrics; the agent sends them as facts.

Without Docker, the agent watches systemd only and reports Docker as `absent`. It checks for the socket every 10 seconds and sends a new snapshot when it appears. When the Docker event stream ends, the agent reconnects and sends a new snapshot. It merges changes to one unit that arrive within 250 milliseconds and sends only the latest state. It sends a heartbeat every 5 seconds and a complete snapshot at least every 60 seconds.

## Facts

The agent reports facts the Gateway stores and answers reads from, such as Doctor, without reaching the Node. It sends each fact with `POST /api/v1/agent/facts`, one kind per request, over HTTP with its [agent secret](#agent-secret). Facts do not depend on Reverb.

```json
{
  "kind": "host",
  "observed_at": "2026-11-02T18:00:00Z",
  "data": { "os": "linux", "arch": "x86_64", "filesystems": [{ "mount": "/", "size": 85899345920, "free": 52613349376 }] }
}
```

| Kind | Data | Read |
| --- | --- | --- |
| `host` | `os`, `arch`, and the `mount`, `size` and `free` bytes of each data filesystem (ext2–4, XFS, Btrfs, ZFS, F2FS; no snap mounts; at most 64) | every 60 seconds |
| `metrics` | Per-core busy ratios, memory and swap bytes, load averages, uptime, pressure and data disks | every 60 seconds |
| `units` | `docker`, `awake`, and each watched unit and container with its `runtime` and `state`. See below. | every 10 seconds |
| `checkout` | One Git checkout at `<apps_root>/<project>/<name>`, named by its path in `subject`. See [Checkouts](#checkouts). | on change |
| `checkouts` | `paths`: the path of every checkout the agent found under the apps root | on change |
| `packages` | One Tool Manager's packages, named by the manager in `subject`. See [Packages](#packages). | every 60 seconds |
| `ufw` | `installed`, `enabled`, and each rule. See below. | every 60 seconds |
| `env_keys` | One Instance `.env`, named by its path in `subject`: its `kind`, its key names in `keys`, and `testing_tracked`. See [Environment key names](#environment-key-names). | every 60 seconds |
| `accounts` | The local accounts of the Node: each `user`, `uid`, primary `group`, `home` and `shell`, as `/etc/passwd` and `/etc/group` hold them. See below. | every 60 seconds |
| `egress` | One task VM's outside destinations, named by the VM in `subject`. See [Task VM egress](#task-vm-egress). | read every 5 seconds; sent on change, at most every 15 seconds |

`ufw` holds each rule from the `### tuple ###` lines of `/etc/ufw/user.rules` and `user6.rules`: `action`, `protocol`, `port`, `destination`, `source_port`, `source`, `direction` (`in`, `out` or `fwd`), `in_interface`, `out_interface`, `family`, the decoded `comment` (empty for a rule without one) and `app`. A rule the agent cannot read is `{"unreadable": true}`. The agent reads `ufw.conf` and the rules files; it runs no `ufw` command.

`accounts` lists the accounts of `/etc/passwd` in uid order, at most 512, each with the name of its primary group from `/etc/group`. The agent leaves out an entry that has no group, a name that is not a plain account name, or a home or shell that is not an absolute path without control characters. It reads two files and starts no program. Agents before 0.12.0 send no `accounts` fact.

Retained Schedule log reads, Node-timer account resolution, and named-user resolution in `CreateProcessAction` and `UpdateProcessAction` use this fact while the Node agent is online, using the Gateway's [agent liveness](#gateway-view). An absent or non-current fact, an unlisted user, or an invalid account entry returns `schedule.target_unavailable` for Schedules or `process.user_unavailable` for Processes without contacting the Node. The Node's managed user still uses `account.lookup`.

`units` lists each `orbit-process-*` unit, each Process container, and each role service and Process unit from the [watch list](#watch-list). `docker` is `available` or `absent`. A systemd unit also has its `load` and `sub` state; one that is not installed reads `not-found`. `awake` lists the watched awake markers that exist. Agents before 0.10.0 send neither `load`, `sub` nor `awake`.

The Gateway stores a host's `free` bytes rounded down to 256 MiB. Free space moves with every log line, so without the rounding each `host` report would write a row.

The agent sends a fact when it changed, and again every 10 minutes.

`metrics` reads `/proc/stat`, `/proc/meminfo`, `/proc/loadavg`, `/proc/uptime`, `/proc/pressure/*` and data filesystem sizes with `statvfs`. CPU ratios compare per-core counters between readings; guest time is not counted twice. Used memory is total minus available, and used swap is total minus free. Missing kernel pressure data reads as zero. Disks and `host` share one background sampler, with `/` first and pseudo and network filesystems excluded. It streams at most 256 KiB of the mount table and stops at 64 distinct data mounts. An incomplete required reading sends nothing.

Each disk read has a 100 ms deadline. One background pass runs at a time, with at most one outstanding call per mount and 64 tracked calls in total. A timed-out mount keeps its last good values and their `observed_at`, marked `stale: true`. Other mounts keep refreshing. A late success updates that mount and clears its stale flag as soon as it arrives; the agent does not wait for the next 60-second pass. A blocked call is never retried before it returns.

[`node:metrics`](/cli/node/metrics) reads the retained snapshot without contacting the Node. Each metrics disk entry includes `observed_at` and `stale`. Before a mount has a successful reading, its values and observation time are null and it is stale. The `host` fact retains known filesystems with the same observation time and stale flag; it omits a filesystem that has never answered. When a mount disappears, its blocked call keeps its slot until it finishes, so mount changes cannot accumulate blocked threads. Other facts read the latest cached disks without waiting. The agent only reads Node state; it runs no collector program and changes nothing on the Node.

A report the Gateway does not accept, for example while a Node boots before the Gateway answers, is kept and sent again after 1, 2 and 4 seconds, and then every 4 seconds until the Gateway accepts it. A refusal that sending again cannot change, such as a `422` for a kind an older Gateway does not know, is not sent again before the next regular read.

The retry keeps both the data and `observed_at` of the failed read, so delayed delivery cannot make an old snapshot look newly observed. It reads the Node again only at the next regular read. A new [watch list](#watch-list) makes the agent read and send `units` at once.

The Gateway answers `204`. It stores one row per Node, kind and `subject` in `node_facts`. Only `checkout`, `packages`, `env_keys` and `egress` have a subject; the other kinds refuse one and store `''`. Each row keeps the agent's `observed_at` and the Gateway's own `received_at`. A report whose content is unchanged reads one row and writes nothing. A change compares and writes in one short transaction with no remote call inside. A request over 1 MiB fails with `agent.fact_too_large` (413), and invalid data fails with `validation.failed` (422).

### Packages

The [watch list](#watch-list) names each active Tool Manager with the candidate paths of its state. The agent reads the first path that exists and never runs the manager:

| Manager | State it reads | Data |
| --- | --- | --- |
| `apt` | `/var/lib/dpkg/status`, `/etc/apt/sources.list` and `sources.list.d/*.list` and `*.sources`, keys in `/etc/apt/keyrings` and `/usr/share/keyrings` | `packages` with `install ok installed`; `sources` and `keys` as `path`, `sha256` and base64 `content`, each file at most 64 KiB |
| `composer` | `composer.json` and `vendor/composer/installed.json` of the global scope | `roots` it requires, and every installed package |
| `vp` | `bins/*.json` of the Vite+ home | each global package once |
| `brew` | `Cellar/<formula>/<version>/INSTALL_RECEIPT.json` and `Caskroom/<cask>/<version>` | `formulae` with `versions`, `tap`, `on_request` and `dependencies`; `casks` with `versions` |

Each fact also names its `scope`, the path it read. On beast the apt fact is about 270 KB.

[`tool:scan`](/reference/tools#discover-installed-packages) lists these facts, and [`tool:adopt`](/reference/tools#adopt-a-tool) records a Tool from them. Neither reads the Node. A manager without a fact is "not observed yet".

On an Instance host, the `host` fact includes `instance_bridge.error_code` from `/var/lib/orbit/instance-bridge/health.json`. The observer reads only a known public error code; absent state means the host has no refresh policy. Unreadable or malformed state reports `instance.container_bridge_health_unreadable`. Command timeouts retain `instance.bridge_command_timeout`. A concurrent writer returns `instance.bridge_busy` without changing the health file, so that refusal does not become a new observer fact. If bounded ACL readback after an ambiguous request cannot determine the applied state, the fact retains `instance.bridge_state_unresolved`. Doctor reports a failure as `node.instance_bridge_unhealthy`, preserving the observed public code. The [Instance host role](/reference/development-containers#prepare-a-node) owns the file and refresh timer.

### Environment key names

The watch list's `environments` names the `.env` of each Instance on the Node. For each, the agent sends an `env_keys` fact:

| Field | Meaning |
| --- | --- |
| `kind` | `absent`, `regular`, `symlink` or `other`. The agent follows no link. A directory the agent can't reach also reads `absent`, so the Gateway counts only a `regular` file with `keys` as observed. |
| `keys` | The names of the `NAME=value` and `export NAME=value` lines, sorted, at most 2,000. Null when the agent may not read the file, or the file is a link or larger than 1 MiB. |
| `testing_tracked` | Whether the Git work tree that holds the file tracks `.env.testing` beside it. |

The agent never sends a value, and the Gateway stores no other field. Orbit writes a managed `.env` as mode 0640 with group `orbit-env`. The agent's unit holds that group, so it can read those files and no others.

[`env:import`](/cli/env/import) reads private file values through an official Swamp SSH run and a one-shot vault key. `instance.read-environment` is unavailable.

A production `.env` lives in its account's 0710 home: the unit binds each production home back read-only, and Orbit gives `orbit-env` traverse on it with an ACL (`g:orbit-env:--x`), as it gives Caddy traverse on a release. [`env:sync`](/cli/env/sync) checks these names at admission. A production Instance cloned after the agent's last converge is observed once the next converge binds its home. A hand-made 0600 `.env` reports `keys` as null: Orbit has not observed its keys. `agent.configure` creates the `orbit-env` system group before it writes the unit, because a unit that names a missing group does not start. Agents before 0.11.0 send no `env_keys` facts.

### Checkouts

On an app-dev Node, the [watch list](#watch-list) names the `apps_root`. The agent sends one `checkout` fact per Git checkout, so each request stays small and each change writes one row. Beast, for example, has more than 300 checkouts. The `checkout` data holds:

| Field | Meaning |
| --- | --- |
| `owner` | The account that owns the checkout directory |
| `top_level`, `common_dir` | The work tree root and the shared `.git` directory |
| `linked`, `worktrees` | Whether this is a linked worktree, and every worktree path of the repository |
| `origin` | The origin URL, with any embedded credential masked |
| `branch`, `head` | The branch `HEAD` names, null when detached, and the `HEAD` commit |
| `default_branch` | The branch `refs/remotes/origin/HEAD` names, or null |
| `application` | The application directory the agent read: the checkout, or the subdirectory `applications` names for its Project |
| `artisan`, `composer` | Whether `artisan` in `application` is `regular`, a `symlink` or `absent`, and the text of its `composer.json` (at most 1 MiB, or null) |
| `env` | The `kind` (`regular`, `symlink` or `other`), octal `mode` and `owner` of `.env` in `application`, or null when it is absent. The agent reads them with `stat` and never opens the file. Agents before 0.8.0 omit it. |

The agent finds checkouts two levels below the apps root and skips symlinks and hidden names. It watches, with inotify on Linux and FSEvents on macOS, the apps root and each project directory. In each checkout it watches the Git directory, `refs/heads`, `refs/remotes/origin`, and the application directory. Only `HEAD`, `packed-refs`, `config`, a ref, `composer.json`, `artisan` and `.env` count; an edit to another file sends nothing. It reads the checkouts that changed 2 seconds after the last event, and reads all of them at start and every 10 minutes. A full read of 339 checkouts on beast takes about 4 seconds, off the agent's async workers.

After the checkouts, the agent sends the `checkouts` index. The Gateway deletes the `checkout` rows whose path is missing from the new index, in the same short transaction.

### Task VM egress

On a task VM host, the [watch list](#watch-list) names the Incus bridge of the task VMs in `egress`, and on a container host also the bridge of the task containers. The agent then records which outside hosts each VM or container connects to and sends one `egress` fact per guest, named by the guest in `subject`. It runs one observer per bridge, with its own files, offsets and state. The record is a log only: nothing is blocked. The agent reads three kinds of files, starts no program and opens no port.

| Source | Path | What the agent takes |
| --- | --- | --- |
| DNS log | `/var/log/orbit/dnsmasq-<network>.log` | The names a VM asked for (`query[A]`) and the IPv4 addresses answered, paired by dnsmasq's serial number. An address in a CNAME chain counts for the name the VM asked for. |
| Connection log | `/var/log/orbit/task-egress.log` | The sender, destination, protocol and port of each new outbound TCP or UDP connection, from the kernel's `orbit-egress` log lines of this bridge. Private, reserved and multicast destinations are skipped. |
| VM identity | `/var/lib/incus/networks/<network>/dnsmasq.hosts/*` and `dnsmasq.leases` | The MAC and name of each VM from the host files Incus writes. A lease only gives the MAC of an address. The DHCP host name is never trusted. |

Every 5 seconds the agent reads the identity files, then the new part of the DNS log, then the new part of the connection log. It reads each log from a remembered byte offset, at most 4 MiB per file per read.

A log that does not exist yet reads as empty, because rsyslog and dnsmasq create theirs when they first write. A file that is there when the agent first looks is read from its end, so history is not replayed, also after an agent restart. A file that appears later is read once from its start. When a file shrinks or its inode changes, as after log rotation, the agent reads the new file from the start.

The agent keeps state per VM by MAC, so a reused VM name with a new MAC starts fresh. It tracks only the MACs that a host file names, and uses the leases only to find the MAC of an address. It drops the state of a MAC when its host file is gone. It tracks at most 64 VMs. With more host files, it keeps the 64 whose host files it saw appear last.

A connection line names its VM by the source MAC in its `MACSRC=` field. Older kernels print one `MAC=` field with the 14 octets of the Ethernet header instead, and the agent takes the source MAC from octets 7 to 12 of it. `MACSRC=` wins when a line has both. Without either field, the agent finds the VM through the source address and the lease. A line whose MAC field is malformed, or names a MAC that is no tracked VM, is dropped. So is a line without `IN=` of this bridge. A DNS line names its VM through the lease of its client address.

A connection to address A by a VM gives one row for each name that VM resolved to A in the last 30 minutes, or one row with the address when it resolved none. A name's answer time is its latest answer. A DNS answer gives a row with no port or protocol for its name. The agent leaves that row out of the fact once the VM connects to the name.

The agent ignores an answer that is private or reserved. Rows are unique by destination, port and protocol, and sorted by those three, so an unchanged set gives the same fact.

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

| Row field | Meaning |
| --- | --- |
| `destination`, `kind` | A lower-case name (`name`) or an IPv4 address (`address`). |
| `port`, `protocol` | The port and `tcp` or `udp` of a connection. Both are null on a row that only a DNS answer made. |
| `addresses` | The IPv4 addresses of the row in numeric order, at most 8. |
| `sources` | `conn` for a logged connection, `dns` for a DNS answer. A row has one or both. |
| `flags` | `dot` for port 853. `dns` for port 53 to an outside address. `doh` for port 443 to a well-known public resolver such as `1.1.1.1` or `8.8.8.8`. |
| `first_seen` | The agent's UTC time, to the second, when it first saw the row. It never changes. |

The agent keeps at most 1,500 rows per VM. That keeps a full fact below the 1 MiB the Gateway accepts. All VMs together share a budget of 32 MiB for rows, names and answers, so a host full of busy VMs stays well inside the agent's 128 MiB memory limit. A row that the row cap or the budget refuses is counted once in `overflow`, however often the VM repeats it, up to 1,024 distinct refused rows per VM.

The agent also remembers at most 8,192 DNS questions, 1,024 name and address pairs per VM, and 8 names per address. A ninth name replaces the oldest name of its address, and the oldest pair goes first when a VM has too many.

The agent reads every 5 seconds but sends a VM's fact only when it changed, at most once every 15 seconds, and again every 10 minutes. It hands at most 4 facts to the reporter per read, and the rest follow on the next reads. A fact that the Gateway refuses is not sent again before the next change or the 10-minute resync. State lives in memory only. When the watch list has no `egress`, the agent drops all state and reads no file.

The agent runs as root without capabilities, so it reads only files that it owns or that others may read. A file it cannot open is not available, except a log that does not exist yet. While it cannot read one of the other files, the agent keeps its state and sends no `egress` fact. It logs the path and the kind of error, never content, at most once in 10 minutes per path.

The fact holds no URL path, packet content or secret, and the agent never writes row contents to its log. Agents before 0.13.0 send no `egress` fact.

## Task workspaces

The agent reports the Git state of each parent [task](/reference/tasks) checkout on its Node. `task:start` prepares that shared checkout for the parent and its subtasks, so the Gateway can skip `git` over SSH. The agent reads only Git metadata, never file contents or file names, and it starts no program.

### Watch list

The agent asks `GET /api/v1/agent/watch` what to watch, at start and every 60 seconds. A request the Gateway does not answer is repeated after 1, 2 and 4 seconds, and then every 4 seconds:

```json
{
  "data": {
    "services": ["caddy.service", "docker.service", "orbit-process-12-web.service"],
    "awake_markers": ["/dev/shm/orbit/hibernation/instance-31"],
    "apps_root": "/home/orbit/apps",
    "applications": { "operator": "server/web" },
    "tools": [{ "manager": "apt", "paths": ["/var/lib/dpkg/status"] }],
    "environments": ["/home/orbit/apps/operator/default/server/web/.env", "/home/orbit-app-16/.env"],
    "egress": { "network": "orbittask0", "container_network": "orbittaskc0" },
    "workspaces": [
      {
        "instance_id": 31,
        "path": "/home/orbit/apps/orbit/task-58",
        "base": "main",
        "start": "9f2c4be07d1a6c35e8f0b2a4d6c8e0f1a3b5c7d9"
      }
    ]
  },
  "meta": { "request_id": "..." }
}
```

`services` are the services of the Node's roles and the units of its systemd Processes, which the agent reports as [facts](#facts). A named unit is read even when systemd has unloaded it. `awake_markers` are the awake markers of its hibernating Processes, all below `/dev/shm/orbit/hibernation/`.

`apps_root` is the apps path of an active app-dev Node, whose [checkouts](#checkouts) the agent reports; it is null on other Nodes. `applications` names, by Project slug, the application directory inside a checkout when the Project's `root` puts it below the checkout root. `tools` lists every Tool Manager a Linux Node can run, recorded or not, with the candidate paths of its [package](#packages) state.

`environments` names the `.env` of each Instance on the Node: the application directory of a development checkout, or the home of a production account; the agent reports their [key names](#environment-key-names). Only plain unit names ending in `.service` are accepted. Agents released before the observer pass still ask `GET /api/v1/agent/workspaces`, which returns only the `workspaces` list. The Gateway serves it until every Node runs the new agent.

`egress` is absent or null on every Node except a task VM host. On a host, `network` names the Incus bridge of the task VMs, such as `orbittask0`. `container_network` names the bridge of the task container setting on [Node settings](/reference/node-settings#task-container-fields), such as `orbittaskc0`, and is null on a host without containers. The agent accepts only a name that is `orbittask` followed by 1 to 6 lower-case letters or digits, and ignores any other value. Agents before 0.15.0 read only `network`, so they observe only the VM bridge. Without `egress` the agent reads none of the [egress](#task-vm-egress) files and holds no egress state.

`workspaces` holds the Instances on the caller's Node that hold the workspace of an unfinished task: `backlog` or `todo` with an Instance, `reserved`, `running`, `reviewing`, or `settling`. `base` is the Project's default branch. `start` is the Instance's starting commit, or null. The list is empty while Tasks is disabled, and it holds at most 64 entries.

The agent accepts only a normalized absolute path of at most 4,096 bytes that opens as the root of a Git work tree. A linked worktree works. It never opens a path that the list does not name, and never opens a submodule.

### Reading Git

The agent reads Git with libgit2 in its own process, which runs no hooks, filters, or `fsmonitor` programs. The checkouts belong to `orbit` and the agent runs as `root`, so the agent turns off libgit2's owner check.

Every 2 seconds, the agent checks the size, time, and inode of `HEAD`, `index`, `packed-refs`, the current branch ref, the local base ref, and `origin/base`. When one changed, it reads the checkout again. It also reads every checkout every 30 seconds, because an edit to a working file changes none of those files.

| Field | Meaning |
| --- | --- |
| `instance_id`, `base`, `start` | The values from the watch list. |
| `branch` | The current branch, or null when `HEAD` is detached. |
| `head` | The full `HEAD` commit, or null in an empty repository. |
| `dirty` | `true` when the index or working tree differs from `HEAD`, untracked files included. Null when the agent cannot read the working tree. |
| `commits` | Commits reachable from `HEAD` but not from `start`, at most 1,000. Null without `start`. |
| `diff` | `{ files, added, removed, truncated }` as `git diff --numstat origin/base...HEAD` counts it. Null when `origin/base` does not resolve. |

| Limit | Value |
| --- | --- |
| Changed files in `diff` | 5,000. A larger diff has an exact `files` count, no line counts, and `truncated: true`. |
| Lines counted per file | Files up to 1 MiB. A larger or binary file counts as changed with no lines. |
| Submodules | A changed submodule counts as one file with no lines. |
| Branch name | 255 characters. A checkout on a longer branch is left out. |
| libgit2 memory | 8 MiB object cache and 32 MiB mapped pack window. |

A checkout that the agent cannot open or read is left out of its reports, and the Gateway reads it over SSH. The agent publishes the state as `client-workspaces` and `client-workspace` events. [Realtime events](/reference/events#events) defines them.

## Log tails

The agent reads an Instance, Process or Schedule log only while a viewer watches it through a [live log stream](/reference/live-logs). It sends the lines only to the Gateway.

### Stream list

The agent asks `GET /api/v1/agent/log-streams` which logs to read. The list holds at most 16 active streams whose source is on the caller's Node. Each entry has an `id`, a number of first `lines`, and a `source`. The agent fetches the list when it joins its log channel, when the Gateway publishes `log-streams.changed` there, and every 15 seconds while it reads a stream. The event carries no data, so the agent reads only what the HTTPS response names. It stops every stream that the list does not name.

When it cannot fetch the list for 60 seconds, the agent stops every stream and ends each one with `client-log-end` and reason `list_unavailable`. It never reads that stream again.

### Sources

The agent checks each source itself. For a `journal` source it runs read-only `journalctl`; for the other sources it runs no program.

| Type | Fields | What it reads | What it refuses |
| --- | --- | --- | --- |
| `laravel` | `path`: the Instance's [application directory](/reference/projects#application-directory) | `storage/logs/laravel.log`, or the newest `laravel-*.log`. It follows a daily file to the next day's file. | A path that is not normalized and absolute. A link at `storage/logs` or at the file. A file that is not regular, or that `root` owns. |
| `journal` | `unit` | The output of `journalctl --unit {unit} --output short-iso --utc`: the unit's entries, systemd's and root services' messages about it, and its core dumps. | A unit that is neither `orbit-process-{id}-{name}.service` nor the service unit of a Schedule, `orbit-schedule-{uuid}.service`. |
| `docker` | `container`, `process_id` | The container's output through the Docker Engine API. | A name that is not `orbit-process-{id}-{name}`, and a container without the labels `orbit.managed=true` and `orbit.process.id` equal to `process_id`. |

Refusing a file that `root` owns means the agent reads only files that other users may read, inside the Instance root that its unit binds. So a link in a checkout cannot point the read at another user's file. When the agent cannot open or keep reading a source, it ends the stream with `source_unavailable`.

`journalctl` is part of systemd on every supported Ubuntu Node, at `/usr/bin/journalctl`. The agent starts it with no environment and no input, and stops it when the stream stops, so no `journalctl` outlives its stream. When the agent itself stops, systemd stops any `journalctl` that is left, because it runs in the agent's unit. [Live logs](/reference/live-logs#sources) describes how the agent hands over from the first lines to the follow and how it cuts a long entry.

The agent sends the first lines of a stream as one batch, also when the batch is empty. The last `client-log` event of that batch carries `first_done: true`, so the Gateway knows the first lines are complete. A missing log file or a unit without entries gives an empty batch.

The agent redacts each line with the Gateway's secret patterns before it sends it. [Live logs](/reference/live-logs#redaction) lists the patterns and the handling of unclosed PEM blocks. Both implementations scan in linear time and test the same expected bytes from `apps/agent/tests/redaction_cases.json`, including multiline text and long repeated inputs.

| Limit | Value |
| --- | --- |
| Streams | 16 |
| First lines | The stream's `lines`, at most 256 KiB |
| Line length | 8 KiB. A longer line is cut and ends with `[truncated]`. |
| Events | One `client-log` event per stream every 250 milliseconds at most, each under 10,000 bytes |
| Rate | 32 KiB per second per stream, with a 256 KiB burst, and 256 KiB per second for the agent |
| Read ahead | 1 MiB per read. A file more than 4 MiB behind skips to its newest 64 KiB. |
| Journal | One `journalctl` per stream. The agent keeps at most 512 KiB of one printed line and makes at most 256 KiB of lines from one entry. |

The agent drops a line that exceeds a rate and reports the count in its next event. It sends log events on a second presence channel, `presence-node-logs.{id}`, as member `agent.{id}`. Only the agent and the Gateway's subscriber join it. [Realtime events](/reference/events#log-channels) defines the events.

Gateway bootstrap configures its own observer after its web service and private DNS are ready. It uses the same agent runtime as Node convergence, records the secret hash, and fails at `agent-install` if the observer cannot be configured. Repeating bootstrap converges that same service. Bootstrap also records the `swamp.orbit` Route and the `gateway-queue` Redis connection of the machine, as [Gateway bootstrap prerequisites](/reference/node-provisioning#gateway-bootstrap-prerequisites) describes.

## Commands

Gateway releases and desired fleet state use the pinned `cli-v0.9923.0` delivery, the observer pass agent release that reports checkouts, `.env` stat and key names, packages and UFW. Its `orbit-agent contract` output is stored beside the pinned manifest in `apps/gateway/resources/agent`. The Gateway reads that contract at runtime. `bin/check-frozen-agent-commands` checks production Gateway and `swamp/` call sites and agent descriptors against it. The PHP job runs it with the published binary's digest check, and the Swamp shape gate runs it for `swamp/` changes. Missing commands must move to native Swamp code; changing the Rust registry does not change the pinned delivery until a reviewed commit moves the pin.

The Gateway runs every Node action through one runner, `AgentCommandRunner`. The recorded Gateway serving Node uses local execution, including during role reconvergence. `orbit:bootstrap` records that identity, and with `--operator-ip` the roleless `operator` Node, from the Gateway's own code and runs no agent command; until it has, the Node whose Gateway role serves the site runs locally. Other Nodes use pinned SSH. It sends a command name and typed input, never program source.

```text
[sudo -n] /usr/local/bin/orbit-agent command <name>
```

A Gateway architecture check scans core and extension PHP for process, SSH, and agent transport dependencies. Only the command transports, Gateway control-plane processes, command rendering, and the excluded Tasks and private DNS paths may use those dependencies. New Node behavior must call `AgentCommandRunner`; adding another executor fails the check.

The `php.configure-runtime` command accepts exactly the six managed OPcache directives and two positive PHP size quantities at most 1 GiB. The upload limit must not exceed the POST limit. An absent FPM binary makes the command a no-op before any file write. It verifies all eight values with `php-fpm -i`, then reloads valid active masters when the module changes. See [request and upload limits](/reference/php-runtime#request-and-upload-limits).

Development profile inspection and URL writing take the Node’s recorded managed username. Each command resolves the account and checks file ownership locally; the Gateway makes no separate account lookup for these actions.

Standard input starts with one JSON envelope line. Standard output carries one JSON result. Redacted logs go to standard error.

Commands with binary input declare an `input_max_bytes` bound in the exported contract. The envelope's `stream_bytes` gives the exact number of raw bytes after its newline. The agent checks its pin before reading them. The supervised process receives them into an unlinked private file and checks the exact size and end of input before invoking the command. Short input, excess bytes, and failed reads refuse with `agent.binary_input_invalid`. Git packs, SQLite snapshots, and source archives remain binary; their bytes never enter JSON, logs, or diagnostics. Each domain command validates and consumes its own data.

The Gateway sends the envelope line followed by the staged file through one process input stream. It keeps the file open and rewinds it after a version mismatch, without making another disk copy. The caller owns the private staging file and leaves its contents unchanged until the command finishes. Transfer archives stay in `storage/app/transfer-staging`, including during upload.

| Envelope field | Meaning |
| --- | --- |
| `agent_sha256` | The SHA-256 of the binary that the Gateway pins for this Node |
| `deadline_ms` | The time left for the call. The command's own bound applies too |
| `stream_bytes` | Exact binary body size; required only for commands that declare binary input |
| `input` | The command's typed input. Unknown fields refuse with `agent.invalid_input` |

The result is `{"ok": true, "data": {...}}` or `{"ok": false, "error": {"code": ..., "message": ..., "details": {...}}}`. A valid result exits 0, a refusal included.

### The frozen check

The process that executes a command first checks the SHA-256 of its own executable against `agent_sha256`. It does this before it looks up the name, parses the input, or reads binary bytes. On a mismatch it answers `agent.version_mismatch` with the expected and observed digests. From the first released command-mode agent, the envelope line framing, its hash field, this check, and that answer never change between versions. Every released command-mode agent answers a newer Gateway in a way the Gateway can act on. Unpublished SPIKE prototypes are reconverged before this contract is frozen.

### Privilege and supervision

Each command declares one privilege per platform. The runner reads it from the contract, never from caller input. The command checks its effective identity before any change.

Linux keeps the managed account's existing `NOPASSWD:ALL` sudo rule. The named-command boundary is a code rule, enforced by architecture tests and command definitions. It does not narrow that account's OS authority. Commands use filesystem and OS APIs or fixed executable arguments. Project hooks, Git, builds, and package tools run as their recorded owner, without root trust files or secrets in their input or environment.

| Privilege | Linux | macOS |
| --- | --- | --- |
| `account` | The managed account | The enrolled account |
| `owner` | Starts as root through `sudo -n`, and drops to the recorded owner before project code | The enrolled account |
| `root` | `sudo -n` | Not declared, so the command refuses with `agent.platform_unsupported` |

A read runs in the started process and takes no lock. On Linux, a mutation runs in its own transient service, `orbit-command-<name>-<random>`, with `KillMode=control-group` and `RuntimeMaxSec` set to its bound. Its control group holds every child, so a daemon or `setsid` child stops with the command. The supervised process checks its own executable again. It takes an exclusive `flock` on its target, when the command has one, then on each shared resource in sorted order, then on the update lock when it needs it. Lock files are close-on-exec, so project code never inherits one.

Each lock records the transient service's control group before the command starts its work. A successor first takes the file lock, then waits for that previous control group to become empty or disappear. This keeps surviving children protected while systemd stops them, including after the command process crashes. A lock wait ends 250 milliseconds before the command's deadline, so a busy lock refuses with its own code, not with `agent.deadline_exceeded`. An unreadable or invalid control-group record refuses with `agent.lock_unavailable`; a lock or group that stays busy until then refuses with `agent.target_busy`.

Target and resource locks live in `/run/orbit-agent/locks/<uid>`, one directory per account. The root process that starts the mutation creates it, owned by the account with mode 0700, and passes it to the service in `ORBIT_AGENT_LOCK_DIR`. The supervised process refuses with `agent.lock_unavailable` unless it owns that directory and no one else can enter it. It opens lock files without following links. Only root writes `/run`, so no other account can create, hold, or replace these locks.

A root command and an account command on the same target do not exclude each other. Commands that must serialize a shared mutation use the same privilege and lock key. So both `.env` writers, `instance.write-environment` and `instance.write-development-url`, are owner commands: root holds the `environment:` lock, and the write runs as the file's owner.

Every root command that runs `apt-get` or `dpkg` holds the shared `packages` resource. Every command that changes UFW rules holds `firewall`. A mutation with neither a target nor a resource changes only state that one Gateway operation owns at a time, such as one Instance's checkout during its own deploy.

### The contract

A command name is `<namespace>.<action>`, and an error code is `<namespace>.<reason>`. A namespace is singular, as in the CLI: `instance.inspect-laravel-routes` refuses with `instance.laravel_routes_unreadable`. An action can name a collection, such as `incus.images`. Four namespaces end in `s` but are not plurals: the mass nouns `analytics` and `metrics`, the product `incus`, and the extension slug `documents`. The Gateway Node's start-hook drop-in runs `orbit-agent service documents.prepare-cleanup`, so that name stays with the extension slug. Service entry points are not commands: `schedules-run` and `schedules-complete` are written into every Schedule's units on its Node, so they keep their names. A Rust test refuses any other namespace whose last word ends in one `s`; `process` ends in two.

Rust defines each command: its input, output, error codes, bound, privilege, and whether it mutates. `apps/agent/contract/commands.json` is written from those definitions, and a Rust test fails when the file is stale. During the freeze, the Gateway reads privileges and bounds from `apps/gateway/resources/agent/frozen-commands.json`, captured from the published binary. `ORBIT_AGENT_CONTRACT` can select a different contract file; a relative path resolves from the Gateway directory. Rewrite the file with `ORBIT_AGENT_WRITE_CONTRACT=1 cargo test --lib -- contract_file` in `apps/agent`.

`apps/agent/contract/schemas.json` holds the JSON Schema of each command's input and output, derived from the same Rust types. `ORBIT_AGENT_WRITE_CONTRACT=1 cargo test --lib -- schemas_file` rewrites it. Rust tests keep every input object closed to unknown fields in the schema, and decode a generated input of every command with an unknown field at each level to prove the real decoder refuses it.

The PHP test fakes refuse a command, error code, or binary stream that the contract does not declare. They check each input in its wire form, where `{}` and `[]` differ, and each answer against the output schema. A test of the Gateway's own guard against a malformed result says so with `answerMalformed`.

These errors can come from any command:

| Code | Meaning |
| --- | --- |
| `agent.version_mismatch` | The binary is not the pinned one. The runner installs the pin |
| `agent.binary_input_invalid` | Binary input is short, has excess bytes, or cannot be read |
| `agent.envelope_invalid` | The envelope is not one JSON object with a valid digest, deadline, and input |
| `agent.unknown_command` | The pinned binary has no command with that name |
| `agent.invalid_input` | The input does not match the command |
| `agent.identity_mismatch` | The command does not run as its privilege requires |
| `agent.platform_unsupported` | The command does not run on this platform |
| `agent.deadline_exceeded` | The command did not finish before its deadline |
| `agent.supervisor_failed` | A mutation's service ended without a result |

A transport loss, a missing result, a malformed result, a timeout, and an undeclared error code are runner failures: `agent.runner_failed` with a `kind`. A lost result never proves that no change happened, so the runner sends a mutation once.

### Transports

The runner picks the transport from the Node or build guest. Every adapter carries the same arguments, envelope, and result.

| Node | Transport |
| --- | --- |
| A managed Node | SSH to its WireGuard address, as its enrolled account, with its pinned host key |
| The Gateway's own Node | A local process. Mutations still change the Node, because each runs in its own transient service outside the worker's read-only view |
| A Node being admitted | Its pinned bootstrap SSH connection, until managed WireGuard SSH is ready |
| An Incus build guest | `incus exec` through its managed host, before guest networking or SSH exists |

Native bootstrap and image consumers receive typed public input from `orbit:node-recipe`: the Node account and roles, bootstrap input, stored machine recipe, and frozen observer delivery. Swamp supplies the system programs and runs their effects. The export contains no agent actions, executable programs or command fingerprints.

Only binary staging may change a Node outside a named command. First admission uses a fixed `uname -sm` staging probe because no agent exists yet. It validates the platform and architecture before installing a candidate. A root bootstrap login runs root commands directly; other Linux logins use passwordless sudo. The installer owns atomic replacement and the cleanup of each candidate it runs from.

### Ownership and exclusions

The Gateway owns validation, authorization, records, operations, placement, and error mapping. Rust commands own Node execution and its tests. [Extensions](/reference/extensions#add-a-plug-in-point) own their Rust command modules, startup hooks, and compiled services; one agent binary dispatches them. There is no dynamic loader or second command runner.

Tasks workspace scripts retain their [existing runner](/reference/remote-programs). The private DNS listener and its publication scripts remain for the separate [DNS migration](/decisions/0219-answer-cluster-names-from-a-dns-on-the-router). Python outside the Gateway is outside this port. The Gateway's own Artisan entry point and OPcache adapter are control-plane code. Static configuration, templates, and certificates remain data.

Operations own recovery. Mutations accept an `effect_key` as `op-<operation id>.<step key>`. Executor transports always supply it after contract authorization. After acquiring command target and resource locks, the shared command layer writes a private intent on the target before the effect and a durable receipt before returning. A repeated key with the same command, input and Operation nonce returns the saved result. A different command or input is refused with `agent.effect_conflict`. An interrupted intent has unknown outcome and is never executed again under that key.

`agent.effect-receipt` reads `{command, effect_key, owner?}` without changing target state. It returns `{command, effect_key, state, exit_code, result_digest, finished_at, result, sanitized, effect_nonce, effect_created_at}`. Status is `succeeded`, `failed`, `running`, or `unknown`; an absent or unfinished receipt has null result, digest, and time. The original invocation supplies protected masks in transient envelope metadata. Before saving a receipt, the shared layer removes those values from the result and discards the masks. The receipt keeps a sanitization flag. Missing metadata refuses execution, and an unproven stored result cannot be recovered. The result is the sanitized agent outcome, including the command's own refusals. The Gateway redacts and filters it before storage or response.

Root receipts live in `/var/lib/orbit-agent/effects`; account receipts live under the account's recorded home in `.orbit/effects`. Directories are private and files have mode 0600. Receipts keep only an input fingerprint, never the input itself. Bounded binary input contributes its digest without moving the consumer's input cursor.

Existing named remote programs reserve and complete their receipts through `agent.effect-reserve` and `agent.effect-complete`. These commands use the same store and execute no program. A reservation binds the program name and input fingerprint. Only its first successful caller may start the program. A lost reservation or program reply leaves an unknown intent; it cannot authorize a repeat. Completion stores the program result before the runner returns. Cancellation finishes the current operation step and starts no next step. Detached Tasks checks and topologies keep their own supervision.

Effect receipts and their per-key locks have a 30-day retention period. Mutating keyed commands prune old files in batches under an exclusive store guard, at most once daily unless more files remain. Active commands hold that guard shared, so pruning cannot split a live lock. Read-only receipt commands never prune. An original effect identity older than the retention period is refused even when its files are absent; cleanup never authorizes an old effect to run again.

The target sanitizer masks matching strings, object keys and numbers before storing results. Receipt identity and timing metadata remain unchanged, even when their digits match a result mask. Boolean masks retain their original type in protected transient metadata; a numeric or string secret equal to `1` does not mask unrelated `true` results.

Uncertain infrastructure refusals leave the unfinished intent. If a typed pre-start or preflight refusal proves that nothing ran, the store removes that intent and syncs its directory while still holding the key lock. The same key can then execute after the precondition clears. Failure to remove the intent remains uncertain. Fixed remote-program receipts retain their failed state and exit code on both live replies and later recovery.


### Future Mac session execution

Command mode currently runs as the enrolled macOS account. A future LaunchAgent can receive the same envelope in that user's login session and show Touch ID or password confirmation for root work. That path is not built. It must authenticate the SSH-started process beyond its user ID, prevent project code from replacing the executable, and name the command in each prompt. The executing process must still check its own binary digest and declared privilege.

### Install on mismatch

A Gateway release is the only step that updates agents. When a command answers `agent.version_mismatch`, the runner:

1. copies the pinned binary from the [delivery](#delivery) to a unique private candidate file with mode `0700`;
2. runs that candidate's own `agent.install`, which waits up to 120 seconds for the update lock;
3. sends the command once more, with the deadline that is left.

The download, the copy, and the installer share one deadline: the call's remaining deadline, or 5 minutes for an explicit installation. When that deadline passes before the installer starts, the call fails as a timeout and nothing is recorded.

Candidate paths use `/var/tmp/orbit-agent-candidate-<random>` on Linux and `/tmp/orbit-agent-candidate-<random>` on macOS. Linux streams through `install`. macOS prepares the file with BSD `install` and streams bytes through fixed `dd` arguments.

The lock wait always leaves 30 seconds of the call's deadline for the swap and the health check. When less time is left than the copies, the health check, and a rollback need, the installer refuses with `agent.install_time_short` before it changes anything. A second mismatch after a passing installation is a runner failure. A busy update lock refuses with `agent.update_busy` and records nothing. A lost connection, a timeout, a failed copy, or a common error such as `agent.deadline_exceeded` records nothing either, so the next command tries again.

Only a refusal that `agent.install` declares, such as `agent.replace_failed` or `agent.health_check_failed`, records the pin in the Node's `agent_failed_pin`. `agent.update_busy` and `agent.install_time_short` are the exceptions. Until `orbit node:converge` installs a pin, every command for that Node refuses at once with `agent.install_failed`, without contacting the Node. A recorded failed pin makes that converge re-apply the agent artifact even when its footprint digest already matches; the other artifacts keep their normal digest checks.

`agent.install` replaces only the binary. It checks the candidate's digest, keeps the previous binary as `orbit-agent.previous`, renames the new one into place, and restarts the observer with its existing configuration and secret. It also restarts running compiled services in their Orbit systemd units or managed Process containers. A container must declare a registered service in its command and bind the installed binary read-only at the same path. Docker's saved definition identifies it even after the service replaces itself with its application.

The installer leaves stopped Processes and in-flight commands alone. When the observer does not stay active for 5 seconds or a compiled service restart fails, it restores the kept binary, restarts those services on it, and fails with `agent.health_check_failed`.

When the installed binary already matches, it changes nothing, so concurrent mismatches install once. Once it runs, it removes the staged candidate whatever the outcome. A call whose deadline passes after staging and before the installer starts leaves its `0700` candidate in `/var/tmp`, where systemd-tmpfiles ages it out; the transport has no file-removal API. It records no recipe identity.

## How it connects

The Gateway's WebSocket client tries a handshake refused with `429` again after the server's `Retry-After`, within the caller's connect timeout ([Swamp command steps](/reference/swamp-command-steps)).


The agent connects to the Gateway at `https://gateway.orbit` and to Reverb at `wss://reverb.orbit`. It never uses system DNS. It connects to the Gateway's WireGuard address from its configuration, `gateway_address`, and to the Reverb address from the realtime response. It verifies each certificate for the hostname against the Orbit root certificate in `ca.pem`.

1. The agent calls `GET /api/v1/agent/realtime` and reads the Reverb connection from the response.

   ```json
   {
     "data": {
       "url": "wss://reverb.orbit",
       "address": "10.44.0.3",
       "key": "<reverb-app-key>",
       "channel": "presence-node.12",
       "log_channel": "presence-node-logs.12",
       "member": "agent.12"
     },
     "meta": { "request_id": "..." }
   }
   ```

   Without an active `websocket` role, `url`, `address`, and `key` are null, and the agent asks again every 60 seconds.

2. It opens a connection to the Reverb address on port 443 and reads its `socket_id`.
3. It asks `POST /api/v1/agent/broadcasting/auth` to sign its membership, with `socket_id`, `channel_name`, and `version`. The Gateway signs `agent.{id}` only on the caller's own two channels.
4. It joins `presence-node.{id}`, sends its snapshot, and then publishes its events.
5. It joins `presence-node-logs.{id}` the same way and fetches its [stream list](#stream-list).

When the `gateway` role moves, the Gateway's WireGuard address changes. Every agent then loses the Gateway until its configuration is rewritten. Run `orbit node:add <node>` or a role converge on each Node. The Reverb address needs no converge, because each realtime response carries it.

The Gateway signs no operation channel for an agent, because no agent publishes operation output.

Every agent endpoint call requires an active WireGuard peer and a valid [agent secret](#agent-secret); there is no exemption for older agents or Nodes without a stored secret. Agent endpoints need no access grant and record no Activity.

| Code | HTTP | Meaning |
| --- | --- | --- |
| `peer.identity_unknown` | 403 | The caller's address does not belong to an active Node. |
| `agent.secret_required` | 401 | The request carries no bearer secret. |
| `agent.secret_invalid` | 403 | The secret's hash differs from the one stored for the Node, or none is stored. |
| `agent.node_ineligible` | 403 | The Node is outside the managed-Node boundary. |
| `agent.channel_forbidden` | 403 | `channel_name` is not one of the caller's own channels. |
| `validation.failed` | 422 | `socket_id`, `channel_name`, or `version` is missing or malformed. |
| `realtime.not_configured` | 404 | No `websocket` role is active. |

The agent bounds each step, so a peer that stops answering never holds it.

| Step | Limit |
| --- | --- |
| A Gateway request | 10 seconds to connect, 30 seconds in total |
| One join, from the connection to `pusher_internal:subscription_succeeded` | 30 seconds |
| A WebSocket write or close | 10 seconds; a blocked write ends the session and starts a new connection |
| A quiet connection | After 15 seconds without a message, the agent sends `pusher:ping`. Without an answer in 10 more seconds, it reconnects. |

After a reset, server close, or ping timeout, the agent reconnects with exponential backoff capped at 30 seconds. Jitter spreads retries across each delay window, including at the cap. A session that stayed joined for 60 seconds starts the backoff again in the initial 1–2 second window. Every connection repeats all steps, because Reverb gives each connection a new `socket_id`.

One Node runs one agent. The agent holds an exclusive lock on `/etc/orbit/agent` while it runs, and a second process exits with `another orbit-agent already runs on this Node`. The agent also refuses to start outside `orbit-agent.service`, so a copy started from a shell never joins as the Node's agent.

## Agent secret

Every Unix user on a Node reaches the Gateway from the Node's WireGuard address, and production Nodes run customer code as unprivileged users. The agent secret keeps those users from acting as the agent.

| Item | Value |
| --- | --- |
| File | `/etc/orbit/agent/secret`, `root:root` mode `0600`, in `/etc/orbit/agent`, `root:root` mode `0700` |
| Contents | 64 lowercase hexadecimal characters: 32 random bytes generated on the Node |
| On the Gateway | Only the SHA-256 hash, in the Node record. The API never returns it. |
| Sent by the agent | `Authorization: Bearer {secret}` on every Gateway request |

The agent reads the file when it starts. It exits with an error when the file is missing or malformed, and systemd starts it again after 2 seconds. The Gateway compares the hashes in constant time.

Retained enrollment and role configuration sends the stored hash to `agent.configure`. Its existing rotation policy keeps a matching secret and can replace a missing or mismatched secret. Standalone `node:converge` only compares the secret with the recorded verifier: a missing or mismatched secret refuses before writes. Deleting the secret and running `node:converge` does not rotate it. Run [`orbit:node-agent-rekey`](#make-a-new-agent-key) for a new key; there is no scheduled rotation.

Retained configuration that permits rotation writes the secret file first, then installs the other files, restarts the agent, and stores the new hash last. The running agent reads its secret only when it starts. So a converge that fails at any step leaves the running agent with a secret that the Gateway still accepts. The next converge verifies and repairs any mismatch.

Retained Gateway enrollment and role callers serialize agent configuration under a lock in a file cache store under `ORBIT_HOME`. Native direct convergence uses Swamp concurrency and the agent update lock instead. A second converge waits up to 2 minutes and then fails with `agent.converge_busy`. The lock expires after 7 minutes, which covers the wait for the Node's update lock below. A running converge renews it before each step and stops with `agent.converge_lock_lost` when the lock has expired. The binary download is limited to 20 seconds to connect and 120 seconds in total, so one step fits in the lock's term.

### Make a new agent key

Run this on the Gateway when the Gateway refuses the agent's reports because its key is missing or does not match, when `node:converge` fails with `agent.secret_mismatch`, or for a planned rotation:

```bash
php artisan orbit:node-agent-rekey <node-id> [--attempt=N]
```

The command admits the task under the key `node-agent-rekey-<node>-<attempt>` through `OperationIdempotency::under`, as any keyed admission. The same attempt number returns the same task; a higher number makes a new key again. The Node must be an active, managed Linux Node with stored SSH host keys. Rekey shares the [Node admission lock](/reference/node-provisioning#converge-the-orbit-footprint) with Node convergence, role addition or convergence and enrollment. The [flow chart](https://github.com/hardimpactdev/orbit/blob/main/docs/cli/node/agent-rekey.mmd) shows the steps.

At admission the Gateway makes 32 random bytes as 64 hex characters. It writes the key to one vault copy, `node-agent-rekey-<node>-<attempt>`, and records only its SHA-256 verifier in the task.

The run reaches the Node over official SSH from 209 and calls no agent command. `Write the new agent key` writes the vault copy to `/etc/orbit/agent/secret` with `@swamp/cfgmgmt/file`, `root:root`, mode `0600`. `Restart the Node agent` restarts `orbit-agent.service` with `@swamp/cfgmgmt/systemd`. `Check the Node agent` fails unless the agent is active again. `Check the new key` reads the file's SHA-256. `Prove the new key` asserts that it equals the recorded verifier, and is the terminal result.

"Node 209" here is the Swamp Node of the production fleet: the Node that holds the `swamp` role. The Gateway finds it by that role and never by an id, so a Gateway that bootstraps its own machine has the same Swamp Node ([Gateway bootstrap prerequisites](/reference/node-provisioning#gateway-bootstrap-prerequisites)).

The Gateway stores the verifier only after that assert passes; until then the old verifier stays valid. When the task ends, the Gateway deletes the vault copy. A failed step has no rollback. Run the command again with a higher attempt number after you fix the cause.

## Gateway view

The Gateway keeps the latest state that each agent reports, so it can skip repeated SSH reads. A long-running Gateway process, the agent view subscriber, receives the reports. The view is only an input to reads.

### Subscriber

The subscriber runs next to PHP-FPM on the Gateway machine. The [gateway role convergence](/cli/node/role-add) installs and enables its unit, and restarts it when the unit changes; releases leave it unchanged. The service runs Gateway PHP as the Gateway account from the stable application path.

| Item | Value |
| --- | --- |
| Command | `/usr/bin/php8.5 artisan orbit:agent-view`, as the `orbit` user in the Gateway checkout |
| Unit | `/etc/systemd/system/orbit-agent-view.service`, with `Restart=always` and `RestartSec=2` |
| Installed by | The [gateway role convergence](/cli/node/role-add) |
| Connection | One WebSocket to Reverb for all Nodes, verifying `reverb.orbit` against the Orbit root CA |
| Channels | `presence-node.{id}` and `presence-node-logs.{id}` for every managed Linux Node |
| Member | `gateway.{socket id}`, with `user_info` `{ "kind": "gateway" }`, signed with the Reverb app secret |

A new member makes every agent on the channel send a complete snapshot. So when a channel owes a snapshot for 5 seconds, the subscriber leaves and joins it again. A channel owes one while agent events arrive without a complete snapshot, or after the agent's `sequence` goes back without a membership change. The subscriber asks each channel at most once every 5 seconds and keeps what it knows until the snapshot arrives.

Every 5 seconds, the subscriber checks the Reverb connection, and every 30 seconds the Node list. It joins new Nodes, leaves removed ones, and reconnects when the Reverb key changes. During a `websocket` move, it keeps one link to each Reverb server that holds clients and uses the link with the newest agent event. Without an active `websocket` role, it checks again every 60 seconds.

When the connection drops, the subscriber clears the view and reconnects with a backoff from 1 to 30 seconds, with jitter. It sends its own ping after 30 quiet seconds and reconnects when no answer comes in 30 more seconds. It ignores a message larger than 64 KB and keeps at most 4,096 units per Node.

Every 60 seconds, the subscriber compares the Gateway checkout's commit with the one it started from. When they differ, it exits, and systemd starts it with the new code. The unit and that check use the stable `ORBIT_GATEWAY_CHECKOUT` path, so in the [release layout](/reference/gateway-recovery#release-layout) they follow a release switch. The release switch also restarts the running subscriber.

### Stored state

The view lives in its own file cache store in `ORBIT_HOME/cache/agent-view`, whatever `CACHE_STORE` says, so heartbeats never write the SQLite database. The subscriber and every PHP-FPM worker run as `orbit` and share those files.

| Entry | Contents | Kept for |
| --- | --- | --- |
| One per Node | Units, `docker` state, task workspaces, agent version, log channel membership, last `sequence`, and the Gateway time of the last event | 60 seconds after its last write |
| One per Node | The Gateway time of the agent's last event | No expiry |
| One per Node with open log streams | Each stream's ID, source, `lines`, opening Node, and lease end | Until its last lease ends |
| One for the subscriber | Whether realtime is configured, whether the socket is connected, the number of joined channels, and the time of the last write | 30 seconds after its last write |

The subscriber accepts an event only when Reverb's `user_id` is `agent.{id}`. It applies a snapshot once every part has arrived, and starts over when the agent's `sequence` restarts. It removes a Node's entry when `agent.{id}` leaves the channel. It keeps a unit only when the name has the form `orbit-process-{id}-{name}`, the runtime is `systemd` or `docker`, and the state is a short lowercase word.

### Liveness

[`node:list`](/cli/node/list), [`node:show`](/cli/node/show) and the Node API show each Node's agent as `online` while an agent event arrived in the last 15 seconds. After that, it shows `offline` with `since`, the time of the last event, which the view keeps without expiry. A Node whose agent never sent an event is `not_seen`. While the subscriber is down, every Node is `unknown`.

### Freshness

A reader asks for one Node's state and gets one of three answers. Freshness uses only the Gateway's clock.

| Answer | Meaning |
| --- | --- |
| Fresh | A complete snapshot exists, and an agent event arrived in the last 15 seconds. |
| Stale | An entry exists, but no agent event arrived in the last 15 seconds. |
| Missing | No entry: the subscriber is down, Reverb is unreachable, the agent is not a member, or no complete snapshot arrived. |

In a fresh view, a systemd Process that the agent does not list is `inactive`, and a Docker Process it does not list is `exited`. When the agent reports Docker as `absent`, the view does not answer for Docker Processes.

### Reads that use the view

These reads ask the view first and fall back when it is not fresh.

| Read | With a fresh view | Otherwise |
| --- | --- | --- |
| Process list `runtime_state` | A state the Gateway observed after its own start, stop, or restart wins for 30 seconds. Otherwise the view answers. | [Prometheus](/reference/metrics#process-runtime-status), then SSH |
| Readiness while a hibernated Instance [wakes](/reference/app-dev-runtime-hibernation#wake) | The view answers every 0.5 seconds. A `failed` answer, or the timeout, is checked once over SSH. | SSH every 0.5 seconds |
| The hibernator's [idle halt](/reference/app-dev-runtime-hibernation#idle-window-and-sweep) | When the view shows the Process stopped, the hibernator skips the stop. | `process.control`, which checks ownership and stops |
| The [scheduler tick](/reference/tasks#session-routing)'s check for new commits | `commits` is greater than 0 | `git` over SSH |
| The line diff of an active [task](/reference/tasks#tokens-and-line-diff) | `diff.added` and `diff.removed`, unless truncated | `git diff --shortstat` over SSH |

A task read uses a workspace only when its `base` and `start` equal the values the reader would use. Reads that decide what the Gateway commits or tells an agent stay on SSH: the branch check before an approval commit, a subtask's start commit, turn receipts, and the Project check. The state that a start, stop, or restart returns also stays on SSH, because it must show the change the Gateway just made.

### Publish runs

For agent observations, the subscriber never reads Prometheus, writes the database, or broadcasts in its socket loop. Native terminal results use the [shared synchronous projection path](/reference/swamp-command-steps#event-connection), which runs independently of Horizon. It starts `php artisan orbit:agent-view-publish` as a child process with the queued work and does not wait for it. Task workspaces, Process usage, and log lines each have their own lane, with one run at a time. A run that takes longer than 12 seconds is stopped.

When a workspace of an unfinished task reports a new `head` or new diff counts, a run stores the task's line counts when the diff is complete and broadcasts [`task_group.updated`](/reference/events#tasks). A failed run retries after 15 seconds. After five failures in a row, the workspace is dropped until the agent reports a new change.

### Process usage

The subscriber pushes the CPU and memory of every Process to browsers, so no browser polls the Process list for them. While at least one `viewer.*` member is present on the channels it joins, it queues a sample every 15 seconds. A publish run reads CPU and memory for every Process from Prometheus and broadcasts one [`process.usage`](/reference/events#process-usage) event on `orbit`. With no viewer, nothing is queried. A failed sample is dropped, because the next one replaces it.

### Log relay

The subscriber accepts `client-log` and `client-log-end` only from `agent.{id}` on that Node's log channel, and queues them in order. A publish run drops lines unless the stream is open for that Node. It redacts each line again, also with the stored environment values of the Instance or Process, or the Instance that owns a Schedule, and publishes `log.lines` in parts under 10,000 bytes. A failed run puts its events back in front of the queue and retries after 2 seconds. [Live logs](/reference/live-logs#limits) lists the Gateway's queue limits and the end reasons.

## Delivery

Every Gateway release uses the pinned `cli-v0.9923.0` agent delivery. CI builds no agent; an agent release carries it. The pinned manifest records the published source commit, platform digests and URLs; Gateway commits need no agent publication. Its shape is:

```json
{
  "commit": "<full commit SHA>",
  "version": "0.5.0",
  "artifacts": {
    "linux-x86_64": {"sha256": "<sha256>", "path": "orbit-agent-linux-x86_64", "url": "https://github.com/hardimpactdev/orbit/releases/download/cli-v0.N.0/orbit-agent-linux-x86_64"},
    "macos-aarch64": {"sha256": "<sha256>", "path": "orbit-agent-macos-aarch64", "url": "..."},
    "macos-x86_64": {"sha256": "<sha256>", "path": "orbit-agent-macos-x86_64", "url": "..."}
  }
}
```

The Gateway reads it from `apps/agent/delivery/manifest.json` in its checkout, or from the path in `ORBIT_AGENT_DELIVERY`. Git does not track the file. The Gateway picks a Node's artifact by its platform and recorded architecture. It uses the file beside the manifest when it exists, and otherwise downloads the URL once into `ORBIT_HOME/agent-artifacts/<sha256>`. It checks the SHA-256 before every use. An existing local artifact with the wrong digest refuses; only an absent local file uses the published URL. `apps/agent/scripts/build-delivery` writes the same layout for CI and for a topology.

[Gateway release admission](/reference/gateway-recovery#build-and-seal-a-release) reads the target commit's pin and fetches its published manifest in PHP. It verifies the [provenance](/reference/gateway-recovery#release-provenance), checksums and manifest before starting the Swamp run.

The Swamp build step writes the admitted manifest into the release. Its private configuration cache selects that manifest, so a shared env override cannot keep the previous release's pin. The release does not install the agent on the Gateway Node.

The manifest also supplies the Linux pins in the [recipe identity](/reference/compute-drivers#recipe-identity). The recipe source carries no separate agent version or hashes.

Convergence, the fleet verifier, and the [desired fleet state](/reference/self-update#desired-fleet-state) all read this one pin.

## Install and upgrade

The agent converge writes these files on a Linux Node:

| Item | Path or value |
| --- | --- |
| Binary | `/usr/local/bin/orbit-agent`, owned by `root`, mode `0755` |
| Directory | `/etc/orbit/agent`, `root:root` mode `0700` |
| Configuration | `/etc/orbit/agent/config.toml`, with `gateway_url = "https://gateway.orbit"` and `gateway_address` |
| Orbit root certificate | `/etc/orbit/agent/ca.pem` |
| Agent secret | `/etc/orbit/agent/secret` |
| Unit | `/etc/systemd/system/orbit-agent.service`, marked `# Managed by Orbit: agent` |
| Kept binary | `/usr/local/bin/orbit-agent.previous`, the binary before the last replacement |

Standalone `node:converge` uses official `@swamp/cfgmgmt` behaviors over SSH from 209; it calls no agent command. `Install the Node agent` downloads the pinned release with `fetch` and checks its SHA-256; a binary that matches the pin is left alone. `Configure the Node agent` writes the configuration and certificate with `file`. `Start the Node agent` writes the unit the Gateway rendered with `systemd`, then enables and starts it, and `Restart the Node agent` restarts it. That part only runs when its digest changed, or with `--force`.

The converge intent carries the Node's roles, the agent key verifier and the footprint check list. For a peer DNS Node it also carries the policy, the wg-quick `dns_hook` line that the shared peer-DNS workflow writes, and the private name its end check resolves.

The `ai-router` part on the collector Node has no step: it only versions that Node by its agent pin, the ai-router extension runs the collector itself, and the part counts as applied with the agent. Its Route residue part re-applies the Router LAN rules the Node's sites need. It migrates no rules: every Node finished the one-time LAN migration. The `caddy-package` footprint part carries the same SHA-256 package pin for `node:converge`, which installs it through the shared `node-caddy-package` workflow.

The converge never writes the secret. Its end check is one `sha256sum --check` against the Gateway's hashes: the pinned binary, the recorded verifier and the agent's files. A missing or mismatched secret fails the converge with the agent part unapplied; [`orbit:node-agent-rekey`](#make-a-new-agent-key) makes a new one. A passing converge clears the Node's recorded failed pin. The role parts run beside the agent part in their own steps, and the Gateway reads each one's outcome from Swamp's run data; [Converge the Orbit footprint](/reference/node-provisioning#converge-the-orbit-footprint) lists them. `agent.remove` stops the agent and removes its unit, binary, and directory.

The unit starts after `network-online.target` and `wg-quick@orbit.service`, so the first request to the Gateway finds the tunnel. Without that order, a Node that boots first waits for the 10 second connect timeout before its first report.

The unit runs the agent as `root` with `Restart=always` and `RestartSec=2`. It grants no capabilities and sets `NoNewPrivileges=yes`, `ProtectSystem=strict`, `ProtectHome=tmpfs`, `BindReadOnlyPaths=-{Instance root}`, one read-only bind for each Tool Manager state path under `/home` (the Vite+ home and `/home/linuxbrew/.linuxbrew`) and one for each production home, `SupplementaryGroups=orbit-env`, `PrivateTmp=yes`, `MemoryMax=128M`, and `RestrictAddressFamilies=AF_UNIX AF_INET AF_INET6`. The memory limit also covers the `journalctl` processes of live journal streams.

The Instance root is the Node's [apps root](/reference/node-settings#derive-the-effective-root). The bind line appears only when the root lies under `/home` or `/root`. When the Gateway cannot resolve the managed user, or the root has characters other than letters, digits, `.`, `_`, `-`, and `/`, the unit sets `ProtectHome=yes`, and the Gateway reads that Node's checkouts over SSH.

| Path | What the agent can read |
| --- | --- |
| `/home` and `/root` | Nothing, except the Instance root |
| The Instance root | Read-only. Without capabilities, root reads only files that other users may read: the tracked files and `.git`, not an Instance `.env`. |
| Everything else | Read-only |

The Instance root and each checkout must stay world-traversable, mode `0755` as Orbit creates them. Otherwise the agent leaves the checkout out. Orbit removes the world bits from an Instance `.env` when it configures the Laravel URL, after registration or transfer, and on each agent converge. Agent converge independently closes environment permissions for the Instances under the Node's apps root; it does not rely on registration or transfer having run.

For Laravel, it uses the shared [application directory](/reference/projects#application-directory): root `apps/site/public` means `<checkout>/apps/site/.env`, not only `<checkout>/.env`. Root `public` and non-Laravel Instances keep the checkout-root path. It closes only regular environment files, without following symlinks or discovering apps by scanning the repository. The converge's `Close Instance .env files` step closes them in one `find`, best effort.

The Gateway converges the agent at these points.

| When | Result of a failure |
| --- | --- |
| `node:add`, after the Metrics exporters | Provisioning fails at step `agent` with `node.agent_install_failed`. A new Node becomes `failed`, and an active Node stays `active`. |
| A role converge on the Node | The role converge continues. The Gateway logs a warning, and The native convergence result records the drift. |

Commands that no Gateway or Swamp code calls leave the agent in small pull requests. The Gateway release, Doctor, image, Pi, sqlite, source transfer and source removal commands have left, and so have the firewall inspection, Metrics runtime and exporter, recipe apply and image owner commands. The last action commands leave with their Swamp ports; then one release ships the observer. Every Node then gets the new pin from the Gateway on its next command, through [install on mismatch](#install-on-mismatch). `orbit self-update` itself replaces only the CLI.

The rollout leaves out the Gateway's own Node. Gateway release runs leave its agent unchanged. Its CLI comes from the current release's `apps/cli`.

`agent.install` holds `/run/lock/orbit-self-update.lock` while it replaces and restarts the agent. `agent.configure` and `agent.remove` hold the same lock, after the installation. So no two of them swap or restart the agent at the same time. A CLI self-update that keeps the lock longer than 5 minutes fails `agent.configure` with `agent.update_busy`.

The Ansible `node:converge` agent phase takes the same lock for each atomic replacement of the agent binary, the versioned CLI binary and the CLI selection link. It stages checksum-verified binaries beside their destinations before waiting up to 120 seconds for the lock. If the wait fails, the selected artifact stays unchanged and the staged candidate is removed. Matching artifacts are not replaced.

An installation changes no configuration, certificate, secret, or unit; the rollout's footprint step re-applies those when their digest changed. The fleet verifier reports each Node that runs another binary.

The observer sends its version with each channel authorization: the Cargo version with the first 16 hexadecimal digits of its binary's SHA-256 as build metadata, such as `0.5.0+ad999ffe1ab24dca`. The Gateway derives the same string from the delivery for each Node, so presence proves the exact pinned binary. The Gateway keeps the version of `presence-node.{id}` in the agent view's file store without an expiry, because an agent that stays connected never joins again. The [fleet rollout](/reference/gateway-recovery#which-nodes-a-pass-visits) visits a Node again when its agent reports another version.

## Failures

The agent recovers from each failure below without an operator.

| Situation | What happens |
| --- | --- |
| The agent crashes | systemd restarts it after 2 seconds. The web app shows the Node offline until the agent joins again, and the Gateway reads over SSH. |
| The agent stops cleanly | It closes its connection. The web app shows the Node offline at once, and the Gateway drops the Node's view. |
| The Node loses power or network | Heartbeats stop. After 15 seconds, the web app shows the Node offline and the view turns stale. |
| The watch list request fails | The agent keeps its last list. |
| A log source cannot be read, or `journalctl` is missing or stops | The agent ends that stream with `source_unavailable`. |
| The agent leaves while it streams | The subscriber ends each stream of that Node with `agent_left`. |
| Reverb is down, or no `websocket` role is active | The agent and the subscriber retry. The web app polls Prometheus, and Gateway reads use Prometheus and SSH. |
| The subscriber stops | systemd restarts it after 2 seconds. Meanwhile the view turns stale, and Gateway reads use Prometheus and SSH. |
| A snapshot is lost | The subscriber asks for a new one within about 10 seconds. |
| The Gateway does not accept a fact report or does not answer the watch list request | The agent tries again after 1, 2 and 4 seconds, and then every 4 seconds. |
| Reverb stops answering without closing the connection | The agent reconnects within about 30 seconds. |
| systemd D-Bus is unavailable | The agent exits, and systemd restarts it. |
| The secret differs from the Gateway's hash, such as after a Gateway database restore | The Gateway refuses the agent with `agent.secret_invalid`. A converge verifies the stored secret and writes a new one when needed. |

The agent logs to the systemd journal. Its logs contain no Reverb key, signature, secret, or streamed log line.

## Removal

Online [`node:remove`](/reference/node-provisioning#remove-a-node) stops and disables `orbit-agent.service` and deletes the unit, the binary, and `/etc/orbit/agent`, secret included. A failure only logs a warning. `--offline --force` for an unreachable Node leaves the agent installed and lists it under `retained_on_node`. The Gateway then refuses its requests with `peer.identity_unknown`, and the agent keeps retrying.

## Doctor

[Doctor](/cli/doctor/doctor) answers from the `host`, `units` and `ufw` facts and the agent's liveness. It starts no run and reaches no Node. It reports `node.agent_missing` for an agent the Gateway never heard from, and `node.agent_view_stale` for a silent one; while the agent is silent, the Node's other findings may be outdated. Provisioning owns agent binary, secret and configuration verification; Doctor does not duplicate it. The agent remains an observer.

See [Doctor](/cli/doctor/doctor#what-each-family-checks) for the current scope, skipped families and recovery rules. The agent has no Doctor command: every Doctor check reads a stored fact.

## Checks

Receipt replay fixtures keep one Operation creation timestamp across repeated calls, so crossing a wall-clock second does not change the identity under test.

Run the [agent checks](/reference/bun-and-rust-projects#node-agent) as a normal user. The dev profile optimizes `regex` and `regex-automata` so the shared redaction fixtures run without the cost of unoptimized matching. Cargo's test profile inherits these package overrides. The inputs, redaction rules, and assertions stay the same; these overrides do not change the release profile. [Live logs](/reference/live-logs#stream-tests) describes which stream tests use a paused clock and which retain real processes and observation intervals.

Tests that create executable tools use the shared `tests/support/executable.rs` helper. It writes a source file, then waits for a separate `install` process to copy it to the executable path. Only that child opens the executable inode for writing. Parallel test forks can inherit a writable descriptor for the source, but the source is never executed. Closing, syncing, or renaming a file written by the test process would leave an inherited descriptor open until the other child executes or exits, which can cause Linux `ETXTBSY` errors.

### Recipe interruption

The native recipe runner waits directly for its command and treats SIGHUP, SIGINT, and SIGTERM in the child wait state as interruption, even when the agent handler has not run yet on another thread. It owns the command process group and enforces its deadline with TERM, then KILL after one second. Deadline expiry remains exit code 124. An external timeout program cannot preserve this evidence reliably: it can turn a child signal into a numeric exit code.

Required and optional commands both stop the recipe on interruption. The handler flag still detects signals before execution and after a command exits normally. A numeric exit code such as 143 is a command failure, not proof of a signal.

Recipe interruption tests run on a non-main thread in an isolated process. A socket barrier delays the agent handler on the main thread while the recipe runs on another thread, so child exit precedes the handler flag update. The delayed-handler cases target the main thread explicitly: a process-wide signal can select the recipe thread and deadlock a blocking test handler. A separate service-termination case signals both process groups with the real handler. A nested signal checks that interrupted socket I/O keeps the barrier closed.

The supervised fixture sends a Unix datagram after execution, including its process group. The test receives that event before it signals the worker and supervisor groups. Fixture readiness, handler entry and release, command lifetime, and helper exit have deadlines. A deadline failure fails the test and kills its owned process groups. Separate cases check each supported signal before execution, after normal execution, and during required and optional commands.

## Releases

The Gateway selects the agent that its pin names: `apps/gateway/resources/agent/frozen-manifest.json`, a byte copy of one release's `manifest.json`. [Release admission](/reference/gateway-recovery#build-and-seal-a-release) verifies that delivery independently of the Gateway commit's publication.

A [CLI release](/reference/cli-binaries#published-releases) also builds and carries the agent when the agent source changed since the previous release. Its manifest names the agent source commit. Other releases contain only CLI assets. A new agent reaches Nodes only when a reviewed commit moves the pin. That commit copies the release's `manifest.json` into the pin and records the binary's command contract in `frozen-commands.json` and `frozen-commands-source.json`, which `bin/check-frozen-agent-commands` verifies. The next Gateway release records the new pin, and the fleet rollout installs it Node by Node.

Schedule follow requires Cargo version 0.14.0 or newer for UTC journal snapshots and ordered multiline entries. This requirement is declared by `ScheduleLogStreamTarget::MinimumAgentVersion` in the PHP SDK and shared with the Gateway. A CLI release tag supplies the download URLs; it does not replace the Cargo version in the Agent manifest.

An agent release carries these assets:

| Asset | Contents |
| --- | --- |
| `orbit-agent-linux-x86_64` | Static binary for `x86_64` Nodes |
| `orbit-agent-macos-aarch64` | Command binary for Apple silicon Macs |
| `orbit-agent-macos-x86_64` | Command binary for Intel Macs |
| `manifest.json` | The delivery manifest, with each binary's URL in this release |

The release's `SHA256SUMS` and `provenance.sigstore.json` authenticate those bytes. A Gateway commit needs no new agent release.

## Limits

The agent has these limits.

- The agent reports presence, Process state, and task checkout Git state, and tails logs only for live log streams.
- The observer reports state, and commands change it.
- Task workspace reads that gate an action still run as SSH programs.
- The agent does not report CPU or memory. The Gateway pushes them from Prometheus as [Process usage](#process-usage).
- The delivery holds Linux builds for `x86_64` and `aarch64`. The observer runs only on Linux. Command mode builds and tests on macOS arm64, and the delivery includes command binaries for Apple silicon and Intel Macs.
- The agent cannot trigger `orbit self-update`. The [fleet rollout](/reference/gateway-recovery#fleet-rollout) runs it over SSH.

## Why it works this way

These reasons explain the design. Check them before you propose a change.

### Typed commands over SSH

Scripts that the Gateway assembles and sends on every call have hidden contracts: positional arguments, exit codes, and text that PHP parses. CI cannot test them alone, and they need an interpreter on every Node. A named command with typed input and output has one implementation and one contract, tested in Rust where the logic runs. So the Gateway sends a name and data, never source.

SSH stays the transport. It authenticates the Gateway with a pinned host key and the enrolled account. It also reaches a Node whose agent is broken, so the Gateway can always repair it. An agent that listened on the network would add a root-capable endpoint that every user on every Node could reach. So the observer still listens on no port, and command mode starts only when SSH or the Gateway starts it. The binary is not setuid: a Node user who runs it gets only that user's permissions.

A Gateway release delivers new agent code, as it delivered scripts before. Install on mismatch reaches an offline Node, a Node that the rollout missed, and a VM from an older image on their first command. No separate agent update step exists.

Moving source into named Python programs improved linting but kept source assembly, interpreter startup, and a second execution contract. Delivering scripts through the agent would keep those costs. PHP SSH command sequences would also split a Node action across round trips without one lock around its checks and changes. Compiled commands keep the rule and its tests together.

The exact binary digest identifies the contract. Version ranges and compatibility commands would keep multiple implementations active. The frozen mismatch response lets a released agent refuse a newer command before understanding its name or input. The Gateway can then install the pinned implementation once.

Ordinary commands run from their open executable and do not hold the update lock. Atomic replacement leaves them valid while an update proceeds. Holding the lock throughout a long deploy would block updates unnecessarily. Installers and `agent.configure` take it exclusively.

### Rust

The agent runs on every Node, so memory matters. A Rust agent uses about 5 MB. PHP or Bun would use about 20 times more. Rust also has mature libraries for systemd D-Bus and the Docker socket.

### One pinned delivery

A workflow artifact needs a GitHub login and expires, so the commit's release publishes the tested artifact as release assets with permanent URLs, beside the CLI. One release per commit needs no separate tag, token, or App. Building on each Node would put a Rust toolchain on production hosts. Resolving the latest release at install time would let an unreviewed build reach Nodes. So each Gateway release pins the exact digests of its own commit's delivery, and every reader takes the pin from it.

The Gateway fetches and installs that delivery itself in PHP, so installing the agent never needs the agent. It verifies the release's provenance first, so only files that the release workflow on `main` signed reach a Node.

### The view in a file cache

HTTP reports would cost a PHP-FPM worker per heartbeat on a small Gateway. A PHP-FPM request cannot wait for a snapshot, which comes only when a member joins. SQLite would take a write every few seconds that competes with API writes. So one long-running subscriber keeps a disposable view in files, and every reader falls back to Prometheus and SSH when the view is missing.

### Rejoin to ask for a snapshot

Reverb gives neither client events nor presence events a socket ID, so the subscriber cannot tell two connections of one member apart. A client event that asks for a snapshot is rejected, because the subscriber never sends client events. A new membership already makes every agent send a snapshot, so the subscriber leaves and joins again.

### A per-Node secret

The WireGuard address alone does not tell the agent apart from other users on the Node. A firewall owner match depends on per-Node rules that fail silently when missing. Mutual TLS needs a certificate issuer and renewal. A bearer secret in a file that only root can read fails closed, at a fraction of that cost. The Gateway stores only the hash, because a leaked database then reveals no secret, and a slow password hash adds nothing for 256 random bits.

### The hash is stored last

Storing a new hash before the agent restarts would refuse the running agent if the restart fails, while the file would match the record. A file-only verification could then miss the mismatch. Storing it last keeps the running agent accepted and leaves a mismatch that Doctor reports.

Native convergence captures Caddy recipe candidates through a native-only Gateway producer. This does not change the frozen agent Caddy command or its accepted fields. See the [candidate rollout](/reference/compute-drivers#the-recipe-artifact-mirror).
