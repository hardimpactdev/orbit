---
title: "ADR 0227: Run CI on GitHub-hosted runners and two dedicated CI hosts"
sidebarTitle: "0227 CI runner policy"
description: "In progress. Public repositories, Orbit included, run CI on GitHub-hosted runners in an image prebuilt from the machine recipe, with our runners as fallback. Private repositories run CI only on our runners. Sabre and Shark become dedicated CI hosts, and Nick registers the runners by hand. This supersedes ADR 0213."
---

# ADR 0227: Run CI on GitHub-hosted runners and two dedicated CI hosts

Public repositories run CI on GitHub-hosted runners, and private repositories run it on our own runners. Sabre and Shark run only CI, and Beast runs feature development. Nick registers our runners by hand, so Orbit gets no new GitHub credential or write permission. This supersedes [ADR 0213](/decisions/0213-run-ci-runners-through-a-ci-runner-role).

## Status

In progress.

Nick decided this on 2026-10-20.

Principle: [Security fits the real threat model](/mission#principles). Code from a public pull request runs on GitHub's machines, no runner job has sudo, and no Orbit credential gains a write right. It also serves [Lean](/mission#principles): no runner App, role, or Gateway records, and CI capacity grows only when a measurement asks for it. Exception to [Agents operate, humans steer](/mission#principles): runner registration is a manual GitHub step that Nick takes, not an Orbit command, because every automated path needs a GitHub write permission.

## Context

GitHub lists five runners for `nckrtl/orbit`. All of them are persistent.

| Runners | Host | How they run | Labels |
| --- | --- | --- | --- |
| `sabre-1`, `sabre-2` | Sabre: 12 threads, 30 GiB | Two host services as the user `github-runner` | `self-hosted`, `Linux`, `X64`, `sabre`, `orbit-shard` |
| `ci-beast-1`, `ci-beast-2` | Beast: 16 threads, 128 GiB | Unprivileged Incus containers that `tools/ci-runners/setup.sh` builds | `self-hosted`, `Linux`, `X64`, `orbit-shard` |
| `ci-shark-1` | Shark: 12 threads, 64 GiB | One container of the same kind | `self-hosted`, `Linux`, `X64`, `orbit-shard` |

Shark also holds `ci-hauzer-1`, a runner registered for `hardimpactdev/hauzer` in its own container. The [implementation loop](/reference/implementation-loop#self-hosted-gateway-runner) describes the Orbit runners.

- On Sabre, `github-runner` has `NOPASSWD:ALL` sudo from `/etc/sudoers.d/github-runner`, a file made by hand on 2026-10-11. The `github-runner-egress` nftables rule matches that user's UID, so a job that runs `sudo` escapes it.
- [PR #1403](https://github.com/nckrtl/orbit/pull/1403) makes ordinary Gateway tests run without sudo. Only the `privileged` group needs root.
- Beast and Shark share their cores between runner containers, task VMs, and development Instances. Shark holds the Instances `pantry-sync` of Hauzer (388) and Mealou (389).
- In reference run [39315174974](https://github.com/nckrtl/orbit/actions/runs/39315174974), hosted jobs spent 420 s installing PHP, summed. A self-hosted job spends about 1 s, because PHP is already on the machine.
- In the CI runner audit of 2026-10-19, the median job queue was 2 s, and Sabre and Shark were almost idle.
- ADR 0213 planned a `ci-runner` role, Gateway runner records, and registration through the Orbit GitHub App with `Administration: write`. None of it is built.

## Decision

The policy has four parts: the hosts, where a job runs, our runners, and capacity. The reasons, the migration, and the deletions follow.

### Hosts

- Sabre and Shark are dedicated CI hosts. No feature development runs on them.
- Feature development runs on Beast. UpCloud takes the overflow.

### Where a job runs

| Repository | Runner |
| --- | --- |
| Public, Orbit included | GitHub-hosted runners. Jobs run in a container image prebuilt from Orbit's [machine software recipe](/reference/compute-drivers#role-and-base-tool-sets), so no job reinstalls PHP. Our runners are the fallback. |
| Private | Our runners, always. A private job never spends hosted minutes. |

### Our runners

- Every repository bound to an Orbit Project can use them.
- Nick registers them himself: once per organization at organization level, and per repository for repositories under his personal account.
- The Orbit GitHub App stays at `administration: read`, as the [GitHub App](/reference/github-app#read-branch-protection) page states. No new App, token, or write permission comes for runners.
- Jobs on our runners run without sudo. Privileged tests run on GitHub-hosted runners.

### Capacity

CI capacity grows only when measured queueing calls for it.

### Why

- GitHub gives public repositories unlimited hosted minutes. Their pull request code is untrusted, and on a hosted runner it runs on GitHub's machine, not on a fleet Node.
- A hosted job spends most of its setup installing PHP. An image that already holds the recipe's PHP removes that cost.
- Private repositories would spend paid hosted minutes. Their code is our own, so our runners may run it.
- CI and development compete for the same cores on Beast and Shark. Separate hosts end that.
- A job with sudo is root on its machine, and root escapes every filter on that machine. A filter that one `sudo` escapes is not a boundary.
- Every way for Orbit to register runners needs a GitHub write permission, and the repository-level one also permits deleting the repository. Registering once per organization by hand costs minutes and adds no credential.
- Queues are short today. More runners now would sit idle.

### Migration

1. PR #1403 merges. Then remove Sabre's `/etc/sudoers.d/github-runner`, and check that `sudo -n true` fails as `github-runner`.
2. Move Shark's Instances 388 and 389 to Beast with [`instance:transfer`](/reference/instance-transfer). Remove Shark as a task VM host, as [Add a task VM host](/reference/compute-drivers#add-a-task-vm-host) describes. Then remove Shark's `app-dev` role.
3. Build the container image from the recipe. Move Orbit's Gateway job and shards to GitHub-hosted runners in that image. Our runners stay registered as the fallback.
4. Nick registers runners on Sabre and Shark for each organization and each personal repository that a Project uses. A runner registered for one repository of an organization, such as `ci-hauzer-1`, is registered again at organization level. An organization's runner group must allow public repositories, or its public repositories have no fallback.
5. The workflows of private repositories move to our runners.
6. The parts below are deleted. The pull request of this step rewrites the runner sections of the implementation loop and retires this ADR and ADR 0213.

### What gets deleted

| Part | Where | Step |
| --- | --- | --- |
| The sudoers drop-in `/etc/sudoers.d/github-runner` | Sabre | 1 |
| The task VM host: `settings.task_vms`, the Incus project `orbit-tasks` with its images and topology seeds, its bridge, ACL, and pool, and the `app-dev` role | Shark | 2 |
| The runners `ci-beast-1` and `ci-beast-2` with their registrations, the Incus project `orbit-ci`, the bridge `orbitci0`, the ACL `orbit-ci-egress`, its ufw rule, and `orbit-ci/*` in `task_vms.operator_owned` | Beast | 6 |
| In `tools/ci-runners/setup.sh`: Beast's subnet and container names, and the fixed repository `nckrtl/orbit` with its repository token command in `register` | Repository | 6 |
| The text that treats the UID egress firewall `github-runner-egress` as a boundary | Documentation | 6 |

ADR 0213's role, runner records, `ci-runner:*` commands, and App permission were never built, so no code goes for them.

## Rejected alternatives

- Single-use runner VMs under GARM, with a runner App, as an earlier draft of this ADR proposed: the App needs `Administration: write`, and the plan adds a role and a pre-1.0 service. Nick did not approve it.
- Register runners through the Orbit App, as ADR 0213 planned: the App would need `Administration: write`, which permits deleting a repository.
- A personal access token or a second App for registration: each is a new credential with a write right.
- Hosted runners for private repositories: they spend paid minutes.
- Our runners first for public repositories: untrusted pull request code would run on fleet Nodes, while hosted minutes are free for those repositories.
- Sudo for runner jobs: root escapes every filter on the host.
- More runners now: queues are short and the CI hosts are idle.

## Consequences

- Public pull request code runs on our hosts only on fallback.
- Hosted jobs start with PHP in place.
- A private job waits when our runners are busy. It never moves to a hosted runner.
- Orbit's full Gateway suite on `main` leaves Sabre's 6 Pest processes for a hosted runner, unless it falls back.
- Each new organization or personal repository needs one registration by Nick before its private CI runs. The Gateway and Doctor do not see runners.
- Shark carries no development work. Beast and UpCloud carry all of it.

## Affects

- Components: apps/gateway, apps/docs
- ADRs: supersedes [0213](/decisions/0213-run-ci-runners-through-a-ci-runner-role); keeps the App permission of [0223](/decisions/0223-run-one-gate-from-handoff-to-merge)
- Detail: `.github/workflows/ci.yml`, `.github/actions/php`, and `tools/ci-runners`; [Implementation loop: One PHP build in CI](/reference/implementation-loop#one-php-build-in-ci), [Self-hosted Gateway runner](/reference/implementation-loop#self-hosted-gateway-runner), and [Self-hosted shard runners](/reference/implementation-loop#self-hosted-shard-runners); [GitHub App](/reference/github-app#read-branch-protection)
- Verify: a pull request run of `nckrtl/orbit` passes `Required checks` on hosted runners, and its PHP step installs nothing; `sudo -n true` fails as the job user on every runner; GitHub lists runners only on Sabre and Shark for each organization and personal repository that a Project uses
