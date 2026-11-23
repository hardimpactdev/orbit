---
title: "Concepts"
description: "Core terms shared across Orbit's CLI, API, and documentation."
---

# Concepts

These are the core terms used across Orbit. Individual guides define any extra terms specific to their feature.

## Fleet

Terms for your machines, network, and permissions.

- **Gateway**: Control plane that stores all fleet state, authorizes requests, and coordinates changes across Nodes. Runs on Linux. See [Architecture](/architecture).
- **Node**: Any machine enrolled in your private WireGuard fleet. A Node can run service roles, managed Tools, or act as an operator device.
- **Role**: A job assigned to a Linux Node, such as `app-dev`, `app-prod`, or `ingress`. See [Nodes and roles](/architecture#nodes-and-roles).
- **Cluster**: An optional group of Nodes that share domain routing.
- **Access grant**: A directed permission that lets one Node act on another through the Gateway. See [node](/cli/node).
- **Tool**: A package that Orbit installs or adopts and keeps updated on a Node. See [Tools](/reference/tools).

## Applications

### Projects and instances

Terms for repositories, documents, and running copies of your code.

- **Project**: A Git repository and its default runtime settings, such as default branch and web root. See [Projects](/reference/projects).
- **Project Documents**: Versioned folders and notes owned by a Project, independent of Git branches and Instances. See [Project Documents](/reference/project-documents).
- **Instance**: One running copy of a Project on a Node, either for development or production. See [Applications](/domains/applications).
- **Default Instance**: The `default` Instance of a Project, tracking its default branch. Other development containers clone from it.
- **Container Instance**: An Instance running inside an Incus container on its Node. See [Instance containers](/reference/development-containers).
- **Deployment**: A prepared release activated on a production Instance, or an updated commit installed on a development default Instance. See [Deployments](/reference/deployments).

### Routing and services

Terms for domains, background jobs, and managed data services.

- **Route**: A domain that directs HTTP or HTTPS traffic to an Instance or Node service. See [Routes](/reference/routes).
- **Process**: A long-running background service managed by Orbit, such as a queue worker. See [Processes and schedules](/reference/processes-and-schedules).
- **Schedule**: A command that runs on a recurring timer for an Instance or Node. See [Schedules](/reference/schedules).
- **Database server**: A MySQL, PostgreSQL, or Valkey server that Orbit runs in Docker on a Node. See [Database servers](/reference/database-servers).

## Operations and extensions

Terms for tracked work, monitoring, auditing, and optional Gateway capabilities.

- **Operation**: Tracked asynchronous work started by the Gateway, with a status, event sequence, and request ID. See [Operations](/reference/operations).
- **Doctor**: A read-only check that compares expected Gateway state with each Node's live state and reports drift. See [Doctor](/cli/doctor).
- **Activity**: The Gateway's audit log of changes, failed requests, and sampled reads. See [Activity](/cli/activity).
- **Node agent**: The daemon on managed Linux Nodes that reports presence and Process state and runs typed commands from the Gateway. See [Node agent](/reference/node-agent).
- **Extension**: An optional Gateway feature toggled with one switch, such as `tasks` or `conn`. Disabled extensions stay hidden from clients. See [Extensions](/reference/extensions).

## Tasks

Terms for automated coding-agent work.

- **Task**: One feature or bug fix delivered as a single pull request. See [Tasks](/reference/tasks).
- **Subtask**: An ordered step inside a Task with its own deliverables and verification pass.
- **Deliverable**: A concrete output a Subtask must produce, such as a file, test, or passing command.
- **Task check**: The project-defined command Orbit runs to verify each Subtask handoff.
- **Task workspace**: The isolated container Instance cloned for a Task on an `app-dev` Node.

## Conn

Terms for the companion agent development environment.

- **Conn**: The companion editor and agent client (a fork of T3 Code) that pairs with Orbit over WireGuard. See [Conn layer](/reference/conn).
- **Profile**: A named bundle of shared workspaces, themes, and device settings synced across your Conn devices through the Gateway.
