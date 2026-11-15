---
title: "ADR 0258: Choose the container server per Project"
sidebarTitle: "0258 Container server"
description: "In progress. A Project chooses which server runs in its Instance containers: the T3 server (default) or the Conn Server. The runtime records the choice when Orbit reserves it, and every later step reads that record. Only the program differs; credentials and the client contract stay. The Conn Server's recipe entry follows once a release exists."
---

# ADR 0258: Choose the container server per Project

A Project chooses the server that runs in its Instance containers: `t3`, the T3 server Orbit runs today and the default, or `conn`, the [Conn Server](https://github.com/hardimpactdev/conn-server). The Conn Server is a Rust server with the same client contract: `/mcp`, `/.well-known/t3/environment`, the HTTP and WebSocket API, and the `t3` CLI subcommands that Orbit calls. Only the program differs. The purpose is a switch to try the Conn Server on one Project.

## Status

In progress. Decided by the owner on 2026-11-15. The Conn Server's recipe entry is the open part: its repository is private and has no release yet, so the agent folder holds no `conn-server` program and Orbit refuses the `conn` choice.

Principle: [One way, one name](/mission#principles) and [No exceptions and no legacy](/mission#principles).

## Context

Each container runs one agent server as `agent`, from the Node's shared agent folder ([Shared folders](/reference/development-containers#shared-folders)). The image's `t3.service` starts `/opt/orbit-agent/current/agent/bin/t3`. Orbit calls the same program inside the container: the create's sessions and `project add`, and a task copy turn's `thread` commands and its RPC helper.

The agent folder is shared by every container on a Node, so the choice cannot live in the folder. The default Instance never runs a server, and clones copy the default, so the choice cannot live in the image or the seed either. The two servers store threads and pairings differently, and no converter exists, so a container cannot change its server.

## Decision

- **Setting.** The Project field `container_server`: `t3` (default) or `conn`. `project:update --container-server`, the API, the SDK and MCP set it. It changes only what a later reservation records, so it may change while the Project has Instances.
- **Record.** When Orbit reserves the runtime of a container with a server (a clone, or a checkout Instance that moves into a container), the runtime records the Project's choice as its own `container_server`. A runtime without a server, the default's, records none. Every later step reads the runtime's record, never the Project's setting. Existing runtimes record `t3`.
- **Program.** The record names one program of the agent folder: `bin/t3` or `bin/conn-server`. The create's sessions and `project add`, and a task turn's `thread` commands and RPC helper, run that program.
- **Unit.** A drop-in, `t3.service.d/20-orbit-server.conf`, sets the unit's `ExecStart` to the recorded program, with the same arguments. A clone gets it before its first start, with T3's other files; a migrated checkout gets it with the agent's files, and the T3 phase reloads systemd before it starts the unit. The image and its unit stay as they are: the unit keeps its name, its `PATH` (the runtime folder's `node`, which the Conn Server's SDK host needs) and its credentials (`model.env` and Codex's `config.toml`).
- **Refusal.** A choice whose program the recipe's agent folder does not pin is refused: `project:update` and every reservation of a server runtime answer `project.container_server_unavailable` (409) before any record. Until the recipe pins a Conn Server release, `conn` is refused.
- **Names.** Orbit's names for the container's server stay: the unit `t3.service`, the T3 port, T3 registration and the `t3` keys of the intents cover both servers.

## Rejected alternatives

- A container-local link, written at clone time to `bin/t3` or `bin/conn-server`, that the unit and every call go through. Every container made before the link would lack it, so Orbit's calls would need the old path beside the new one, or a converge that adds the link to existing containers. The runtime record is the pattern Orbit already uses for the Incus project, and existing runtimes get their value from the migration.
- A new image with the link in its unit. A clone copies its default, and an existing default keeps the image it was made from, so a new image reaches no clone of an existing default.
- Read the Project's setting at each call. A setting changed after a create would point an existing container at the other server, whose store does not hold its threads.
- A Node or Gateway setting. The trial is per Project.

## Consequences

- A Project switches to the Conn Server for its new containers only. Its existing containers keep T3; a container that should change is destroyed and created again.
- An agent folder update restarts both servers the same way, with `systemctl try-restart t3.service`.
- `conn:environment-restart` counts each direct child of the server that is not a provider as busy. The Conn Server's SDK host runs on `node` as such a child, so that restart may answer `busy` for a Conn container until the restart rule names it.
- Follow-up: once a Conn Server release exists at a URL Orbit can fetch, add `instances.agent.conn-server` to the recipe (`bin`: `{"conn-server": "conn-server-<version>-linux-x64/conn-server"}`), and the `conn` choice works without another change.

## Affects

- Components: apps/gateway, apps/cli, packages/php-sdk, swamp, apps/docs
- ADRs: [0250](/decisions/0250-run-instances-and-task-groups-in-incus-containers)
- Detail: [Container server](/reference/development-containers#container-server), [Project fields](/reference/projects#fields)
- Verify: Gateway Pest tests of the setting, the refusal, the record, the drop-in and the turn intent; CLI and SDK tests of the option and the field; the Swamp test of the turn's program check; a by-hand check of the drop-in with both programs in an Incus container
