---
title: "Agent runtime and threads"
description: "Plan from 9 November 2026: a T3 server in each container is the agent engine, a T3 thread in an Orbit Project is an Instance, and a task can attach to that Instance. The decisions, the findings behind them and the order of work."
---

# Agent runtime and threads

A task group or a development Instance gets one isolated runtime: an Incus container with a T3 server inside. [ADR 0250](/decisions/0250-run-instances-and-task-groups-in-incus-containers) decides the runtime and the engine. [Instance containers](/reference/development-containers) specifies the Instance side.

Starting a thread in an Orbit Project in Conn creates an Instance. The thread is a session of that Instance's T3 server. A task can attach to the Instance: while the task flow runs, Swamp drives the turns; otherwise the user drives from the thread. Both use the same sessions, so work passes from one to the other without a handover.

The first version of this plan had a separate runtime object with two drivers and a "manual mode". That is dropped: Swamp is involved either way, so the Instance and an optional attached task are enough.

This page records the agent and client decisions and the order of work. It is a plan, not a contract: each step changes the owning reference pages when it lands.

## Decided

These decisions are settled. Later steps build on them without asking again.

| Date | Decision |
| --- | --- |
| 2026-11-09 | Agents always run with full access. No approval or permission support is planned. |
| 2026-11-09 | Pi and Pi Durable are used through their native features and public extension points. Orbit does not fork or patch them. |
| 2026-11-09 | Skills, prompt templates, system prompts and MCP servers come from the project. The runtime injects none of its own, apart from the environment notes below. |
| 2026-11-09 | A container reaches models through a placeholder key. A forwarder on its Node passes the calls to the model router guard. |
| 2026-11-09 | The guard swaps in the real key. No personal provider login enters a container. |
| 2026-11-09 | A T3 server inside each container is the agent engine. It replaces Pi Durable in containers. |
| 2026-11-09 | Swamp drives T3 through its `/mcp` endpoint and WebSocket API. The Pi server stays on task VMs until they switch. |
| 2026-11-09 | Inside a development container, the app and every Orbit Process run as `orbit`. |
| 2026-11-09 | T3, Claude Code and Codex run as `agent`, and so do their shell commands. There is no wrapper shell and no sudo. |
| 2026-11-09 | T3, Claude Code, Codex and the environment notes live once per Node, in a versioned read-only agent folder. Every development container mounts it. |
| 2026-11-09 | The runtime binaries have their own such folder. |
| 2026-11-09 | An update flips a link and restarts T3 per container. The CLI self-updaters are off. |
| 2026-11-09 | Each Project type has an environment note. `~/.claude/CLAUDE.md` and `~/.codex/AGENTS.md` link to it. A note states environment facts only: a narrow exception to "the runtime injects no prompts". |
| 2026-11-09 | Preview URLs reach only Orbit Processes. Agents may run their own development servers and view them in T3's browser, without restriction. |
| 2026-11-09 | A T3 thread in an Orbit Project is an Instance. Orbit registers its T3 server. |
| 2026-11-09 | The user pairs through Conn. Access follows the node access edges to the Instance's Node. |
| 2026-11-09 | A task can attach to the Instance. There is no runtime object and no separate mode for work that a user drives. |
| 2026-11-09 | A container that is cloned must never have run T3. Every new Instance is a clone of the Project's default Instance, which never runs T3. |
| 2026-11-09 | T3 creates its identity on the first start of each clone. |
| 2026-11-09 | T3 history is deleted with its Instance. An archive comes later. |
| 2026-11-09 | MCP servers come from the project's own files. The agents use their own subagents only. |

The earlier decision that T3 threads live on the always-on Mac mini and drive an agent in a container from outside is replaced by the T3 server inside the container.

## Findings that shape the plan

These facts come from reading T3, Pi Durable 1.5.0 and Orbit, and from two spikes on Beast on 9 November.

### T3 in a container

A stock T3 server ran in an unprivileged container as an unprivileged user, from a read-only shared folder. Claude Code and Codex turns ran through the AI router with only a placeholder key inside. T3 continued a turn after a restart in the middle of a tool call. Its changed-files view worked over WireGuard from a Mac. A copy of the container started with T3 ready in about 3 s. [Instance containers](/reference/development-containers#evidence) has the numbers.

### What T3 already offers Swamp

T3's MCP endpoint creates projects and threads, sends a turn (`queue`, `steer`, `restart`), waits for a turn, reads its result and interrupts it. Its WebSocket API streams a thread and returns turn diffs. A session issued with `t3 auth session issue` authenticates both. Swamp needs no change to T3 to drive it.

### What Pi Durable lacks

Pi Durable 1.5.0 covers the chat loop, but has no MCP client and no subagent tool. Pi coding-agent extensions with UI dialogs cannot run on it. T3's own Pi provider runs `pi --mode rpc`, not Pi Durable. These were the reasons to look at T3 inside the container.

### Orbit gaps the spikes found

The spikes found three things that Orbit must change or accept.

- The model router guard admits only the task VM WireGuard range. It also refuses the `?beta=true` query that Claude Code always sends. A separate PR builds the per-Node forwarder and the guard change.
- T3 prints a pairing token to its journal on every start. With two users, only root and `agent` can read it.
- Each container holds one T3 port on its Node's WireGuard address.

## Steps

The work runs in two tracks. Each step lands as its own change and updates the reference pages it touches.

### Track A: container runtime (ADR 0250)

Track A replaces task VMs and the Project VM with containers, in the order ADR 0250 sets.

| Step | Work | Status |
| --- | --- | --- |
| 1 | Put ADR 0250 and this plan up for review. | In review |
| 2a | Reboot all managed Nodes into current kernels. | Done: 7.0.0-43 on 9 November |
| 2b | Block SCTP on every Node through node converge. | In progress in the reboot-fixes PR; set by hand on Beast, Shark and Sabre |
| 2c | Define the container egress and DNS model on Beast. | Open |
| 2d | Keep secrets off agent hosts. | Open |
| 2e | Patch kernels automatically, with planned reboots. | In progress |
| 3 | Run Instances in containers on the Nodes of a build list, with T3 inside. | Specified in [Instance containers](/reference/development-containers); plan in [Container runtime](/solutions/development-container-runtime) |
| 4 | Run tasks in Instance containers, driven by Swamp through T3. | Planned in [Container runtime](/solutions/development-container-runtime#pr-7-run-a-task-in-a-clone) |
| 5 | Add the nested fleet as a Project feature, and run command proofs in an Orbit Instance container. | Planned in [Container runtime](/solutions/development-container-runtime#pr-10-the-nested-fleet) |
| 6 | Parity gate: Orbit's command proof cases in an Orbit Instance container with the nested fleet match their task VM results. | Specified in [Parity gate](/solutions/development-container-runtime#parity-gate) |
| 7 | Switch task work on Beast, then Shark, in a window with no proofs or merges in flight. | Open |
| 8 | Switch development: sleep and wake, threads from Conn, migration, every `app-dev` Node. | Planned in [Container runtime](/solutions/development-container-runtime#pr-13-sleep-and-wake) |
| 9 | Remove the task VM runtime, the Pi server and the task container setting. | Open |
| 10 | Run production Instances in containers on every `app-prod` Node. | Planned in [Production Instances](/solutions/development-container-runtime#production-instances) |

### Track B: threads and tasks

Track B builds on steps 3 and 4. Its steps are PRs of the [container plan](/solutions/development-container-runtime#build-prs): PR 14, PR 9 and PR 7 with PR 8.

1. **pi-server on Pi 1.5.0.** Done.
2. **Drive an external task group from a T3 thread through the Orbit MCP.** What got in the way became fixes in the MCP. The last of them is in review.
3. **Thread to Instance.** Starting a thread in an Orbit Project creates an Instance, registers its T3 server and pairs Conn with it.
4. **Attach a task.** A task drives the turns of an existing Instance's thread through T3's MCP. Pausing the task leaves the thread to the user.
5. **Tasks on T3.** A task runs in an Instance container, and Swamp drives its T3. There is no separate task container.
6. **Later.** A web UI on WireGuard shows the chat and the preview side by side. OAuth for MCP users outside the network follows it.
