---
title: "Database role"
description: "What the database role converges on a Node, which roles it shares a Node with, and how add, converge, and remove behave."
covers:
  - apps/gateway/app/Infrastructure/Nodes/Roles/DatabaseRoleBaseline.php
---

# Database role

The `database` role ensures a Linux Node can run Docker and marks it as a host for shared database Processes. The role owns no database containers itself: shared MySQL, PostgreSQL, and Valkey containers are managed as Node [Processes](/reference/processes-and-schedules) or [Database servers](/reference/database-servers), and the [database connection](/reference/database-connections) registry does not require this role.

## Add and converge

Add the role to an active Linux Node (or pass `--role=database` during `node:add`):

```bash
orbit node:role:add <node> database
```

Retry a failed or active assignment with `--converge`. Convergence requires a WireGuard IP (`database.wireguard_ip_missing` at `role-prerequisites`) and installs Ubuntu's `docker.io` package via `role.install-prerequisites` unless Docker CE is already installed and running (`database.prerequisite_failed` on error, wrapped in `node_role.convergence_failed`). No extra options are accepted (`validation.failed` otherwise), and Docker is not recorded as a Tool.

## Shared Nodes

`database` shares a Node with `app-dev`, `router`, `metrics`, `websocket`, `analytics`, `ci-runner`, and `swamp` in any order. It conflicts with `gateway`, `vpn`, `ingress`, and `app-prod` (`validation.failed`). See [Role compatibility](/reference/node-provisioning#role-compatibility).

## Remove

Remove the role with `orbit node:role:remove <node> database --force`. Removal deletes only the role assignment; Docker, its service, and all Node Processes stay intact (`--purge-data` has no additional effect). While assigned, [Doctor](/cli/doctor) verifies that Docker is installed and running.

## Why it works this way

### Processes own the containers

Keeping container lifecycles in Node Processes avoids giving shared databases two owners, and keeping Docker installed on role removal ensures existing Processes continue running.

### The remaining conflicts

`gateway`, `vpn`, `ingress`, and `app-prod` are dedicated control-plane, network, public-edge, or production roles where shared database hosts do not belong.
