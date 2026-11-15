---
title: "ADR 0259: Deliver a Project's agent instructions from its Documents"
sidebarTitle: "0259 Agent instructions"
description: "In progress. A Project keeps its own agent instructions, an AGENTS.md and skills, in the reserved agent/ folder of its Project Documents. Each clone's create writes them, after the note of the Project's type, into a folder in the copy's own agent home, and links Claude Code and Codex to it. A copy keeps the version of its create."
---

# ADR 0259: Deliver a Project's agent instructions from its Documents

A Project keeps its own agent instructions in Orbit: an `AGENTS.md` and a `.skills` folder in the reserved `agent/` folder of its [Project Documents](/reference/project-documents#agent-instructions). Every clone of the Project's container default, a task copy among them, gets them at its create, so the implementer and the reviewer in the copy follow them.

## Status

In progress.

The owner decided on 2026-11-15: each Project has its own instructions, stored in Orbit, and every task copy gets them. A live copy that reads them as `agent` proves the release.

Principle: [Agents operate, humans steer](/mission#principles) and [One way, one name](/mission#principles).

## Context

A copy's agents read `~/.claude/CLAUDE.md` and `~/.codex/AGENTS.md`. Until now both linked to the note of the Project's type in the Node's agent folder ([Environment notes](/reference/development-containers#environment-notes)). The note states container facts only, and the same note serves every Project of that type.

Every container on a Node mounts the whole agent folder. A Project's text in that folder would be readable by the containers of every other Project on the Node. A copy cannot reach the Gateway, and the agent in a copy knows only its task, the code base and the web through the proxy.

Project Documents are per Project, stored in Orbit, versioned, and edited through the CLI, MCP, SDK and web.

## Decision

- **Store.** The Project's Documents, reserved folder `agent/` at the root: `agent/AGENTS.md`, and `agent/.skills/<skill>/SKILL.md` with the files beside it. An archived entry, or one below an archived folder, is absent. Other files in `agent/` are not instructions. While the `documents` extension is off, no Project has instructions.
- **Limits.** At most 32 files and 32 KiB in all, text in UTF-8 with no control character but a tab or a line end, each name after `.skills` of letters, digits, `.`, `_` and `-`. A create of a clone whose folder breaks a limit is refused before any record: `projects.agent_instructions_too_large` or `projects.agent_instructions_invalid` (422). The create intent crosses as one command argument, which Linux holds to 128 KiB, and JSON escapes text to at most three times its size.
- **Read.** The create of a clone reads the bodies before its admission transaction. A store failure refuses the create with the store's own error.
- **Folder.** The intent carries the folder's whole content. The copy's agent part makes `/home/agent/.config/orbit/agent` again, owned by root and readable by `agent`, with exactly those files: `AGENTS.md` is the note of the Project's type, then the Project's own text under `# This Project`; the skills follow as they are.
- **Links.** A clone links `~/.claude/CLAUDE.md` and `~/.codex/AGENTS.md` to the folder's `AGENTS.md`, and `~/.claude/skills` and `~/.agents/skills` to its `.skills`.
- **Version.** A copy keeps the version of its create. A Documents change reaches the next copy. Only the create writes the folder; a clone made before this decision keeps its links to the type note.
- **Show.** `project:show` reports `agent_instructions`: `source` (`project` or `type_note`), the current version number of `AGENTS.md`, and the skill names, from the records alone. It does not check the limits; the next create does.

## Rejected alternatives

- The Node's agent folder. Every container on the Node mounts it, so another Project's container reads the text.
- The Project's repository. The text would change with each branch and need a commit to change, and the owner wants it stored in Orbit.
- A new table or a Project setting. Documents already holds per-Project, versioned text with every client.
- Rewrite the folder at each task turn. The owner decided that a copy keeps the version it started with.
- The Project's text in place of the type note. The note carries the container facts that change with Orbit releases; a Project's own copy of them would go stale.

## Consequences

- Project Documents enter an agent's context for one reserved folder only.
- A Project's text that changes during a task group reaches the group's next copy, not its running one.
- An Orbit default skill, such as the reviewer's pull request skill, is not part of this decision. The slice that lets the reviewer write the pull request text adds it.

## Affects

- Components: apps/gateway, apps/cli, packages/php-sdk, apps/docs
- ADRs: [0250](/decisions/0250-run-instances-and-task-groups-in-incus-containers)
- Detail: [Agent instructions](/reference/development-containers#agent-instructions), [Project Documents](/reference/project-documents#agent-instructions)
- Verify: Gateway Pest tests of the intent, the limits and the version; the Documents contribution test; the guest test of the folder; the clone script test of the agent part's argument; a live copy of a fixture Project that reads both files as `agent`
