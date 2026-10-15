---
title: "CI runner role"
description: "How the ci-runner role hosts self-hosted runners for GitHub Actions in Incus containers on a Node, and how CI runners are created and removed."
covers:
  - apps/gateway/app/Domain/Nodes/RoleRegistry.php
---

# CI runner role

The `ci-runner` role prepares a Node to host self-hosted runners for GitHub Actions. Every runner runs in an unprivileged Incus container on that Node. Each runner is a CI runner record: the Gateway registers it with one repository through its [GitHub App](/reference/github-app), runs it, and deregisters it when you destroy it. [ADR 0213](/decisions/0213-run-ci-runners-through-a-ci-runner-role) holds the decision while it is built.

## Add the role

Add the role to an active Linux Node:

```bash
orbit node:role:add sabre ci-runner
```

The role shares a Node with every role except `gateway` and `vpn`. It cannot be assigned through `node:add --role`, and Cluster membership does not affect it.

The role installs Incus when it is missing. It creates the Incus project `orbit-ci`, the bridge `orbitci0`, the network ACL `orbit-ci-egress`, the project's restricted default profile, and a `ufw route allow in on orbitci0` rule. The ACL rejects every private, CGNAT, loopback, link-local, multicast, and reserved IPv4 range, and all IPv6. So a job reaches the public internet, but not the Node, the Gateway, WireGuard, or the LAN. The task VM stray report and Doctor treat the `orbit-ci` project as owned by the role.

## Create a runner

Create a runner on a Node that has the role. The Gateway records it, creates its container, registers it, and starts it.

```bash
orbit ci-runner:create sabre sabre-1 --repository=nckrtl/orbit --label=sabre --label=orbit-shard
```

Every runner carries the labels `self-hosted`, `Linux`, and its architecture label, such as `X64`, plus each `--label`.

Each container has a `runner` user with no `sudo`. Every tool a job needs comes from the `ci-runner` profile of the machine software recipe. A CI step that needs `sudo`, such as the `privileged` test group, runs on a GitHub-hosted runner instead.

Runners are persistent. One runner takes job after job in the same container, so caches stay warm and a job can leave files for the next job.

| Command | Effect |
| --- | --- |
| `ci-runner:create <node> <name> --repository=<owner>/<repository> [--label=…]` | Records, creates, registers, and starts a runner. |
| `ci-runner:list [--node=…]` | Lists CI runners and their status. |
| `ci-runner:show <runner>` | Shows one runner and the status GitHub reports. |
| `ci-runner:destroy <runner>` | Stops the runner, deregisters it, and deletes its container. |

## Registration tokens

The Gateway mints each registration and removal token through the GitHub App, with only `Administration: write` for the runner's repository. When the installation has not accepted that permission, the command fails with `ci_runner.permission_missing`.

The Gateway sends the token on standard input and never stores, logs, or returns it. The runner's `config.sh` takes the token as an argument, so it shows in the container's process list for a few seconds. After registration, the runner keeps its own long-lived credentials in its container. Destroying the runner deletes them and deregisters it.

## Failure codes

The role and runner commands fail with these codes.

| Code | Meaning |
| --- | --- |
| `validation.failed` | The repository or labels are invalid, or the role conflicts with another role on the Node. |
| `ci_runner.role_missing` | The Node does not have the `ci-runner` role. |
| `ci_runner.permission_missing` | The GitHub App installation has not accepted `Administration: write`. |

## Remove the role

Remove the role when the Node stops hosting runners.

```bash
orbit node:role:remove sabre ci-runner --force
```

Removal refuses while the Node has CI runners, unless you pass `--force`; the Gateway then destroys each runner first. `--purge-data` also deletes the `orbit-ci` project, its images, the bridge, the ACL, and the ufw rule. `--offline` removes only Gateway state, so the runners stay registered with GitHub until you remove them there.

[Doctor](/cli/doctor) compares each CI runner with its container and reports a missing, stopped, or unexpected runner. It does not call GitHub.

## Why it works this way

### Every runner runs in a container

A job on the host that gains root can act as the Node toward the Gateway, which trusts WireGuard source addresses. A container with an egress ACL keeps every job away from the Node, so one model fits both a dedicated Node and a shared `app-dev` Node.

### Jobs have no sudo

No CI step on a self-hosted runner needs `sudo`, because the recipe profile installs the tools in advance. Without it, a job cannot change its container's network or packages.

### The App mints every token

An operator token lasts one hour, must be fetched for each add and repair, and cannot deregister a runner. The App mints a token for each operation with only the permission that operation needs. The cost is that the App holds `Administration: write`, which also covers repository settings.

### Runners are records

Each runner has its own registration, status, and failures. A record gives each of these a home, and a Node setting would hide them.
