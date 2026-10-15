---
title: "ADR 0213: Run CI runners through a ci-runner role"
sidebarTitle: "0213 CI runner role"
description: "In progress. A ci-runner role prepares a Node to host self-hosted CI runners, on the host or in Incus containers. Each runner is a Gateway record that Orbit registers through the GitHub App, converges, and removes."
---

# ADR 0213: Run CI runners through a ci-runner role

A new `ci-runner` role prepares a Node to host self-hosted CI runners. Each runner is a CI runner record that the Gateway registers with GitHub through its GitHub App, converges on the Node, and deregisters on removal. The role runs runners on the host of a dedicated Node, or in unprivileged Incus containers on a shared Node. GitHub Actions is the first provider; the record names its provider so that GitLab can follow.

## Status

In progress.

Principle: [Agents operate, humans steer](/mission#principles). Runners become commands that an agent can run and a human can read, instead of hand-made services and a shell script. It also serves [Security fits the real threat model](/mission#principles): runners accept only trusted code, so a job may hold root, and the boundary is what that code can reach. Exception to [One way, one name](/mission#principles): the role has two modes, `host` and `container`. A dedicated Node gains nothing from a container boundary, and a shared Node cannot run jobs safely without one. Each mode has one path, and the Gateway refuses every other combination.

## Context

Orbit's own CI already runs on Orbit Nodes, but Orbit does not manage those runners:

- Sabre has no role. Three runner services, `sabre-1` to `sabre-3`, run on the host as `github-runner`, with passwordless `sudo`. An nftables rule, made by hand, limits their egress. A job that runs `sudo` bypasses that rule and can act as Sabre toward the Gateway. The implementation loop describes [the setup](/reference/implementation-loop#self-hosted-gateway-runner).
- Beast and Shark are `app-dev` Nodes. Their shard runners run in unprivileged Incus containers that [`tools/ci-runners/setup.sh`](https://github.com/nckrtl/orbit/blob/main/tools/ci-runners/setup.sh) builds by hand. The implementation loop describes [those runners](/reference/implementation-loop#self-hosted-shard-runners).

Doctor sees none of this state, registration tokens pass through an operator's shell, and removal is a manual recipe.

GitHub registers a runner against a repository or an organization. A personal account, such as the owner of `nckrtl/orbit`, cannot have organization runners. The registration-token endpoint for a repository needs the App permission `Administration: write`. The organization endpoint needs the organization permission `Self-hosted runners: write`.

The [task VM host](/reference/node-settings#configure-a-task-vm-host) already shows how the Gateway drives Incus on a Node: a project, a bridge, an egress ACL, and a restricted profile.

## Decision

The Gateway owns the role, every CI runner record, and every registration. The parts below form one contract.

### The role and its mode

`orbit node:role:add <node> ci-runner --mode=host|container` assigns the role. The mode is required and is stored in the Node's typed setting `ci_runners`. It changes only by removing and adding the role, and only while the Node has no CI runners. The role is Linux-only, like every role, is not a singleton, is not relocatable, and cannot be assigned through `node:add --role`.

- `host` mode refuses a Node that has any other role, and every other role refuses a Node with `ci-runner` in `host` mode. A host-mode job shares the Node with no Instance, task VM, or service.
- `container` mode shares a Node with every role except `gateway` and `vpn`.

These rules depend on the mode, so `AssignRoleAction` checks them with a mode-aware guard instead of a static `RoleRegistry` conflict. Both refusals answer `validation.failed` before anything changes.

Cluster membership does not matter to the role. A Node keeps its Cluster when it gains or loses the role.

### What the role converges

Both modes install the runner software from the `ci-runner` profile of the [machine software recipe](/reference/node-provisioning), so a host-mode Node and a runner container carry the same tools.

In `host` mode the role creates the system user `orbit-ci` with passwordless `sudo` and no access to the managed user's home. An nftables rule, owned by the role, rejects traffic from `orbit-ci` to `10.0.0.0/8`, `172.16.0.0/12`, `192.168.0.0/16`, `100.64.0.0/10`, `fc00::/7`, and `fe80::/10`. DNS still works through the local resolver. A command that a job runs with `sudo` runs as root, which the rule does not cover. So a runner in `host` mode accepts only trusted code, such as pushes and same-repository pull requests. Each runner is one `orbit-ci-runner@<name>` systemd service with its own work directory mounted at `/home/runner/work`.

In `container` mode the role creates the Incus project `orbit-ci`, the bridge `orbitci0`, the ACL `orbit-ci-egress`, the restricted default profile, and the `ufw route allow in on orbitci0` rule, as `setup.sh host` does today. Each runner is one container in that project, with a `runner` user that has passwordless `sudo`. Root in the container is still unprivileged on the host, so the ACL holds.

Convergence runs as remote programs when [ADR 0211](https://github.com/nckrtl/orbit/pull/1251) is built first, and through the existing role baseline otherwise.

### CI runner records

A CI runner is a Gateway record with a Node, a unique name, a provider, a target, labels, and a status. The provider is `github`; any other value fails with `validation.failed`. A new ADR adds GitLab. The target is `org:<organization>` or `repo:<owner>/<repository>`. Labels always include `self-hosted`, `Linux`, and the architecture label, plus any labels the operator adds.

| Command | Effect |
| --- | --- |
| `ci-runner:create <node> <name> --target=… [--label=…]` | Records the runner, converges its service or container, registers it, and starts it. |
| `ci-runner:list [--node=…]` | Lists records with their status. |
| `ci-runner:show <runner>` | Shows one record and the status that GitHub reports for it. |
| `ci-runner:destroy <runner>` | Stops the runner, deregisters it from GitHub, and removes its service or container and its work directory. |

`ci-runner:create` refuses a Node without the role with `ci_runner.role_missing`.

### Registration through the GitHub App

The Gateway mints each registration and removal token through its [GitHub App](/reference/github-app). A repository target asks for a token with only `administration: write`; an organization target asks for one with only `organization_self_hosted_runners: write`. The token travels to the Node in a program's secret input and is never stored, logged, or returned. The App's manifest gains both permissions, and existing installations must accept them before a runner of that kind registers. A refused permission fails with `ci_runner.permission_missing` and names the permission.

Runners are persistent: one runner takes job after job, in the same work directory or container. Ephemeral runners need a new ADR.

### Removal and Doctor

`node:role:remove <node> ci-runner` refuses while CI runners exist, unless `--force` is given; then it destroys each runner first. `--purge-data` also deletes the Incus images and the runner software cache. `--offline` removes only Gateway state and leaves the runners registered with GitHub; the operator removes them there.

Doctor compares each CI runner record with its service or container on the Node and reports a missing, stopped, or unexpected runner. It does not call GitHub.

### Migration

The existing runners are recreated, not adopted. The operator adds the role to Sabre in `host` mode and to Beast and Shark in `container` mode, creates runners with the same labels, and then removes the hand-made runners. The pull request that builds this decision deletes `tools/ci-runners/` and rewrites the two runner sections of the implementation loop. The CI workflow keeps skipping the PHP setup step on self-hosted runners, because that step makes `/usr/local/bin` world-writable, which Orbit's service metrics refuse.

## Rejected alternatives

- Always run runners in containers: one model, but a dedicated Node such as Sabre gains nothing from a container boundary.
- Always run runners on the host: the role then never shares a Node with `app-dev`.
- No `sudo` for jobs: every tool a job needs must come from the recipe profile, and common setup actions that install packages fail. Trusted code makes the extra boundary worth less than the friction.
- Operator-supplied registration tokens: each add and repair needs a fresh one-hour token, and removal cannot deregister.
- Repository targets only: no runner pool for an organization. Organization targets only: Orbit's own repository is on a personal account.
- Runners as fields of the Node setting, like task VMs: per-runner status, errors, and removal have no home.
- Adopt the existing runners: adoption code must read and trust hand-made state, for a one-time move.

## Consequences

- Runners become visible to Doctor, the API, and agents, and removal deregisters them.
- The GitHub App gains `Administration: write`, which also covers repository settings. Orbit requests it only in the runner registration token.
- Jobs have passwordless `sudo`, so setup actions work as on GitHub-hosted runners.
- A host-mode job can become root, bypass the egress rule, and act as the Node toward the Gateway. Host-mode runners must accept only trusted code.
- Persistent runners keep warm caches, and a job can still leave state for the next job.
- The mode-aware guard is a new kind of role conflict that Doctor and the role compatibility table must explain.

## Affects

- Components: apps/gateway, apps/cli, apps/web, apps/docs, apps/e2e
- ADRs: [0211](https://github.com/nckrtl/orbit/pull/1251), in review, for the convergence programs
- Detail: [CI runner role](/reference/ci-runner-role), [Role compatibility](/reference/node-provisioning#role-compatibility), [GitHub App](/reference/github-app#what-the-app-is)
- Verify: Gateway feature tests for the role guard, records, token scopes, and removal; CLI command surface test; an Incus E2E that adds the role in both modes, creates a runner against a test repository, and destroys it
