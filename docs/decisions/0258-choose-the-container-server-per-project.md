---
title: "ADR 0258: Choose the container server per Project"
sidebarTitle: "0258 Container server"
description: "In progress. Every task copy runs the Conn Server. A Project chooses which server runs in its other Instance containers: the T3 server (default) or the Conn Server. The runtime records the choice when Orbit reserves it, and every later step reads that record. Credentials and the client contract stay. The recipe pins Conn Server 0.2.0 and its headless Chrome."
---

# ADR 0258: Choose the container server per Project

A Project chooses the server that runs in its Instance containers: `t3`, the T3 server Orbit runs today and the default, or `conn`, the [Conn Server](https://github.com/nckrtl/conn), which the Conn monorepo holds. The Conn Server is a Rust server with the same client contract: `/mcp`, `/.well-known/t3/environment`, the HTTP and WebSocket API, and the `t3` CLI subcommands that Orbit calls. The purpose was a switch to try the Conn Server on one Project. Since 2026-11-19 every task copy runs the Conn Server, and the Project's choice governs only its dev containers.

## Status

In progress.

The owner decided on 2026-11-15. The recipe pins Conn Server 0.2.0 as the agent folder's part `conn-server`, with Chrome headless shell 157.0.8103.78 as `chrome-headless-shell`, so Orbit accepts the `conn` choice and supplies its preview browser. On 2026-11-16 a clone of the first Project that chose `conn` ran the Conn Server on Beast, and one agent turn through `thread start --wait` completed.

On 2026-11-19 the owner made the Conn Server the server of every task copy, after a task group on Project 20 ran its question, Stop, reviewer turn and thread index on it with every field Orbit reads matching T3, at a twentieth of T3's memory. Dev containers keep the Project's choice while Conn clients still call methods that only T3 serves.

Principle: [One way, one name](/mission#principles) and [No exceptions and no legacy](/mission#principles).

## Context

Each container runs one agent server as `agent`, from the Node's shared agent folder ([Shared folders](/reference/development-containers#shared-folders)). The image's `t3.service` starts `/opt/orbit-agent/current/agent/bin/t3`. Orbit calls the same program inside the container: the create's sessions and `project add`, and a task copy turn's `thread` commands and its RPC helper.

The agent folder is shared by every container on a Node, so the choice cannot live in the folder. The default Instance never runs a server, and clones copy the default, so the choice cannot live in the image or the seed either. The two servers store threads and pairings differently, and no converter exists, so a container cannot change its server.

## Decision

- **Task copies.** A task copy, a clone that a task group owns, always runs the Conn Server, for every Project. No Project setting changes that, and a new Project needs none.
- **Setting.** The Project field `container_server`: `t3` (default) or `conn`. It chooses the server of the Project's dev containers only: clones that are not task copies, and checkout Instances that move into containers. `project:update --container-server`, the API, the SDK and MCP set it. It changes only what each reservation after the change records, so it may change while the Project has Instances.
- **Record.** When Orbit reserves the runtime of a container with a server (a clone, or a checkout Instance that moves into a container), the runtime records its server as its own `container_server`: `conn` for a task copy, the Project's choice for any other. A runtime without a server, the default's, records none. Each step after the reservation reads the runtime's record, never the Project's setting. Existing runtimes record `t3`.
- **Program.** The record names one program of the agent folder: `bin/t3` or `bin/conn-server`. The create's sessions and `project add`, and a task turn's `thread` commands and RPC helper, run that program.
- **Unit.** A drop-in, `t3.service.d/20-orbit-server.conf`, sets the unit's `ExecStart` to the recorded program, with the same arguments. A clone gets it before its first start, with T3's other files; a migrated checkout gets it with the agent's files, and the T3 phase reloads systemd before it starts the unit. For Conn, the drop-in also sets `CONN_SERVER_BROWSER_PATH` to `/opt/orbit-agent/current/agent/chrome-headless-shell/chrome-headless-shell-linux64/chrome-headless-shell`, the unpacked executable beside its support files, rather than its `bin` link. It also sets `T3CODE_SERVER_BROWSER_SANDBOX=0` because the container has no user namespaces. T3 gets neither override. The unit keeps its name, its `PATH` (the runtime folder's `node`, which the Conn Server's SDK host needs) and its credentials (`model.env` and Codex's `config.toml`).
- **Launch check.** The durable Conn launch runs the runtime's recorded program, and accepts the live `serverVersion` only when it equals the T3 server version that program states with `--version`: `0.1.7` for T3, `0.1.10` for Conn Server 0.2.0.
- **Refusal.** A server whose program the recipe's agent folder does not pin is refused: `project:update` and every reservation of a server runtime answer `project.container_server_unavailable` (409) before any record.
- **Names.** Orbit's names for the container's server stay: the unit `t3.service`, the T3 port, T3 registration and the `t3` keys of the intents cover both servers.

## Rejected alternatives

- A container-local link, written at clone time to `bin/t3` or `bin/conn-server`, that the unit and every call go through. Every container made before the link would lack it, so Orbit's calls would need the old path beside the new one, or a converge that adds the link to existing containers. The runtime record is the pattern Orbit already uses for the Incus project, and existing runtimes get their value from the migration.
- A new image with the link in its unit. A clone copies its default, and an existing default keeps the image it was made from, so a new image reaches no clone of an existing default.
- Read the Project's setting at each call. A setting changed after a create would point an existing container at the other server, whose store does not hold its threads.
- A Node or Gateway setting. The trial is per Project.
- A second Project setting for task copies. The owner wants every Project's task copies on the Conn Server, so the setting would have one useful value.
- Flip `container_server` to `conn` for every Project. Dev containers would lose the T3-only client methods they still need.

## Consequences

- Every task copy made after this decision runs the Conn Server; a copy made before keeps T3 until its group ends.
- A Project switches to the Conn Server for its new dev containers only. Its existing containers keep T3; to move a container to the other server, a person destroys it and creates it again.
- An agent folder update restarts both servers the same way, with `systemctl try-restart t3.service`.
- `conn:environment-restart` treats the Conn Server's SDK host as it treats a provider. The host, the providers under it and what stays in the `claude` session may go, as under T3; any other process under it keeps the container busy ([the rule](/reference/development-containers#existing-instances)).
- The recipe pins the Conn Server as the agent folder's part `conn-server`. `nckrtl/conn` publishes each release with the tag `conn-server-v<version>` and the asset `conn-server-<version>-linux-x64.tar.gz`, with its digest in the asset `conn-server-<version>-linux-x64.tar.gz.sha256`. The part's `bin` is `{"conn-server": "conn-server-<version>-linux-x64/conn-server"}`. A bump changes the part's version, URL and digest. The `chrome-headless-shell` pin comes from `nckrtl/conn`'s `apps/conn-server/crates/conn-preview/chrome.json`. Its ZIP stays intact when unpacked, including `.pak`, `.so` and locale files. The runtime folder's `browser-<package>` parts supply Chrome's system libraries, as for any headless browser. With the browser path configured, Conn never downloads Chrome; it refuses preview tools with a typed error if the executable is missing or has the wrong version.

## Affects

- Components: apps/gateway, apps/cli, packages/php-sdk, apps/docs
- ADRs: [0250](/decisions/0250-run-instances-and-task-groups-in-incus-containers)
- Detail: [Container server](/reference/development-containers#container-server), [Project fields](/reference/projects#fields)
- Verify: Gateway Pest tests of the setting, the refusal, the record (a task copy of a `t3` Project records `conn`), the drop-in and the turn intent; CLI and SDK tests of the option and the field; the Swamp test of the turn's program check; the Swamp test of the launch's version check for both programs; the Swamp test of the restart's process rule; a by-hand check of the drop-in with both programs in an Incus container
