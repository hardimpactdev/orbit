---
title: "Architecture"
description: "Orbit's components, trust model, Node roles, and how control, traffic, observation, tasks, and Conn flow through the fleet."
---

# Architecture

One central Gateway owns all fleet state in Orbit. When a human or agent requests a change, the Gateway validates permissions, records the intent, and hands execution to Swamp to apply on your Nodes. Nodes run your applications and report live status back to the fleet.

## Components

Each Orbit component lives in its own directory in the repository.

| Component | Code | Role |
| --- | --- | --- |
| Gateway | `apps/gateway` | Single source of truth for fleet state. Authorizes requests, coordinates Node changes, and serves the HTTP API, MCP server, and web app. |
| Swamp | `swamp/` | Applies changes to Nodes over SSH by running workflows started by the Gateway and reporting back each result. |
| CLI | `apps/cli`, `packages/php-sdk` | Thin client for humans and agents. Talks only to the Gateway API and never connects directly to a Node. |
| Web app | `apps/web`, `apps/desktop` | Single-page interface that reads the Gateway API and streams live state updates. The desktop app wraps it in a native shell. |
| Node agent | `apps/agent` | Daemon running on managed Linux Nodes that reports presence and Process state and executes typed commands from the Gateway. |

## Trust

Every Node joins one private WireGuard network. The Gateway identifies each caller by its WireGuard IP address and checks a directed access grant to decide which Nodes that caller may act on. Humans, coding agents, and Conn devices all use the same API under the same rules. Node agents authenticate to their own reporting endpoints using a per-Node secret.

The Gateway stays private and is reachable only over WireGuard. Public HTTP and HTTPS traffic enters only through Nodes with the `ingress` role, and the Gateway never shares a machine with `ingress`.

## Nodes and roles

A Node is any machine enrolled in your WireGuard fleet. Ubuntu 26.04 Nodes run Orbit service roles, while macOS Nodes support managed Tools through their existing SSH account and package managers without service roles. Tools do not require a role. See [macOS Nodes](/reference/node-provisioning#macos-nodes) for enrollment details.

On Ubuntu Nodes, Orbit manages services natively with systemd, runs development Instances in Incus containers, and runs container Processes and the `metrics` stack (Prometheus and Grafana) in Docker.

| Role | Responsibility |
| --- | --- |
| `gateway` | Runs the Gateway. One per fleet. |
| `vpn` | Runs the WireGuard hub. One per fleet. |
| `app-dev` | Runs development Instances and task workspaces. |
| `app-prod` | Runs production Instances. |
| `router` | Routes Cluster domains to the Node running each target Instance. |
| `ingress` | Accepts public HTTP and HTTPS traffic. |
| `database` | Runs shared database Processes in Docker. |
| `websocket` | Runs the realtime server. One per fleet. |
| `metrics` | Collects fleet metrics. One per fleet. |
| `analytics` | Collects visit analytics. One per fleet. |
| `swamp` | Runs Swamp serve and its workers, serving `swamp.orbit`. One per fleet. |
| `ci-runner` | Runs GitHub Actions runners and proof copies. |

Incompatible roles cannot share a Node. For example, `gateway` never shares a Node with `app-dev`, `app-prod`, `database`, `analytics`, `ingress`, or `ci-runner`. [Node provisioning](/reference/node-provisioning) lists every conflict rule.

## How the parts connect

Five main flows connect Orbit's components.

### Control

A human or agent runs a CLI command or MCP tool, sending a request to the Gateway over WireGuard. The Gateway authorizes the caller, records the Operation, and applies changes to each affected Node over a shared SSH connection.

### Traffic

A Route maps a domain to an Instance or local service on a Node or Cluster. Private traffic arrives over WireGuard, while public traffic enters through `ingress`. In a Cluster, the `router` Node forwards each request to the Node hosting the Instance, where Caddy serves it. See [Routes](/reference/routes).

### Observation

Node agents publish live status to the `websocket` Node so the Gateway stays current and the web app updates in real time. [Doctor](/cli/doctor) compares expected Gateway state against each Node's actual state and reports any drift without making changes.

### Tasks

When the [Tasks](/reference/tasks) extension is enabled, Orbit runs planned work in isolated container copies of a project's default Instance on an `app-dev` Node. Coding agents complete subtasks in order, verify each handoff against the project's task check, review the branch, and open a pull request through the GitHub App.

### Conn

When the [Conn](/reference/conn) extension is enabled, the Gateway signs Conn devices in as Nodes, syncs shared profiles and settings across devices, and manages pairings and container server sessions. Live T3 editor and agent traffic flows directly between each Conn device and its T3 servers over WireGuard without passing through the Gateway.

## Data

The Gateway stores all Orbit records in its SQLite database. [Project Documents](/reference/project-documents) keep folder hierarchy, versions, and encrypted storage credentials in SQLite, while file contents live in a private UpCloud S3-compatible bucket accessed only by the Gateway. Applications keep their own data in their own databases, and Orbit stores the connection records for them.

Next, read [Concepts](/concepts) for the core terms shared across Orbit.
