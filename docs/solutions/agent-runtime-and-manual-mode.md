---
title: "Agent runtime and manual mode"
description: "Plan from 9 November 2026: one Pi Durable runtime in an Incus container with two drivers, Swamp for automatic tasks and an outside agent for manual work, plus the steps to get there."
---

# Agent runtime and manual mode

Nick's direction of 9 November 2026: a task group or a development Instance gets one isolated runtime, an Incus container with Pi Durable inside. Two drivers can work in it:

- **Automatic mode.** Swamp orchestrates the task, as task groups do today.
- **Manual mode.** Nick talks to an agent in any harness, such as T3 on the Mac mini or Claude Code. That agent drives the Pi Durable agent in the container through the Orbit MCP.

Both drivers use the same sessions, so work can pass from one to the other. [ADR 0250](/decisions/0250-run-instances-and-task-groups-in-incus-containers) decides the runtime. This page records the agent and client direction and the order of work. It is a plan, not a contract: each step changes the owning reference pages when it lands.

## Decided

These decisions are settled. Later steps build on them without asking again.

| Date | Decision |
| --- | --- |
| 2026-11-09 | Agents always run with full access. No approval or permission support is planned. |
| 2026-11-09 | Pi and Pi Durable are used through their native features and public extension points. Orbit does not fork or patch them. |
| 2026-11-09 | Skills, prompt templates, system prompts and MCP servers come from the project. The runtime injects none of its own. |
| 2026-11-09 | A container reaches models as task VMs do today: through a placeholder key that the model router guard accepts. No personal provider login enters a container. |
| 2026-11-09 | No T3 server per container. Measured idle memory: T3 about 390 MB, pi-server 88 MB, and 97 MB with one session. |
| 2026-11-09 | T3 threads live on the always-on Mac mini, which drives the agent in a container from outside. |
| 2026-11-09 | Development containers run as an unprivileged `orbit` user without sudo. The name can still change. |

## Findings that shape the plan

These facts come from reading T3, Pi Durable 1.5.0 and Orbit on 9 November. They decide which steps need new code.

### T3 and Pi

T3 ships a native Pi provider (`packages/provider-pi` upstream). It runs `pi --mode rpc` over stdio. That is the coding agent, not Pi Durable, and it does not speak pi-server's HTTP API.

### What Pi Durable 1.5.0 covers

Pi Durable covers the chat loop natively. That includes prompts with images, streaming, steering, queued messages, interrupt, model and thinking changes, compaction, fork, rollback and usage.

It has no MCP client and no subagent tool. Pi leaves subagents out on purpose. Durable documents a pattern instead: a tool that drives an owned child conversation. Pi coding-agent extensions with UI dialogs, and their slash commands, cannot run on Durable.

### The 1.5.0 upgrade breaks pi-server

Orbit's pi-server pins 1.4.0. Version 1.5.0 removed three things it uses: the parked task states, the commit subscription behind its live output, and two Chord state types. pi-server also reads full views, which 1.5.0 caps at a window.

### Changed files in T3

T3 builds its changed-files view from git in its own working directory. All its git work goes through one service, `VcsProcess.run` (`apps/server/src/vcs/VcsProcess.ts` in Conn). A second implementation could run those commands over SSH in a container. A few spots still use the local disk: temporary index files during checkpoints, the git trace file, and the repository detection cache.

### Manual orchestration exists

Task groups with `orchestration: external` are driven through `task:start`, `task:turn`, `task:check`, `task:publish` and `task:pr-*`. An outside agent with the Orbit MCP can drive Pi agents in a task VM today.

## Open questions

These need Nick's decision before the step that depends on them.

1. **What Conn shows for a container thread.** Chat only with Pi's edits as cards, or T3's changed-files view through an SSH-backed `VcsProcess`. The second is Conn fork code to carry through every upstream sync.
2. **The handover contract.** Which driver holds a session. How a task attaches to an existing runtime and hands it back.
3. **MCP from the project.** Which file lists a project's MCP servers. How the runtime offers their tools through `pi-mcp`.
4. **Subagents.** Leave them out, or let the orchestrator start more Pi sessions in parallel containers.

## Steps

The work runs in two tracks. Each step lands as its own change and updates the reference pages it touches.

### Track A: container runtime (ADR 0250)

Track A replaces task VMs and the Project VM with containers, in the order ADR 0250 sets.

| Step | Work | Status |
| --- | --- | --- |
| 1 | Put ADR 0250 and this plan up for review. | Written; not pushed |
| 2a | Reboot all managed Nodes into current kernels. | Done: 7.0.0-43 on 9 November |
| 2b | Block SCTP on every Node through node converge. | In progress in the reboot-fixes PR; set by hand on Beast, Shark and Sabre |
| 2c | Define the container egress and DNS model on Beast. | Open |
| 2d | Keep secrets off agent hosts. | Open |
| 2e | Patch kernels automatically, with planned reboots. | Open |
| 3 | Build the task container runtime next to the task VM runtime, selected by a Node setting. It needs a container base image, the nested setup of ADR 0250, and `SetCredential` units. | Specified in [Task containers](/reference/task-containers); plan and open questions in [Task container runtime](/solutions/task-container-runtime) |
| 4 | Parity gate: the command proof cases on task containers match their task VM results. Time it with the operations migration thread. | Specified in [Parity gate](/solutions/task-container-runtime#parity-gate) |
| 5 | Switch Beast, then Shark, in a window with no proofs or merges in flight. | Open |
| 6 | Run development Instances on the Node. Rework the unpushed `task-4193` branch to drop the Project VM; a bundle is kept at `~/apps/orbit-container-plan/.backup/proof-4193.bundle`. | Open |
| 7 | Remove the task VM runtime. | Open |

### Track B: agent runtime and manual mode

Track B gives the runtime its second driver. Steps 1 and 2 can start now; steps 3 and 4 need the containers of Track A step 3.

1. **pi-server on Pi 1.5.0.** Fix what 1.5.0 breaks. The task VM and Swamp Pi driver keep passing.
2. **Manual mode on today's task VMs.** Drive an external task group from a T3 thread on the mini through the Orbit MCP. Record what gets in the way.
3. **A runtime without a task.** Create, list and remove an Instance runtime with Pi Durable and no task. Orbit returns its URL and the driver's access.
4. **Handover.** A task attaches to an existing runtime without provisioning. Pausing the task hands the session back. One driver holds a session at a time.
5. **Conn view.** Settle open question 1. The spike for the changed-files option is an SSH-backed `VcsProcess` against one container.
6. **Project MCP.** Decide open question 3 and wire it into the runtime.
7. **Later.** A web UI on WireGuard shows the chat and the preview side by side. OAuth for MCP users outside the network follows it.
