---
title: "Node agent"
description: "What orbit-agent observes on a managed Node, how the Gateway runs typed commands through it, how it connects and authenticates, how releases deliver it, and how Doctor checks it."
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

The agent binary also serves signed executor orders in a separate service mode. [Executor actions](/reference/executor#action-primitives), including `instance.destroy`, `route.withdraw_site`, a task copy's `instance.sync_base` and the read-only `instance.work_read` [signed read](/reference/executor#signed-reads), are not observer commands. A `route.publish_site` order reads its installed Caddyfile back before it reports success.

`orbit-agent` is a Rust binary installed on managed Linux Nodes. It operates in four modes:

- **Observer (`orbit-agent`):** A long-running systemd service that reports presence, Process states, facts, task workspaces, and live logs, and forwards `executor-order` events.
- **Executor (`orbit-agent execute serve` / `relay`):** Runs signed orders on the local socket and the [Gateway engine](/reference/gateway-engine) machine bus. It is pinned at `cli-v0.10995.0` / agent `0.38.0`.
- **Command mode (`orbit-agent command <name>`):** Runs one typed Node action over pinned SSH (or locally on the Gateway Node) and exits.
- **Service entry points (`orbit-agent service <name>`):** Fixed systemd helpers such as `schedules-run <uuid>` and `schedules-complete <uuid>` ([Schedules](/reference/schedules#node-timers)).

Recent agent versions changed the executor as follows.

- **Process restart:** Agent `0.33.0` adds `process.read_instance` and `process.restart`, the signed Process restart ([the process family](/reference/executor#the-process-family)).
- **Route update:** Agent `0.34.0` keeps the Gateway's Route update fenced until `tls.ensure_csr` and `route.publish_site` return, and `route.publish_site` reads its installed Caddyfile back ([route.update](/reference/gateway-engine#routeupdate)).
- **Route withdrawal read-back:** Agent `0.35.0` makes `route.withdraw_site` read Caddy's admin API for up to about 5 s after the reload, until the Route is gone ([action primitives](/reference/executor#action-primitives)).
- **Host primitives:** Agent `0.36.0` turns on the [host family](/reference/executor#the-host-family) with `file.read` on app-dev and vpn Nodes.
- **Account read:** Agent `0.37.0` adds `account.read`, which reads one account's uid, primary group and home through the host's NSS ([the host family](/reference/executor#the-host-family)).
- **ACL tree scope:** Agent `0.38.0` adds `scope: tree` to `posix.acl.ensure`, one entry over one folder tree, and reports the `posix.acl.tree` feature ([the host family](/reference/executor#the-host-family)).
- **ACL read-back:** Agent `0.38.0` makes `posix.acl.ensure` read and write each list through the path's own descriptor and take a `user` or a `group`. It answers its changes and skipped paths as output.
Later versions add catalogue primitives.

- **Incus instance:** Agent `0.39.0` turns on the [incus family](/reference/executor#the-incus-family) on app-dev Nodes with `incus.instance.ensure`, one Incus instance through the Incus API over its unix socket.
- **Unit restart and signal:** Agent `0.45.0` adds `systemd.unit.restart` and `systemd.unit.signal` to the host family, one run of one unit restarted or signalled at most once per order ([the host family](/reference/executor#the-host-family)).

The pinned executor has the host catalogue primitives `config.apply`, `file.ensure` (with a host-side `source_path`), `dotenv.ensure`, `posix.acl.ensure`, `ca.trust.ensure`, `systemd.unit.ensure` and `http.probe`.

The observer listens on no network port. To build and test the agent workspace, see [Bun and Rust projects](/reference/bun-and-rust-projects#node-agent).

## Where it runs

The Gateway installs the observer on every active managed Linux Node with a WireGuard address and pinned SSH host key. Only those Nodes hold an agent secret.

macOS Nodes have no observer service, agent secret, or LaunchAgent; macOS releases ship command-mode binaries only (`macos-aarch64`, `macos-x86_64`).

## What it observes

The observer watches systemd over D-Bus (`orbit-process-*.service`) and `/run/docker.sock` (`orbit-process-*` containers), reporting `runtime_state` values matching the Process API (`active`, `inactive`, `failed`, `running`, `exited`, `restarting`). On a container Gateway, the host watch list omits the Horizon and scheduler units inside the live container.

Without Docker, the observer reports `docker: "absent"` and checks for the socket every 10 seconds. Rapid changes to one unit merge over 250 ms. The observer sends a heartbeat every 5 seconds and a full snapshot at least every 60 seconds.

## Facts

The observer posts structured facts to `POST /api/v1/agent/facts` using its [agent secret](#agent-secret), independently of Reverb. Facts are sent on change and repeated every 10 minutes (failed deliveries retry at 1, 2, and 4 seconds, keeping the original `observed_at` timestamp). The Gateway stores one row per `(node, kind, subject)` in `node_facts` (max payload 1 MiB, HTTP 413 `agent.fact_too_large`; invalid data HTTP 422 `validation.failed`).

| Kind | Subject | Data | Cadence |
| --- | --- | --- | --- |
| `host` | none | `os`, `arch`, data filesystems (up to 64, `free` rounded down to 256 MiB), and `instance_bridge.error_code` on container hosts | 60 seconds |
| `metrics` | none | Per-core CPU busy ratios, memory/swap bytes, load averages, uptime, kernel pressure, and disk stats | 60 seconds |
| `units` | none | `docker` state, watched `orbit-process-*` and role units (`load`/`sub`/`state`), and existing `awake` markers | 10 seconds |
| `checkout` | path | Git metadata, `composer.json`, `artisan`, and `.env` stat for one checkout under `apps_root` | on change |
| `checkouts` | none | Index of all checkout paths found under `apps_root` (prunes removed `checkout` rows) | on change |
| `packages` | manager | Installed packages, sources, and keys for one Tool Manager; identical samples renew confirmation for Doctor | 60 seconds |
| `ufw` | none | `installed`, `enabled`, and parsed rules from `/etc/ufw/user*.rules` (no `ufw` subprocess) | 60 seconds |
| `env_keys` | path | `.env` file `kind`, sorted key names (never values), and `testing_tracked` | 60 seconds |
| `accounts` | none | Local `/etc/passwd` and `/etc/group` entries (up to 512) | 60 seconds |

`egress` facts are rejected on write with HTTP 422 (`agent.fact_not_expected`); any retained rows remain readable through [`task:egress`](/cli/task/egress).

Disk sampling shares one background pass with a 100 ms deadline per mount; timed-out mounts retain their last reading marked `stale: true`. If a `host` report omits `/`, the Gateway keeps the stored `/` reading for up to 180 seconds so Doctor checks stay valid across boot. [`node:metrics`](/cli/node/metrics) reads the stored `metrics` snapshot directly without contacting the Node.

### Packages

The observer reads each Tool Manager's local state files directly without invoking the package manager:

| Manager | State files read |
| --- | --- |
| `apt` | `/var/lib/dpkg/status`, `/etc/apt/sources.list*`, `/etc/apt/keyrings`, `/usr/share/keyrings` |
| `composer` | Global `composer.json` and `vendor/composer/installed.json` |
| `vp` | Vite+ home `bins/*.json` |
| `brew` | `Cellar/<formula>/<version>/INSTALL_RECEIPT.json`, the `opt/<formula>` link and `Caskroom/<cask>/<version>` |

The `apt` fact lists each `install ok installed` package and each `hold ok installed` package, a held one marked `"held": true`. The observer has no capability to read past file modes, so a `brew` formula appears only when others can read its Cellar folder; Orbit keeps the Linux prefix [readable](/reference/tools#readable-homebrew-prefix).

Each `brew` formula lists every Cellar folder in `versions`, since Homebrew keeps an old version after an upgrade until `brew cleanup`. When `opt/<formula>` links to `../Cellar/<formula>/<version>` and that version is in the list, the formula also carries it as `linked`:

```json
{"name": "fzf", "versions": ["0.74.3", "0.75.0"], "linked": "0.75.0", "tap": "homebrew/core", "on_request": true, "dependencies": []}
```

The linked version is the installed one. Doctor and adoption read it, and use the only entry of `versions` when there is no link. With several versions and no link, the installed version is unknown: Doctor reports `tool.inspection_failed` and adoption is blocked with `version_unreadable`.

[`tool:scan`](/reference/tools#discover-installed-packages) and [`tool:adopt`](/reference/tools#adopt-a-tool) read these stored facts without contacting the Node. The observer reports what is installed; the Gateway stores [Tool membership and desired versions](/reference/tools#tool-modes) separately.

### Environment key names

For each `.env` path in the watch list's `environments`, the observer reports `kind` (`absent`, `regular`, `symlink`, `other`), sorted `keys` (up to 2,000, `null` if unreadable, symlinked, or over 1 MiB), and `testing_tracked`. It never sends values. Managed `.env` files use mode `0640` with group `orbit-env`, which the observer unit joins via `SupplementaryGroups=orbit-env`.

### Checkouts

On active `app-dev` Nodes, the observer watches `apps_root` (two levels deep, skipping symlinks and hidden names) using inotify and reports one `checkout` fact per Git worktree: `owner`, `top_level`, `common_dir`, `linked`, `worktrees`, credential-masked `origin`, `branch`, `head`, `default_branch`, `application`, `artisan`, `composer`, and `.env` `stat` metadata. It debounces changes for 2 seconds and runs a full sweep every 10 minutes.

## Task workspaces

The observer reads Git metadata for watched task checkouts in-process via `libgit2` (running no hooks, filters, or `fsmonitor`), polling metadata files every 2 seconds and working trees every 30 seconds.

### Watch list

The observer fetches `GET /api/v1/agent/watch` at startup and every 60 seconds:

```json
{
  "data": {
    "services": ["caddy.service", "docker.service", "orbit-process-12-web.service"],
    "awake_markers": ["/dev/shm/orbit/hibernation/instance-31"],
    "apps_root": "/home/orbit/apps",
    "applications": { "operator": "server/web" },
    "tools": [{ "manager": "apt", "paths": ["/var/lib/dpkg/status"] }],
    "environments": ["/home/orbit/apps/operator/default/server/web/.env"],
    "workspaces": [
      { "instance_id": 31, "path": "/home/orbit/apps/orbit/task-58", "base": "main", "start": "9f2c4be0..." }
    ]
  }
}
```

`workspaces` contains up to 64 unfinished task checkouts on the Node. Each `client-workspace` / `client-workspaces` report includes `instance_id`, `base`, `start`, `branch`, `head`, `dirty`, `commits` (up to 1,000), and `diff` (`{ files, added, removed, truncated }`, capped at 5,000 files and 1 MiB per file).

## Log tails

The observer tails Instance, Process, or Schedule logs only while an active [live log stream](/reference/live-logs) requests them, publishing `client-log` and `client-log-end` on `presence-node-logs.{id}`.

### Stream list

The observer fetches `GET /api/v1/agent/log-streams` (up to 16 active streams) on channel join, on `log-streams.changed`, and every 15 seconds while streaming. If the list cannot be fetched for 60 seconds, all active streams end with `list_unavailable`.

Supported sources are `laravel` (`storage/logs/laravel*.log`, rejecting symlinks and root-owned files), `journal` (`journalctl --unit <unit> --output short-iso --utc` for `orbit-process-*` and `orbit-schedule-*` units), and `docker` (containers matching `orbit-process-{id}-{name}` with `orbit.managed=true`). Lines are redacted with the Gateway's secret patterns before transmission and bounded to 8 KiB per line, 256 KiB initial batch, 32 KiB/s per stream, and 256 KiB/s per agent. The same Node redactor also sanitizes signed removal diagnostics before the executor journals them.

## Commands

The Gateway executes typed Node actions through `AgentCommandRunner`:

```text
[sudo -n] /usr/local/bin/orbit-agent command <name>
```

A Gateway architecture test blocks direct SSH or process execution outside `AgentCommandRunner`, Swamp transports, and narrow documented exceptions (container Process journal/state/workspace reads and [host-checkout admission checks](/reference/remote-programs)).

Standard input carries one JSON envelope line (`agent_sha256`, `deadline_ms`, optional `stream_bytes`, and typed `input`), optionally followed by exact raw binary bytes. Standard output returns one JSON result (`{"ok": true, "data": {...}}` or `{"ok": false, "error": {...}}`) with exit code `0`, even on declared refusals.

Before parsing input or reading binary bytes, the agent verifies its own executable's SHA-256 against `agent_sha256` and returns `agent.version_mismatch` if it differs. On Linux, mutating commands run inside isolated transient systemd services (`orbit-command-<name>-<random>`, `KillMode=control-group`) with `flock` locks in `/run/orbit-agent/locks/<uid>` and 30-day idempotent effect receipts (`effect_key`, readable via `agent.effect-receipt`).

### The contract

Command names follow `<namespace>.<action>` and error codes follow `<namespace>.<reason>`. Rust definitions generate `apps/agent/contract/commands.json` and `apps/agent/contract/schemas.json`; at runtime the Gateway reads the pinned binary's contract from `apps/gateway/resources/agent/frozen-commands.json` (verified in CI by `bin/check-frozen-agent-commands`).

Common runner and agent errors include `agent.version_mismatch`, `agent.binary_input_invalid`, `agent.envelope_invalid`, `agent.unknown_command`, `agent.invalid_input`, `agent.identity_mismatch`, `agent.platform_unsupported`, `agent.deadline_exceeded`, `agent.supervisor_failed`, and `agent.runner_failed`.

### Transports

`AgentCommandRunner` selects the transport by Node:

| Node | Transport |
| --- | --- |
| Managed Node | Pinned SSH to its WireGuard address as its enrolled user |
| Serving Gateway Node | Local process execution |
| Node during admission | Pinned bootstrap SSH until WireGuard is ready |

### Install on mismatch

When a command returns `agent.version_mismatch`, the runner stages the pinned binary to a `0700` candidate file (`/var/tmp/orbit-agent-candidate-*` on Linux, `/tmp/orbit-agent-candidate-*` on macOS), runs its `agent.install` (holding `/run/lock/orbit-self-update.lock`, keeping `orbit-agent.previous`, and rolling back on health-check failure), and retries the command once.

Declared installation refusals (except `agent.update_busy` and `agent.install_time_short`) record the pin in `nodes.agent_failed_pin`, failing subsequent commands with `agent.install_failed` until `orbit node:converge` repairs the Node.

## How it connects

The observer connects directly to the Gateway's WireGuard IP (`https://gateway.orbit`) and Reverb's IP (`wss://reverb.orbit`), verifying certificates against `/etc/orbit/agent/ca.pem` without system DNS:

1. Calls `GET /api/v1/agent/realtime` to obtain the Reverb address, public key, and channel names (`presence-node.{id}` and `presence-node-logs.{id}`).
2. Connects to Reverb on port 443 with `TCP_NODELAY`, authenticates both channels via `POST /api/v1/agent/broadcasting/auth`, and publishes its initial snapshot.
3. Forwards server `executor-order` events on `presence-node.{id}` verbatim to `/run/orbit-executor.sock` and returns each reply line as `client-executor-result`.

All agent endpoints require an active WireGuard peer and valid bearer secret (`peer.identity_unknown`, `agent.secret_required`, `agent.secret_invalid`, `agent.node_ineligible`, `agent.channel_forbidden`, `realtime.not_configured`, `realtime.not_serving`). Reconnects use jittered exponential backoff capped at 30 seconds. An exclusive lock on `/etc/orbit/agent` ensures only one observer runs per Node inside `orbit-agent.service`.

## Agent secret

Each managed Linux Node holds a 32-byte (64 lowercase hex character) random secret at `/etc/orbit/agent/secret` (`root:root`, `0600`). The Gateway stores only its SHA-256 hash and compares bearer tokens in constant time.

Standalone `node:converge` verifies the secret against the stored hash and never rotates it. To generate and install a new secret, run on the Gateway:

```bash
php artisan orbit:node-agent-rekey <node-id> [--attempt=N]
```

This writes the new secret via Swamp (`node.agent_rekey`), restarts `orbit-agent.service`, verifies the SHA-256 on disk, and only then updates the stored verifier. `--attempt` defaults to `1` and forms the Operation idempotency key (`node-agent-rekey-<node-id>-<attempt>`): reusing a Node ID and attempt number returns the existing Operation without generating a new secret, so increment `--attempt` for every new rotation or retry after a failed attempt.

## Gateway view

The Gateway maintains an in-memory/file-cache view (`ORBIT_HOME/cache/agent-view`) of live agent reports via `orbit-agent-view.service` (`php artisan orbit:agent-view`), connected to Reverb as `gateway.{socket id}` on every managed Linux Node's channels.

### Liveness

`node:list`, `node:show`, and the Node API report an agent as `online` if an event arrived within the last 15 seconds, `offline` (with `since`) afterward, `not_seen` if never observed, or `unknown` while the subscriber is down.

### Freshness

A Node's view is **fresh** when a complete snapshot exists and an event arrived within 15 seconds, **stale** after 15 quiet seconds, or **missing** when no snapshot exists. Fresh views answer Process `runtime_state`, hibernation wake/idle checks, and task workspace commit/diff reads without SSH, falling back to Prometheus or SSH when stale or missing.

### Process usage

While at least one `viewer.*` member is present on a Node agent channel, the subscriber triggers a background `orbit:agent-view-publish` run every 15 seconds to read per-Process CPU and memory from Prometheus and broadcast [`process.usage`](/reference/events#process-usage) on `orbit`.

The subscriber also acts as the [executor result listener](/reference/executor#the-listener-and-the-hand-off) for `client-executor-result` and relays redacted live log chunks from `presence-node-logs.{id}`.

## Delivery

Gateway releases pin one verified delivery manifest in `apps/gateway/resources/agent/frozen-manifest.json` (currently `cli-v0.11000.0` / agent `0.39.0`, overrideable via `ORBIT_AGENT_DELIVERY`). Its format is:

```json
{
  "commit": "<full commit SHA>",
  "version": "0.22.0",
  "artifacts": {
    "linux-x86_64": {"sha256": "<sha256>", "path": "orbit-agent-linux-x86_64", "url": "https://github.com/hardimpactdev/orbit/releases/download/cli-v0.N.0/orbit-agent-linux-x86_64"},
    "executor-linux-x86_64": {"sha256": "<sha256>", "path": "orbit-executor-linux-x86_64", "url": "..."},
    "macos-aarch64": {"sha256": "<sha256>", "path": "orbit-agent-macos-aarch64", "url": "..."},
    "macos-x86_64": {"sha256": "<sha256>", "path": "orbit-agent-macos-x86_64", "url": "..."}
  }
}
```

Artifacts are cached in `ORBIT_HOME/agent-artifacts/<sha256>` and SHA-256 verified before every use.

## Install and upgrade

`node:add`, `node:converge`, and [fleet rollout](/reference/gateway-recovery#fleet-rollout) install `/usr/local/bin/orbit-agent` (`0755`), `/etc/orbit/agent/{config.toml,ca.pem,secret}` (`0700` dir), and `/etc/systemd/system/orbit-agent.service`.

The systemd unit runs as `root` with `NoNewPrivileges=yes`, `ProtectSystem=strict`, `ProtectHome=tmpfs`, read-only binds for `apps_root`, Tool Manager paths, and production homes, `SupplementaryGroups=orbit-env`, `PrivateTmp=yes`, and `MemoryMax=128M`. Binary replacements synchronize on `/run/lock/orbit-self-update.lock`. Each channel auth reports `<cargo-version>+<16-hex-sha256>` so the fleet rollout detects binary drift.

## Doctor

[Doctor](/cli/doctor/doctor) evaluates stored `host`, `units`, and `ufw` facts and agent liveness without contacting Nodes, reporting `node.agent_missing` or `node.agent_view_stale` when an agent is absent or silent.

## Checks

Run the Rust workspace checks (`cargo test`, `cargo clippy`) as a normal user; see [Bun and Rust projects](/reference/bun-and-rust-projects#node-agent).

GitHub Actions builds the test profile without debug information to leave room for tests, Clippy, and cache saving in the four-minute agent job. Debug assertions, local builds, and release profiles stay unchanged.

## Releases

A [CLI release](/reference/cli-binaries#published-releases) builds and publishes agent and executor binaries whenever `apps/agent` changes:

| Asset | Contents |
| --- | --- |
| `orbit-agent-linux-x86_64` | Static observer and command binary for Linux `x86_64` Nodes |
| `orbit-executor-linux-x86_64` | Static signed-order executor binary for Linux `x86_64` Nodes |
| `orbit-agent-macos-aarch64` | Command binary for Apple silicon Macs |
| `orbit-agent-macos-x86_64` | Command binary for Intel Macs |
| `manifest.json` | Delivery manifest with URLs and SHA-256 digests |

A new agent reaches the fleet only when a reviewed commit updates `frozen-manifest.json` and `frozen-commands.json` and is deployed. Schedule log following requires Cargo version `0.14.0` or newer.

## Limits

The agent has three platform and reporting limits:

- The observer runs on Linux `x86_64` only; macOS ships command binaries only (`macos-aarch64`, `macos-x86_64`). There is no `linux-aarch64` build.
- The observer reports host CPU, memory, and disk usage via `metrics` facts. Per-Process CPU and memory come from Prometheus via [`process.usage`](#process-usage).
- The agent never triggers `orbit self-update`; fleet rollout handles binary updates over SSH.

## Why it works this way

### Rejoin to ask for a snapshot

Reverb notifies all channel members whenever a new member joins, prompting the agent to send a fresh snapshot. When the Gateway subscriber sees events without a snapshot or a reset `sequence`, leaving and rejoining the channel triggers a snapshot without adding a separate control protocol.

### Typed commands over SSH

Compiling Node logic into a pinned Rust binary with validated JSON envelopes replaces ad-hoc shell scripts with testable, supervised commands that enforce their own privileges, locks, and deadlines.
