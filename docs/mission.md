---
title: "Mission"
description: "Why Orbit exists, how it works with Conn and coding agents, what it manages, and the principles behind its design."
---

# Mission

Orbit is a self-hosted platform for developing, running, and maintaining software on machines you own. It brings your servers, application environments, automated agent tasks, and [Conn](/reference/conn) workspaces under one control plane.

## Why Orbit exists

Developing, deploying, and maintaining software usually splits the same project across disconnected tools: local setups, CI runners, hosting panels, monitoring dashboards, and separate agent workspaces. Orbit connects those pieces on your own hardware so your machines, applications, tasks, and change history live in one place.

## How Orbit works

One central Gateway stores fleet state, checks permissions, and coordinates changes over a private WireGuard network. Your Linux and macOS machines (called [Nodes](/concepts#fleet)) run the applications, databases, and background work.

Humans and coding agents operate Orbit through the same CLI, API, and MCP server with the same permissions. Orbit also integrates tightly with [Conn](/reference/conn), the companion agent development environment. You plan, chat, and steer agents in Conn across your devices, while Orbit handles identity, shared profiles, server pairing, and isolated container workspaces behind the scenes.

## What Orbit does

Orbit covers three areas on the machines you add.

- **Machines and applications.** Runs development containers, production releases, domains, databases, background processes, scheduled jobs, metrics, and certificates on your Nodes.
- **Planned agent work.** Through [Tasks](/reference/tasks), Orbit runs coding agents in isolated project copies, verifies each subtask against your checks, and opens a pull request.
- **Conn integration.** Through the [Conn layer](/reference/conn), Orbit signs devices in as Nodes, syncs profiles and settings, and launches threads in project containers.

## Where Orbit stops

Orbit focuses on running machines and workloads rather than replacing the tools where you think and review.

- **No built-in IDE or chat UI.** You plan and review in [Conn](/reference/conn) or your own tools; Orbit runs the environments and executes the work.
- **Shared notes only.** The plain-text editor in [Project Documents](/reference/project-documents) is a narrow exception for project notes, backed by the same API agents use.
- **Only what you add.** Orbit manages only the machines and resources you register.
- **Project-owned workflows.** Each project defines its own setup, checks, and agent rules in its repository, keeping Orbit independent of your stack.

## Principles

Every architecture decision in Orbit follows these principles.

- **Agents operate, humans steer.** Every action is a command that an agent can run and a human can inspect.
- **One way, one name.** Each task has one supported path, and each concept uses the same name in the CLI, API, database, and docs.
- **No exceptions and no legacy.** Orbit refuses unsupported combinations instead of patching around them, and removes replaced paths rather than keeping compatibility layers.
- **Deterministic first.** Use code and automated checks wherever possible; leave only judgment calls to language models.
- **Lean.** Every feature has to earn its place, and unused parts get deleted.
- **The documentation describes the present.** The docs are the current source of truth, and completed design decisions are folded into them.
- **Security fits the real threat model.** Orbit defends real trust boundaries and avoids adding layers against an attacker who is already inside.

Next, read [Architecture](/architecture) to see how Orbit's components fit together.
