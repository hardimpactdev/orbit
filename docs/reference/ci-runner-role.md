---
title: "CI runner role"
description: "How the ci-runner role hosts self-hosted CI runners on a Node, how CI runners are created and removed, and why the role has two modes."
covers:
  - apps/gateway/app/Domain/Nodes/RoleRegistry.php
---

# CI runner role

The `ci-runner` role prepares a Node to host self-hosted CI runners. Each runner is a CI runner record: the Gateway registers it with GitHub through its [GitHub App](/reference/github-app), runs it on the Node, and deregisters it when you destroy it. [ADR 0213](/decisions/0213-run-ci-runners-through-a-ci-runner-role) holds the decision while it is built.

## Add the role

Add the role to an active Linux Node and choose its mode:

```bash
orbit node:role:add sabre ci-runner --mode=host
orbit node:role:add beast ci-runner --mode=container
```

| Mode | Where jobs run | Shares a Node with |
| --- | --- | --- |
| `host` | On the Node, as the `orbit-ci` user | No other role |
| `container` | In unprivileged Incus containers in the project `orbit-ci` | Every role except `gateway` and `vpn` |

The mode is stored in the Node setting `ci_runners`. To change it, destroy the Node's CI runners, remove the role, and add it again. The role cannot be assigned through `node:add --role`. Cluster membership does not affect it.

Both modes install the tools of the `ci-runner` recipe profile. A job gets passwordless `sudo` in both modes, so setup actions that install packages work.

### Host mode

The role creates the `orbit-ci` user, with passwordless `sudo` and no access to the managed user's home. An nftables rule that the role owns rejects traffic from `orbit-ci` to `10.0.0.0/8`, `172.16.0.0/12`, `192.168.0.0/16`, `100.64.0.0/10`, `fc00::/7`, and `fe80::/10`. So a job reaches the public internet and the local resolver, but not the Gateway, WireGuard, or the LAN. A command that a job runs with `sudo` runs as root, which the rule does not cover. So a runner in `host` mode accepts only trusted code, such as pushes and pull requests from branches in the same repository. Each runner is one `orbit-ci-runner@<name>` service with its own work directory mounted at `/home/runner/work`.

### Container mode

The role creates the Incus project `orbit-ci`, the bridge `orbitci0`, the network ACL `orbit-ci-egress`, the project's restricted default profile, and a `ufw route allow in on orbitci0` rule. The ACL rejects every private, CGNAT, loopback, link-local, multicast, and reserved IPv4 range, and all IPv6. Each runner is one container with a `runner` user that has passwordless `sudo`. Root in the container is still unprivileged on the host, so the ACL holds.

## Create a runner

Create a runner on a Node that has the role. The Gateway records it, registers it, and starts it.

```bash
orbit ci-runner:create sabre sabre-1 --target=repo:nckrtl/orbit --label=sabre
orbit ci-runner:create beast ci-beast-1 --target=repo:nckrtl/orbit --label=orbit-shard
```

`--target` is `repo:<owner>/<repository>` or `org:<organization>`. A personal account has no organization runners, so its repositories use `repo:`. Every runner carries the labels `self-hosted`, `Linux`, and its architecture label, such as `X64`, plus each `--label`.

The Gateway mints a registration token for the target through the GitHub App and passes it to the Node without storing or printing it. A repository target needs the App permission `Administration: write`. An organization target needs `Self-hosted runners: write`. When the installation has not accepted the permission, the command fails with `ci_runner.permission_missing` and names it.

Runners are persistent. One runner takes job after job and keeps its work directory, so caches stay warm and a job can leave files for the next job.

| Command | Effect |
| --- | --- |
| `ci-runner:create <node> <name> --target=… [--label=…]` | Records, converges, registers, and starts a runner. |
| `ci-runner:list [--node=…]` | Lists CI runners and their status. |
| `ci-runner:show <runner>` | Shows one runner and the status GitHub reports. |
| `ci-runner:destroy <runner>` | Stops the runner, deregisters it, and removes its service or container and work directory. |

## Failure codes

The role and runner commands fail with these codes.

| Code | Meaning |
| --- | --- |
| `validation.failed` | The mode, target, provider, or labels are invalid, or the role conflicts with another role on the Node. |
| `ci_runner.role_missing` | The Node does not have the `ci-runner` role. |
| `ci_runner.permission_missing` | The GitHub App installation has not accepted the permission that the target needs. |

## Remove the role

Remove the role when the Node stops hosting runners.

```bash
orbit node:role:remove sabre ci-runner --force
```

Removal refuses while the Node has CI runners, unless you pass `--force`; the Gateway then destroys each runner first. `--purge-data` also deletes the Incus images and the runner software cache. `--offline` removes only Gateway state, so the runners stay registered with GitHub until you remove them there.

[Doctor](/cli/doctor) compares each CI runner with its service or container and reports a missing, stopped, or unexpected runner. It does not call GitHub.

## Why it works this way

### Two modes

A dedicated Node, such as Sabre, gains nothing from a container boundary: a job shares the Node with nothing it could reach. A shared Node, such as an `app-dev` Node with live Instances, cannot run jobs safely without one. So the role has a mode, and each mode refuses the combinations it cannot make safe. Role conflicts usually depend only on the role. These depend on the mode, so the Gateway checks them against the stored mode.

### Jobs have sudo

Common setup actions install packages, and a runner without `sudo` makes every such tool a recipe change. Runners accept only trusted code, so the role trades the extra boundary for runners that behave like GitHub-hosted ones. In `host` mode, a job that runs as root can bypass the egress rule and use the Node's WireGuard identity, which the Gateway trusts. That is why a host-mode Node has no other role.

### The App mints every token

An operator token lasts one hour, must be fetched for each add and repair, and cannot deregister a runner. The App mints a token for each operation with only the permission that operation needs. The cost is that the App holds `Administration: write`, which also covers repository settings.

### Runners are records

Each runner has its own registration, status, and failures. A record gives each of these a home, and a Node setting would hide them.
